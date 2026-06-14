<?php

declare(strict_types=1);

namespace App\Docs;

/**
 * Case-insensitive substring search over the spec corpus, returning matching
 * lines with the nearest preceding section header and a 1-based line number.
 * One engine serves the public MCP spec_search tool and the docs chat. When a
 * SpecIndex is supplied, the specs are scanned in title-weighted relevance
 * order (so the most relevant spec's matches come first) instead of corpus
 * order; the per-line match shape is unchanged either way.
 */
final class SpecSearch
{
    private const SNIPPET_WINDOW = 160;
    public const MAX_LIMIT = 100;
    public const DEFAULT_LIMIT = 20;

    public function __construct(
        private readonly SpecCorpus $corpus,
        private readonly ?SpecIndex $index = null,
    ) {}

    /**
     * @return list<array{spec: string, title: string, section: ?string, line: int, snippet: string}>
     */
    public function search(string $query, int $limit = self::DEFAULT_LIMIT): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $limit = max(1, min($limit, self::MAX_LIMIT));
        $needle = mb_strtolower($query);
        $matches = [];

        foreach ($this->orderedSpecNames($query) as $name) {
            $markdown = $this->corpus->markdown($name);
            if ($markdown === null) {
                continue;
            }
            $title = $this->corpus->title($name) ?? $name;

            $section = null;
            foreach (preg_split('/\R/', $markdown) ?: [] as $i => $line) {
                if (preg_match('/^#{2,3}\s+(.+)$/', $line, $m) === 1) {
                    $section = trim($m[1]);
                }

                $pos = mb_strpos(mb_strtolower($line), $needle);
                if ($pos === false) {
                    continue;
                }

                $matches[] = [
                    'spec' => $name,
                    'title' => $title,
                    'section' => $section,
                    'line' => $i + 1,
                    'snippet' => $this->snippet($line, $pos),
                ];

                if (count($matches) >= $limit) {
                    return $matches;
                }
            }
        }

        return $matches;
    }

    /**
     * Every spec name to scan, ordered so the most relevant specs come first
     * when an index is wired. The index only RE-ORDERS the scan, it never
     * filters it: ranked specs lead, then every remaining spec in corpus order,
     * so a literal substring living in a spec the FTS ranker did not surface
     * (e.g. a token the porter tokenizer split differently) is still found.
     * This keeps the indexed search's match set identical to the corpus-order
     * search, exactly the public spec_search substring contract.
     *
     * @return list<string>
     */
    private function orderedSpecNames(string $query): array
    {
        $all = array_map(static fn(array $spec): string => $spec['name'], $this->corpus->all());
        if ($this->index === null) {
            return $all;
        }

        $ranked = $this->index->rankSpecNames($query, self::MAX_LIMIT);
        if ($ranked === []) {
            return $all;
        }

        $seen = array_fill_keys($ranked, true);
        $rest = array_values(array_filter($all, static fn(string $name): bool => !isset($seen[$name])));

        return array_merge($ranked, $rest);
    }

    private function snippet(string $line, int $position): string
    {
        $line = trim($line);
        if (mb_strlen($line) <= self::SNIPPET_WINDOW) {
            return $line;
        }

        $start = max(0, $position - intdiv(self::SNIPPET_WINDOW, 2));
        $snippet = mb_substr($line, $start, self::SNIPPET_WINDOW);

        return ($start > 0 ? "\u{2026}" : '') . trim($snippet) . "\u{2026}";
    }
}
