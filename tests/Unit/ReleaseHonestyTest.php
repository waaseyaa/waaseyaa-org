<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Content\FrontMatter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The site must not claim a release it is not running: the newest file
 * in content/releases/ must be the exact framework version locked in
 * the corpus manifest. Adding a newer release note requires bumping the
 * framework first.
 */
final class ReleaseHonestyTest extends TestCase
{
    #[Test]
    public function newest_release_note_matches_the_locked_framework_version(): void
    {
        $root = dirname(__DIR__, 2);
        $manifest = json_decode((string) file_get_contents($root . '/resources/specs/manifest.json'), true);
        $locked = $manifest['framework_version'] ?? null;
        self::assertIsString($locked);

        // Tie-break deterministically on [released_at, version] rather than
        // relying on glob() file order: two release notes dated the same
        // day must not let filesystem order pick which one counts as
        // "newest". Version compares descending as a plain string, which
        // matches how releases are named (alpha.NNN increments as a run
        // of digits) without pulling in a version-comparison library.
        $newest = null;
        $newestKey = ['', ''];
        foreach (glob($root . '/content/releases/*.md') ?: [] as $file) {
            $meta = FrontMatter::parse((string) file_get_contents($file))['meta'];
            $key = [(string) $meta['released_at'], (string) $meta['version']];
            if ($key > $newestKey) {
                $newestKey = $key;
                $newest = (string) $meta['version'];
            }
        }

        self::assertNotNull($newest, 'content/releases/ must contain at least one release note');
        self::assertSame($locked, $newest);
    }
}
