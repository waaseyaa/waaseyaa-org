<?php

declare(strict_types=1);

namespace App\Docs;

/**
 * Query tokenizer shared by the FTS spec index and the chat retriever: lowers
 * the question, splits on non-word characters, and drops stopwords and very
 * short tokens. One tokenizer keeps the keywords the index ranks on identical
 * to the keywords the retriever highlights sections with.
 */
final class Keywords
{
    public const MAX = 8;

    private const STOPWORDS = [
        'the', 'and', 'for', 'with', 'that', 'this', 'what', 'when', 'where',
        'how', 'why', 'who', 'does', 'can', 'are', 'was', 'will', 'should',
        'would', 'could', 'into', 'from', 'have', 'has', 'had', 'about',
        'use', 'used', 'using', 'you', 'your', 'they', 'them', 'there',
        'waaseyaa', 'framework', 'spec', 'specs', 'work', 'works', 'mean',
    ];

    /**
     * @return list<string>
     */
    public static function extract(string $text, int $max = self::MAX): array
    {
        $words = preg_split('/[^a-z0-9_:\\\\-]+/i', mb_strtolower($text)) ?: [];

        $keywords = [];
        foreach ($words as $word) {
            $word = trim($word, '-_:');
            if (mb_strlen($word) < 3 || in_array($word, self::STOPWORDS, true)) {
                continue;
            }
            $keywords[$word] = true;
        }

        return array_slice(array_keys($keywords), 0, $max);
    }
}
