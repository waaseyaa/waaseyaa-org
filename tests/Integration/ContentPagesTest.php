<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Content\ContentReader;
use App\Content\ContentSync;
use App\Controller\ReleasesController;
use App\Controller\RoadmapController;
use App\Docs\SpecCorpus;
use App\Support\SiteUrl;
use App\Tests\Support\ContentEntityHarness;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\SSR\SsrServiceProvider;

final class ContentPagesTest extends TestCase
{
    private EntityTypeManager $manager;

    public static function setUpBeforeClass(): void
    {
        $provider = new SsrServiceProvider();
        $provider->setKernelContext(dirname(__DIR__, 2), [], []);
        $provider->boot();
    }

    protected function setUp(): void
    {
        $this->manager = ContentEntityHarness::entityTypeManager();
        // Sync the REAL repo corpus so tests cover the shipped content.
        new ContentSync($this->manager, dirname(__DIR__, 2) . '/content')->sync();
    }

    private function releases(): ReleasesController
    {
        return new ReleasesController(
            new ContentReader($this->manager),
            SpecCorpus::default(),
            new SiteUrl('https://waaseyaa.org'),
        );
    }

    #[Test]
    public function releases_index_renders_the_locked_version(): void
    {
        $response = $this->releases()->index(Request::create('/releases'));
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('v0.1.0-alpha.276', (string) $response->getContent());
    }

    #[Test]
    public function releases_index_negotiates_markdown(): void
    {
        $response = $this->releases()->index(Request::create('/releases', server: ['HTTP_ACCEPT' => 'text/markdown']));
        self::assertStringStartsWith('text/markdown', (string) $response->headers->get('Content-Type'));
        self::assertStringContainsString('v0.1.0-alpha.276', (string) $response->getContent());
    }

    #[Test]
    public function release_page_serves_html_markdown_and_404(): void
    {
        $html = $this->releases()->show(Request::create('/releases/v0.1.0-alpha.276'), 'v0.1.0-alpha.276');
        self::assertSame(200, $html->getStatusCode());
        self::assertStringContainsString('alpha.276', (string) $html->getContent());

        $md = $this->releases()->show(Request::create('/releases/v0.1.0-alpha.276.md'), 'v0.1.0-alpha.276.md');
        self::assertStringStartsWith('text/markdown', (string) $md->headers->get('Content-Type'));

        $missing = $this->releases()->show(Request::create('/releases/v9.9.9'), 'v9.9.9');
        self::assertSame(404, $missing->getStatusCode());
    }

    #[Test]
    public function roadmap_renders_grouped_horizons(): void
    {
        $controller = new RoadmapController(
            new ContentReader($this->manager),
            SpecCorpus::default(),
            new SiteUrl('https://waaseyaa.org'),
        );
        $html = (string) $controller->page(Request::create('/roadmap'))->getContent();

        self::assertStringContainsString('Now', $html);
        self::assertStringContainsString('Curated guides tier on waaseyaa.org', $html);
        self::assertStringContainsString('stage-based', $html);

        $md = $controller->page(Request::create('/roadmap', server: ['HTTP_ACCEPT' => 'text/markdown']));
        self::assertStringStartsWith('text/markdown', (string) $md->headers->get('Content-Type'));
    }

    #[Test]
    public function empty_database_renders_an_honest_empty_state(): void
    {
        $empty = new ReleasesController(
            new ContentReader(ContentEntityHarness::entityTypeManager()),
            SpecCorpus::default(),
            new SiteUrl('https://waaseyaa.org'),
        );
        $response = $empty->index(Request::create('/releases'));
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('No releases have been synced yet', (string) $response->getContent());
    }
}
