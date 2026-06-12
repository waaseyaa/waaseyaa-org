<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Site-wide content rules: no em dashes anywhere in authored content,
 * and no banned marketing phrases. These rules apply to every template
 * and stylesheet the site ships.
 */
final class ContentHonestyTest extends TestCase
{
    private const EM_DASH = "\u{2014}";

    /** @var list<string> */
    private const BANNED_PHRASES = [
        'cutting edge',
        'cutting-edge',
    ];

    #[Test]
    public function no_em_dashes_in_authored_content(): void
    {
        foreach ($this->authoredFiles() as $file) {
            $content = (string) file_get_contents($file);

            $this->assertStringNotContainsString(
                self::EM_DASH,
                $content,
                sprintf('Em dash found in %s', $file),
            );
        }
    }

    #[Test]
    public function no_banned_marketing_phrases(): void
    {
        foreach ($this->authoredFiles() as $file) {
            $content = strtolower((string) file_get_contents($file));

            foreach (self::BANNED_PHRASES as $phrase) {
                $this->assertStringNotContainsString(
                    $phrase,
                    $content,
                    sprintf('Banned phrase "%s" found in %s', $phrase, $file),
                );
            }
        }
    }

    /**
     * @return list<string>
     */
    private function authoredFiles(): array
    {
        $root = dirname(__DIR__, 2);

        $files = array_merge(
            glob($root . '/templates/*.twig') ?: [],
            glob($root . '/templates/**/*.twig') ?: [],
            glob($root . '/public/css/*.css') ?: [],
            glob($root . '/src/**/*.php') ?: [],
        );

        $this->assertNotEmpty($files, 'Authored content files must be discoverable');

        return $files;
    }
}
