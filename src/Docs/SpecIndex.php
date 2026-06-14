<?php

declare(strict_types=1);

namespace App\Docs;

use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Search\Document\MarkdownDirectorySource;
use Waaseyaa\Search\Fts5\Fts5SearchIndexer;
use Waaseyaa\Search\Fts5\Fts5SearchProvider;
use Waaseyaa\Search\SearchRequest;

/**
 * Relevance ranking of the spec corpus, on the framework's own FTS5 search
 * engine (waaseyaa/search) with the spec title weighted above the body. This
 * is what the substring + keyword-count ranker could not do: a question about
 * adding an entity type now surfaces the spec titled "Entity system" instead
 * of an access-control spec that merely repeats the phrase in its body.
 *
 * The corpus is files, not entities, so each spec is indexed as a
 * SearchDocument via MarkdownDirectorySource. The index lives in the app's
 * persistent SQLite file alongside the chat tables and is (re)built lazily
 * whenever the synced framework version changes, so a fresh deploy reindexes
 * once and steady-state requests only read a version marker.
 *
 * Natural-language questions are ranked per keyword and aggregated (the FTS5
 * provider ANDs the terms within one query, which would return nothing for a
 * full sentence), so recall matches the old per-keyword retriever while the
 * scoring gains title weighting. A query that matches no keyword returns no
 * specs, which keeps the chat's honest "not covered" path intact.
 */
final class SpecIndex
{
    public const TITLE_WEIGHT = 10.0;
    public const BODY_WEIGHT = 1.0;

    private const ID_PREFIX = 'spec:';
    private const PER_KEYWORD_HITS = 50;
    // Reciprocal-rank-fusion constant (standard 60): fuses the per-keyword
    // rankings without summing bm25 scores, whose magnitudes are
    // incomparable across keywords (a ubiquitous term like "entity" has
    // near-zero IDF, a rarer term like "add" a large one).
    private const RRF_K = 60;

    private readonly Fts5SearchIndexer $indexer;
    private readonly Fts5SearchProvider $provider;

    public function __construct(
        private readonly SpecCorpus $corpus,
        private readonly DatabaseInterface $database,
    ) {
        $this->indexer = new Fts5SearchIndexer($database);
        $this->provider = new Fts5SearchProvider(
            $database,
            $this->indexer,
            null,
            self::TITLE_WEIGHT,
            self::BODY_WEIGHT,
        );
    }

    /**
     * Idempotent: create the schema and (re)build the index only when the
     * synced corpus version differs from what is indexed.
     */
    public function ensure(): void
    {
        // Wait (don't abort) if another php-fpm worker is mid-rebuild after a
        // fresh deploy; the rebuild is sub-second so this never blocks long.
        $this->setBusyTimeout();

        $this->indexer->ensureSchema();
        $this->ensureStateTable();

        $version = (string) ($this->corpus->frameworkVersion() ?? '');
        if ($version !== '' && $this->indexedVersion() === $version) {
            return;
        }

        $this->rebuild($version);
    }

    /**
     * Specs ranked best first. Title matches are weighted above the body: the
     * primary key is how many distinct question keywords appear in the spec
     * title (so the spec whose title is the question's topic wins, and specs
     * that only repeat the phrase in body fall behind), and the tiebreak is
     * reciprocal-rank fusion of the per-keyword, title-weighted FTS rankings.
     *
     * @return list<array{name: string, title: string, score: float}>
     */
    public function rankSpecs(string $query, int $limit): array
    {
        $keywords = Keywords::extract($query);
        if ($keywords === []) {
            return [];
        }

        // Body relevance: fuse the per-keyword rankings (reciprocal rank).
        $rrf = [];
        foreach ($keywords as $keyword) {
            $result = $this->provider->search(new SearchRequest($keyword, pageSize: self::PER_KEYWORD_HITS));
            $position = 0;
            foreach ($result->hits as $hit) {
                $name = $this->specName($hit->id);
                if ($name === null) {
                    continue;
                }
                $rrf[$name] = ($rrf[$name] ?? 0.0) + 1.0 / (self::RRF_K + $position);
                ++$position;
            }
        }

        // Title signal: how many distinct keywords hit the (canonical) spec
        // title, and how specific that title is (matched tokens / title length),
        // so a concise on-topic title ("Entity System") outranks a long
        // tangential one ("Entity Storage - Two-Axis ...") that merely shares a
        // word. Order: title-hit count, then title specificity, then body RRF.
        $candidates = [];
        foreach (array_keys($rrf) as $name) {
            $title = $this->corpus->title($name);
            if ($title === null) {
                continue;
            }
            [$hits, $specificity] = $this->titleSignal($title, $keywords);
            $candidates[] = [
                'name' => $name,
                'title' => $title,
                'hits' => $hits,
                'specificity' => $specificity,
                'rrf' => $rrf[$name],
            ];
        }

        usort($candidates, static fn(array $a, array $b): int => [$b['hits'], $b['specificity'], $b['rrf']] <=> [$a['hits'], $a['specificity'], $a['rrf']]);

        $out = [];
        foreach (array_slice($candidates, 0, $limit) as $candidate) {
            $out[] = ['name' => $candidate['name'], 'title' => $candidate['title'], 'score' => $candidate['rrf']];
        }

        return $out;
    }

    /**
     * @param list<string> $keywords
     * @return array{0: int, 1: float} distinct keyword hits in the title, and
     *         the fraction of title tokens those keywords cover
     */
    private function titleSignal(string $title, array $keywords): array
    {
        $titleLower = mb_strtolower($title);
        $tokens = preg_split('/[^a-z0-9]+/', $titleLower, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $hits = 0;
        $matchedTokens = 0;
        foreach ($keywords as $keyword) {
            if (mb_strpos($titleLower, $keyword) !== false) {
                ++$hits;
            }
        }
        foreach ($tokens as $token) {
            foreach ($keywords as $keyword) {
                if (str_contains($token, $keyword)) {
                    ++$matchedTokens;
                    break;
                }
            }
        }

        $specificity = $tokens === [] ? 0.0 : $matchedTokens / count($tokens);

        return [$hits, $specificity];
    }

    /**
     * @return list<string>
     */
    public function rankSpecNames(string $query, int $limit): array
    {
        return array_map(static fn(array $spec): string => $spec['name'], $this->rankSpecs($query, $limit));
    }

    /**
     * Rebuild atomically. The whole wipe + reindex + version stamp runs in one
     * transaction, so concurrent readers see either the previous index or the
     * complete new one, never a half-built table, and a rebuild that fails
     * partway rolls back cleanly instead of stamping the version over a partial
     * index. The indexer's own per-document transactions nest inside this one
     * (DBAL nests by counter), so they add no intermediate commits.
     */
    private function rebuild(string $version): void
    {
        $transaction = $this->database->transaction();
        try {
            $this->indexer->removeAll();

            $source = new MarkdownDirectorySource($this->corpus->dir(), rtrim(self::ID_PREFIX, ':'));
            foreach ($source->documents() as $document) {
                $this->indexer->index($document);
            }

            $this->writeIndexedVersion($version);
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
    }

    private function setBusyTimeout(): void
    {
        // PRAGMA routes through DatabaseInterface::query as a lazy generator;
        // consume it so the statement actually runs on the connection.
        iterator_to_array($this->database->query('PRAGMA busy_timeout = 5000'));
    }

    private function specName(string $id): ?string
    {
        if (!str_starts_with($id, self::ID_PREFIX)) {
            return null;
        }

        return substr($id, strlen(self::ID_PREFIX));
    }

    private function ensureStateTable(): void
    {
        $this->database->query(
            'CREATE TABLE IF NOT EXISTS spec_index_state (id INTEGER PRIMARY KEY CHECK (id = 1), framework_version TEXT NOT NULL)',
        );
    }

    private function indexedVersion(): ?string
    {
        foreach ($this->database->query('SELECT framework_version FROM spec_index_state WHERE id = 1') as $row) {
            return (string) $row['framework_version'];
        }

        return null;
    }

    private function writeIndexedVersion(string $version): void
    {
        $this->database->query(
            'INSERT INTO spec_index_state (id, framework_version) VALUES (1, ?) '
            . 'ON CONFLICT(id) DO UPDATE SET framework_version = excluded.framework_version',
            [$version],
        );
    }
}
