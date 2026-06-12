<?php

declare(strict_types=1);

namespace App\Docs;

/**
 * Case-insensitive substring search over the spec corpus with the
 * nearest preceding section header, mirroring the framework's
 * bimaaji_search_specs semantics. One engine serves the public MCP
 * tools and the docs chat.
 */
final class SpecSearch
{
    private const SNIPPET_WINDOW = 160;
    public const MAX_LIMIT = 100;
    public const DEFAULT_LIMIT = 20;

    public function __construct(
        private readonly SpecCorpus $corpus,
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

        foreach ($this->corpus->all() as $spec) {
            $markdown = $this->corpus->markdown($spec['name']);
            if ($markdown === null) {
                continue;
            }

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
                    'spec' => $spec['name'],
                    'title' => $spec['title'],
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
