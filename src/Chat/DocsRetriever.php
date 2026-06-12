<?php

declare(strict_types=1);

namespace App\Chat;

use App\Docs\SpecCorpus;
use App\Docs\SpecSearch;
use App\Support\SiteUrl;

/**
 * Keyword retrieval over the spec corpus for the docs chat. The same
 * search engine the public MCP spec_search tool uses, lifted from line
 * hits to section-level passages: what the assistant reads is what an
 * agent can fetch itself.
 */
final class DocsRetriever
{
    private const MAX_KEYWORDS = 8;
    private const HITS_PER_KEYWORD = 40;
    private const EXCERPT_CHARS = 1100;

    private const STOPWORDS = [
        'the', 'and', 'for', 'with', 'that', 'this', 'what', 'when', 'where',
        'how', 'why', 'who', 'does', 'can', 'are', 'was', 'will', 'should',
        'would', 'could', 'into', 'from', 'have', 'has', 'had', 'about',
        'use', 'used', 'using', 'you', 'your', 'they', 'them', 'there',
        'waaseyaa', 'framework', 'spec', 'specs', 'work', 'works', 'mean',
    ];

    public function __construct(
        private readonly SpecCorpus $corpus,
        private readonly SpecSearch $search,
        private readonly SiteUrl $urls,
    ) {}

    /**
     * @return list<Passage>
     */
    public function retrieve(string $question, int $topK = 6): array
    {
        $keywords = $this->keywords($question);
        if ($keywords === []) {
            return [];
        }

        // Score sections by how many distinct keywords land in them.
        $sections = [];
        foreach ($keywords as $keyword) {
            foreach ($this->search->search($keyword, self::HITS_PER_KEYWORD) as $hit) {
                $key = $hit['spec'] . '#' . ($hit['section'] ?? '');
                $sections[$key] ??= [
                    'spec' => $hit['spec'],
                    'title' => $hit['title'],
                    'section' => $hit['section'],
                    'keywords' => [],
                    'hits' => 0,
                ];
                $sections[$key]['keywords'][$keyword] = true;
                $sections[$key]['hits']++;
            }
        }

        uasort($sections, static function (array $a, array $b): int {
            return [count($b['keywords']), $b['hits']] <=> [count($a['keywords']), $a['hits']];
        });

        $passages = [];
        foreach ($sections as $section) {
            $excerpt = $this->sectionExcerpt($section['spec'], $section['section']);
            if ($excerpt === null) {
                continue;
            }

            $passages[] = new Passage(
                spec: $section['spec'],
                specTitle: $section['title'],
                section: $section['section'],
                excerpt: $excerpt,
                url: $this->urls->spec($section['spec']),
            );

            if (count($passages) >= $topK) {
                break;
            }
        }

        return $passages;
    }

    /**
     * @return list<string>
     */
    public function keywords(string $question): array
    {
        $words = preg_split('/[^a-z0-9_:\\\\-]+/i', mb_strtolower($question)) ?: [];

        $keywords = [];
        foreach ($words as $word) {
            $word = trim($word, '-_:');
            if (mb_strlen($word) < 3 || in_array($word, self::STOPWORDS, true)) {
                continue;
            }
            $keywords[$word] = true;
        }

        return array_slice(array_keys($keywords), 0, self::MAX_KEYWORDS);
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
