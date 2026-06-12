<?php

declare(strict_types=1);

namespace App\Chat;

/**
 * One grounding passage retrieved from the spec corpus: a section-level
 * excerpt with its citation (spec name + canonical URL).
 */
final readonly class Passage
{
    public function __construct(
        public string $spec,
        public string $specTitle,
        public ?string $section,
        public string $excerpt,
        public string $url,
    ) {}

    public function citationTitle(): string
    {
        return $this->section !== null && $this->section !== ''
            ? sprintf('%s.md (%s)', $this->spec, $this->section)
            : $this->spec . '.md';
    }
}
