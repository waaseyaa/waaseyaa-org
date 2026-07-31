<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Content\ContentReader;
use App\Content\ContentSync;
use App\Tests\Support\ContentEntityHarness;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\EntityTypeManager;

final class ContentReaderTest extends TestCase
{
    private EntityTypeManager $manager;
    private string $root;

    protected function setUp(): void
    {
        $this->manager = ContentEntityHarness::entityTypeManager();
        $this->root = sys_get_temp_dir() . '/content-reader-' . bin2hex(random_bytes(6));
        foreach (['releases', 'roadmap', 'case-studies'] as $dir) {
            mkdir($this->root . '/' . $dir, recursive: true);
        }
    }

    private function release(string $version, string $date): void
    {
        file_put_contents(
            $this->root . '/releases/' . $version . '.md',
            "---\ntitle: {$version}\nversion: {$version}\nreleased_at: \"{$date}\"\nsummary: s\n---\nb\n",
        );
    }

    #[Test]
    public function releases_are_published_only_newest_first(): void
    {
        $this->release('v0.1.0-alpha.900', '2026-01-01');
        $this->release('v0.1.0-alpha.901', '2026-02-01');
        new ContentSync($this->manager, $this->root)->sync();
        unlink($this->root . '/releases/v0.1.0-alpha.900.md');
        new ContentSync($this->manager, $this->root)->sync(); // unpublishes .900

        $reader = new ContentReader($this->manager);
        $versions = array_map(fn ($e) => $e->get('version'), $reader->releases());
        self::assertSame(['v0.1.0-alpha.901'], $versions);
        self::assertNull($reader->release('v0.1.0-alpha.900'), 'unpublished must not resolve');
        self::assertNotNull($reader->release('v0.1.0-alpha.901'));
    }

    #[Test]
    public function roadmap_groups_by_horizon(): void
    {
        file_put_contents($this->root . '/roadmap/a.md', "---\ntitle: A\nhorizon: now\nstatus_note: open\nweight: 1\n---\n");
        file_put_contents($this->root . '/roadmap/b.md', "---\ntitle: B\nhorizon: now\nstatus_note: open\nweight: 0\n---\n");
        file_put_contents($this->root . '/roadmap/c.md', "---\ntitle: C\nhorizon: later\nstatus_note: open\n---\n");
        new ContentSync($this->manager, $this->root)->sync();

        $grouped = new ContentReader($this->manager)->roadmap();
        self::assertSame(['B', 'A'], array_map(fn ($e) => $e->get('title'), $grouped['now']));
        self::assertSame(['C'], array_map(fn ($e) => $e->get('title'), $grouped['later']));
        self::assertSame([], $grouped['next']);
    }

    #[Test]
    public function null_manager_yields_empty_never_throws(): void
    {
        $reader = new ContentReader(null);
        self::assertSame([], $reader->releases());
        self::assertSame(['now' => [], 'next' => [], 'later' => []], $reader->roadmap());
        self::assertSame([], $reader->caseStudies());
        self::assertNull($reader->release('v1'));
        self::assertNull($reader->caseStudy('x'));
        self::assertNull($reader->revisionCount('release', '1'));
    }
}
