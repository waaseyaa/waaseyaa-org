<?php

declare(strict_types=1);

namespace App\Chat;

/**
 * Prompt assembly for the docs chat. The assistant answers ONLY from the
 * provided spec passages and cites specs by filename; everything else is
 * an honest "not in the corpus".
 */
final class ChatPrompt
{
    public const NO_ANSWER = 'That is not covered by the spec corpus on this site. The docs index at /docs lists every published spec.';

    public function system(): string
    {
        return <<<'PROMPT'
You are the docs assistant on waaseyaa.org, the public site of the Waaseyaa framework (a sovereignty-first, entity-first PHP framework, alpha, in production at First Nations Procurement Inc.).

Rules:
- Answer ONLY from the spec passages provided in the user message. They are excerpts from the framework's own spec corpus.
- Cite the spec file for every claim, inline, like: (entity-system.md). Cite at least one spec in every answer.
- If the passages do not answer the question, reply exactly with: "That is not covered by the spec corpus on this site. The docs index at /docs lists every published spec."
- Be concise and concrete. Prefer code identifiers and file paths from the passages over paraphrase.
- Plain text and simple Markdown only. Never use em dashes. Never invent APIs, versions, or claims not present in the passages.
PROMPT;
    }

    /**
     * @param list<Passage> $passages
     */
    public function userMessage(string $question, array $passages): string
    {
        if ($passages === []) {
            return "No passages were retrieved for this question.\n\nQuestion: " . $question;
        }

        $blocks = [];
        foreach ($passages as $i => $passage) {
            $blocks[] = sprintf(
                "[%d] %s\n%s",
                $i + 1,
                $passage->citationTitle(),
                $passage->excerpt,
            );
        }

        return "Spec passages:\n\n" . implode("\n\n---\n\n", $blocks) . "\n\nQuestion: " . $question;
    }

    /**
     * The site never publishes em dashes, including model output.
     */
    public static function sanitizeDashes(string $text): string
    {
        return str_replace(["\u{2014}", "\u{2013}"], '-', $text);
    }
}
