<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * bin/sync-specs.php must be a pure function of the locked framework
 * inputs: running it twice against unchanged inputs leaves
 * resources/specs byte-for-byte identical. Wall-clock output (the old
 * synced_at field) made the CI generated-files check permanently
 * dirty; this is the regression fence.
 */
final class SyncSpecsDeterminismTest extends TestCase
{
    #[Test]
    public function consecutive_syncs_are_byte_identical(): void
    {
        $root = dirname(__DIR__, 2);

        $run = static function () use ($root): void {
            exec(sprintf('%s %s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg($root . '/bin/sync-specs.php')), $out, $code);
            self::assertSame(0, $code, implode("\n", $out));
        };

        $snapshot = static function () use ($root): array {
            $state = [];
            foreach (glob($root . '/resources/specs/*') ?: [] as $file) {
                $state[basename($file)] = sha1_file($file);
            }
            ksort($state);

            return $state;
        };

        $run();
        $first = $snapshot();
        $manifestAfterFirst = (string) file_get_contents($root . '/resources/specs/manifest.json');

        $run();
        $second = $snapshot();
        $manifestAfterSecond = (string) file_get_contents($root . '/resources/specs/manifest.json');

        self::assertSame($first, $second, 'a second sync against unchanged inputs must change nothing');
        self::assertSame($manifestAfterFirst, $manifestAfterSecond);
        self::assertStringNotContainsString('synced_at', $manifestAfterSecond, 'wall-clock provenance must not return');
    }

    #[Test]
    public function manifest_timestamp_is_the_locked_package_release_time(): void
    {
        $root = dirname(__DIR__, 2);
        $manifest = json_decode((string) file_get_contents($root . '/resources/specs/manifest.json'), true);

        $expected = null;
        $installed = json_decode((string) file_get_contents($root . '/vendor/composer/installed.json'), true);
        foreach (($installed['packages'] ?? []) as $package) {
            if (($package['name'] ?? '') === 'waaseyaa/framework') {
                $expected = $package['time'] ?? null;
                break;
            }
        }

        self::assertNotNull($expected, 'locked framework package must carry a release time');
        self::assertSame($expected, $manifest['source_released_at']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{40}$/', $manifest['corpus_sha1']);
    }
}
