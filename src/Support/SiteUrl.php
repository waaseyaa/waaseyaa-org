<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Canonical URL builder. The canonical base is the public site origin
 * (waaseyaa.org in production); locally it falls back to APP_URL so
 * generated links stay clickable during development.
 */
final class SiteUrl
{
    public function __construct(
        private readonly string $base,
    ) {}

    public static function fromEnvironment(): self
    {
        $base = getenv('WAASEYAA_ORG_CANONICAL_URL') ?: getenv('APP_URL') ?: 'https://waaseyaa.org';

        return new self(rtrim((string) $base, '/'));
    }

    public function base(): string
    {
        return $this->base;
    }

    public function to(string $path): string
    {
        return $this->base . '/' . ltrim($path, '/');
    }

    public function spec(string $name): string
    {
        return $this->to('/docs/specs/' . $name);
    }

    public function specMarkdown(string $name): string
    {
        return $this->spec($name) . '.md';
    }
}
