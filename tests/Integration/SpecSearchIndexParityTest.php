<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Docs\SpecCorpus;
use App\Docs\SpecIndex;
use App\Docs\SpecSearch;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;

/**
 * The public MCP spec_search tool runs SpecSearch WITH the index in
 * production. The index must only re-order the scan, never drop a literal
 * substring match the corpus-order search would return, or the substring
 * contract regresses on a public endpoint.
 */
final class SpecSearchIndexParityTest extends TestCase
{
    private SpecSearch $plain;
    private SpecSearch $indexed;

    protected function setUp(): void
    {
        $corpus = SpecCorpus::default();
        $db = DBALDatabase::createSqlite(':memory:');
        $index = new SpecIndex($corpus, $db);
        $index->ensure();

        $this->plain = new SpecSearch($corpus);
        $this->indexed = new SpecSearch($corpus, $index);
    }

    #[Test]
    public function indexed_search_returns_the_same_match_set_as_corpus_order_search(): void
    {
        // The two queries the review proved regressed: "EntityRepository"
        // (porter tokenization hides the substring from the FTS ranker) and
        // "set(" (a spec ranked past the per-keyword window). Both have well
        // under MAX_LIMIT total matches, so any difference is a dropped match,
        // not limit truncation (which would reorder a >100-match result set).
        foreach (['EntityRepository', 'set('] as $query) {
            $plainMatches = $this->plain->search($query, SpecSearch::MAX_LIMIT);
            $indexedMatches = $this->indexed->search($query, SpecSearch::MAX_LIMIT);

            $this->assertLessThan(
                SpecSearch::MAX_LIMIT,
                count($plainMatches),
                'Test query is too common to distinguish dropping from truncation: ' . $query,
            );

            $plain = $this->keyset($plainMatches);
            $indexed = $this->keyset($indexedMatches);
            sort($plain);
            sort($indexed);

            $this->assertSame($plain, $indexed, 'Indexed search must not drop matches for: ' . $query);
        }
    }

    /**
     * @param list<array{spec: string, title: string, section: ?string, line: int, snippet: string}> $matches
     * @return list<string>
     */
    private function keyset(array $matches): array
    {
        return array_map(static fn(array $m): string => $m['spec'] . ':' . $m['line'], $matches);
    }
}
