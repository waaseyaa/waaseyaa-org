<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Content\ContentSync;
use App\Content\ContentSyncException;
use App\Tests\Support\ContentEntityHarness;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\EntityValues;

final class ContentSyncTest extends TestCase
{
    private EntityTypeManager $manager;
    private string $root;

    protected function setUp(): void
    {
        $this->manager = ContentEntityHarness::entityTypeManager();
        $this->root = sys_get_temp_dir() . '/content-sync-' . bin2hex(random_bytes(6));
        foreach (['releases', 'roadmap', 'case-studies'] as $dir) {
            mkdir($this->root . '/' . $dir, recursive: true);
        }
    }

    private function writeRelease(string $version, string $summary = 'A release.'): void
    {
        file_put_contents(
            $this->root . '/releases/' . $version . '.md',
            "---\ntitle: Framework {$version}\nversion: {$version}\nreleased_at: \"2026-07-27\"\nsummary: {$summary}\n---\n\nHighlights here.\n",
        );
    }

    private function sync(): \App\Content\ContentSyncReport
    {
        return new ContentSync($this->manager, $this->root)->sync();
    }

    #[Test]
    public function first_sync_creates_entities(): void
    {
        $this->writeRelease('v0.1.0-alpha.900');
        $report = $this->sync();

        self::assertSame(1, $report->created);
        $all = $this->manager->getRepository('release')->findBy([]);
        self::assertCount(1, $all);
        self::assertSame('v0.1.0-alpha.900', $all[0]->get('slug'));
        self::assertSame(1, EntityValues::statusToInt($all[0]->get('status')));
        self::assertNotSame('', (string) $all[0]->get('source_sha1'));
    }

    #[Test]
    public function second_sync_is_a_noop(): void
    {
        $this->writeRelease('v0.1.0-alpha.900');
        $this->sync();
        $report = $this->sync();

        self::assertSame(0, $report->created + $report->updated + $report->unpublished);
        self::assertSame(1, $report->unchanged);

        $repository = $this->manager->getRepository('release');
        $entity = $repository->findBy([])[0];
        self::assertCount(1, $repository->listRevisions((string) $entity->id()), 'no-op sync must not add revisions');
    }

    #[Test]
    public function changed_file_saves_a_new_revision(): void
    {
        $this->writeRelease('v0.1.0-alpha.900', 'Original summary.');
        $this->sync();
        $this->writeRelease('v0.1.0-alpha.900', 'Amended summary.');
        $report = $this->sync();

        self::assertSame(1, $report->updated);

        $repository = $this->manager->getRepository('release');
        $entity = $repository->findBy([])[0];
        self::assertSame('Amended summary.', $entity->get('summary'));
        self::assertGreaterThanOrEqual(2, count($repository->listRevisions((string) $entity->id())));
    }

    #[Test]
    public function removed_file_unpublishes_but_keeps_history(): void
    {
        $this->writeRelease('v0.1.0-alpha.900');
        $this->sync();
        unlink($this->root . '/releases/v0.1.0-alpha.900.md');
        $report = $this->sync();

        self::assertSame(1, $report->unpublished);
        $entity = $this->manager->getRepository('release')->findBy([])[0];
        self::assertSame(0, EntityValues::statusToInt($entity->get('status')));
    }

    #[Test]
    public function malformed_front_matter_fails_loudly_with_the_file_path(): void
    {
        file_put_contents($this->root . '/releases/bad.md', "no front matter\n");

        $this->expectException(ContentSyncException::class);
        $this->expectExceptionMessageMatches('/bad\.md/');
        $this->sync();
    }

    #[Test]
    public function missing_required_key_fails_loudly(): void
    {
        file_put_contents($this->root . '/releases/v1.md', "---\ntitle: X\n---\nbody\n");

        $this->expectException(ContentSyncException::class);
        $this->expectExceptionMessageMatches('/version/');
        $this->sync();
    }

    #[Test]
    public function unknown_key_fails_loudly(): void
    {
        file_put_contents(
            $this->root . '/releases/v1.md',
            "---\ntitle: X\nversion: v1\nreleased_at: \"2026-01-01\"\nsummary: s\nsurprise: y\n---\n",
        );

        $this->expectException(ContentSyncException::class);
        $this->expectExceptionMessageMatches('/surprise/');
        $this->sync();
    }

    #[Test]
    public function invalid_horizon_fails_loudly(): void
    {
        file_put_contents(
            $this->root . '/roadmap/thing.md',
            "---\ntitle: X\nhorizon: someday\nstatus_note: open\n---\n",
        );

        $this->expectException(ContentSyncException::class);
        $this->expectExceptionMessageMatches('/horizon/');
        $this->sync();
    }
}
