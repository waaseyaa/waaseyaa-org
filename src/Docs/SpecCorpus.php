<?php

declare(strict_types=1);

namespace App\Docs;

/**
 * The synced spec corpus: one docs source for all three renderings
 * (HTML pages, Markdown negotiation, MCP tools) and for the docs chat.
 *
 * Reads resources/specs/, which bin/sync-specs.php populates from the
 * installed framework dist together with a provenance manifest.
 */
final class SpecCorpus
{
    private const NAME_PATTERN = '/^[a-z0-9][a-z0-9.\-]*$/';

    /** @var array<string, mixed>|null */
    private ?array $manifest = null;

    public function __construct(
        private readonly string $dir,
    ) {}

    public static function default(): self
    {
        return new self(dirname(__DIR__, 2) . '/resources/specs');
    }

    /**
     * The directory the corpus reads from. Used by SpecIndex to scan the
     * synced markdown files into the search index.
     */
    public function dir(): string
    {
        return $this->dir;
    }

    public function frameworkVersion(): ?string
    {
        $version = $this->manifest()['framework_version'] ?? null;

        return is_string($version) ? $version : null;
    }

    /**
     * @return list<array{name: string, title: string}>
     */
    public function all(): array
    {
        $specs = $this->manifest()['specs'] ?? [];
        if (!is_array($specs)) {
            return [];
        }

        $out = [];
        foreach ($specs as $spec) {
            if (is_array($spec) && is_string($spec['name'] ?? null) && is_string($spec['title'] ?? null)) {
                $out[] = ['name' => $spec['name'], 'title' => $spec['title']];
            }
        }

        return $out;
    }

    public function has(string $name): bool
    {
        return $this->isValidName($name) && is_file($this->dir . '/' . $name . '.md');
    }

    public function title(string $name): ?string
    {
        foreach ($this->all() as $spec) {
            if ($spec['name'] === $name) {
                return $spec['title'];
            }
        }

        return null;
    }

    public function markdown(string $name): ?string
    {
        if (!$this->has($name)) {
            return null;
        }

        $content = file_get_contents($this->dir . '/' . $name . '.md');

        return $content === false ? null : $content;
    }

    /**
     * First prose paragraph after the H1, with markdown and review
     * comments stripped. Used for llms.txt and index descriptions.
     */
    public function description(string $name, int $maxLength = 160): ?string
    {
        $markdown = $this->markdown($name);
        if ($markdown === null) {
            return null;
        }

        // Drop HTML review comments and the H1.
        $body = (string) preg_replace('/<!--.*?-->/s', '', $markdown);
        $body = (string) preg_replace('/^#\s+.+$/m', '', $body);

        foreach (preg_split('/\R\R+/', trim($body)) ?: [] as $block) {
            $line = trim((string) preg_replace('/\s+/', ' ', $block));
            // Skip headings, tables, code fences, lists.
            if ($line === '' || preg_match('/^[#>\-*|`\[]/', $line) === 1) {
                continue;
            }
            // Strip inline markdown emphasis and code markers.
            $line = str_replace(['**', '`', '*'], '', $line);
            if (mb_strlen($line) > $maxLength) {
                $line = rtrim(mb_substr($line, 0, $maxLength - 1)) . "\u{2026}";
            }

            return $line;
        }

        return null;
    }

    private function isValidName(string $name): bool
    {
        return preg_match(self::NAME_PATTERN, $name) === 1 && !str_contains($name, '..');
    }

    /**
     * @return array<string, mixed>
     */
    private function manifest(): array
    {
        if ($this->manifest !== null) {
            return $this->manifest;
        }

        $file = $this->dir . '/manifest.json';
        if (!is_file($file)) {
            return $this->manifest = [];
        }

        try {
            $data = json_decode((string) file_get_contents($file), true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->manifest = [];
        }

        return $this->manifest = is_array($data) ? $data : [];
    }
}
