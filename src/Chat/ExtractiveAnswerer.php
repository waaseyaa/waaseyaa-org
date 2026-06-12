<?php

declare(strict_types=1);

namespace App\Chat;

/**
 * Deterministic no-model answer mode: when no model key is configured,
 * the chat still gives grounded value by quoting the most relevant spec
 * passages, each headed by a citation LINK to its canonical docs URL.
 * Used locally and as the mid-stream fallback; the citation invariant
 * holds by construction.
 *
 * Output is shaped for the workspace markdown renderer (workspace-md.js):
 * links, bold, and paragraphs only; no blockquotes or code fences.
 */
final class ExtractiveAnswerer
{
    private const MAX_PASSAGES = 3;
    private const QUOTE_CHARS = 420;

    /**
     * @param list<Passage> $passages
     */
    public function answer(string $question, array $passages): string
    {
        if ($passages === []) {
            return ChatPrompt::NO_ANSWER;
        }

        $parts = ['No model is configured on this instance, so here are the most relevant spec passages, quoted directly:'];

        foreach (array_slice($passages, 0, self::MAX_PASSAGES) as $passage) {
            $parts[] = sprintf(
                "**[%s](%s)**\n\n%s",
                $passage->citationTitle(),
                $passage->url,
                $this->proseSnippet($passage->excerpt),
            );
        }

        return implode("\n\n", $parts);
    }

    /**
     * Prose lines only, sized for a chat bubble: headings, tables, code
     * fences and fence bodies are dropped (the full text is one click away).
     */
    private function proseSnippet(string $excerpt): string
    {
        $lines = [];
        $inFence = false;
        foreach (preg_split('/\R/', $excerpt) ?: [] as $line) {
            $trimmed = trim($line);
            if (str_starts_with($trimmed, '```')) {
                $inFence = !$inFence;
                continue;
            }
            if ($inFence || $trimmed === '' || preg_match('/^[#|>]/', $trimmed) === 1) {
                continue;
            }
            $lines[] = $trimmed;
        }

        $prose = implode(' ', $lines);
        if ($prose === '') {
            $prose = 'See the linked spec for the details (the section is mostly code or tables).';
        }
        if (mb_strlen($prose) > self::QUOTE_CHARS) {
            $prose = rtrim(mb_substr($prose, 0, self::QUOTE_CHARS)) . ' [...]';
        }

        return $prose;
    }
}
