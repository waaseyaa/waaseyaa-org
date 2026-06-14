<?php

declare(strict_types=1);

namespace App\Chat;

use App\Docs\Keywords;
use App\Docs\SpecCorpus;
use App\Docs\SpecIndex;
use App\Support\SiteUrl;

/**
 * Retrieval over the spec corpus for the docs chat. Specs are ranked by the
 * title-weighted FTS5 index (the same engine the public MCP spec_search tool
 * uses), so the question's topic spec surfaces first; within each ranked spec
 * the section that best matches the question's keywords becomes the grounding
 * passage. What the assistant reads is what an agent can fetch itself.
 */
final class DocsRetriever
{
    private const EXCERPT_CHARS = 1100;

    public function __construct(
        private readonly SpecCorpus $corpus,
        private readonly SpecIndex $index,
        private readonly SiteUrl $urls,
    ) {}

    /**
     * @return list<Passage>
     */
    public function retrieve(string $question, int $topK = 6): array
    {
        $specs = $this->index->rankSpecs($question, $topK);
        if ($specs === []) {
            return [];
        }

        $keywords = Keywords::extract($question);

        $passages = [];
        foreach ($specs as $spec) {
            $section = $this->bestSection($spec['name'], $keywords);
            $excerpt = $this->sectionExcerpt($spec['name'], $section);
            if ($excerpt === null) {
                continue;
            }

            $passages[] = new Passage(
                spec: $spec['name'],
                specTitle: $spec['title'],
                section: $section,
                excerpt: $excerpt,
                url: $this->urls->spec($spec['name']),
            );

            if (count($passages) >= $topK) {
                break;
            }
        }

        return $passages;
    }

    /**
     * The section of a spec whose lines mention the most question keywords;
     * null (the spec preamble) when no section matches.
     *
     * @param list<string> $keywords
     */
    private function bestSection(string $spec, array $keywords): ?string
    {
        $markdown = $this->corpus->markdown($spec);
        if ($markdown === null || $keywords === []) {
            return null;
        }

        $markdown = (string) preg_replace('/<!--.*?-->/s', '', $markdown);

        $current = '';
        $counts = [];
        foreach (preg_split('/\R/', $markdown) ?: [] as $line) {
            if (preg_match('/^#{2,3}\s+(.+)$/', $line, $m) === 1) {
                $current = trim($m[1]);
                continue;
            }

            $haystack = mb_strtolower($line);
            foreach ($keywords as $keyword) {
                if (mb_strpos($haystack, $keyword) !== false) {
                    $counts[$current] = ($counts[$current] ?? 0) + 1;
                }
            }
        }

        if ($counts === []) {
            return null;
        }

        arsort($counts);
        $top = (string) array_key_first($counts);

        return $top === '' ? null : $top;
    }

    private function sectionExcerpt(string $spec, ?string $section): ?string
    {
        $markdown = $this->corpus->markdown($spec);
        if ($markdown === null) {
            return null;
        }

        // Drop review comments; they are provenance, not prose.
        $markdown = (string) preg_replace('/<!--.*?-->/s', '', $markdown);
        $lines = preg_split('/\R/', $markdown) ?: [];

        $start = 0;
        if ($section !== null && $section !== '') {
            foreach ($lines as $i => $line) {
                if (preg_match('/^#{2,3}\s+(.+)$/', $line, $m) === 1 && trim($m[1]) === $section) {
                    $start = $i;
                    break;
                }
            }
        }

        $body = [];
        foreach (array_slice($lines, $start) as $i => $line) {
            if ($i > 0 && preg_match('/^#{1,3}\s+/', $line) === 1) {
                break;
            }
            $body[] = $line;
        }

        $excerpt = trim(implode("\n", $body));
        if ($excerpt === '') {
            return null;
        }
        if (mb_strlen($excerpt) > self::EXCERPT_CHARS) {
            $excerpt = rtrim(mb_substr($excerpt, 0, self::EXCERPT_CHARS)) . "\n[truncated]";
        }

        return $excerpt;
    }
}
