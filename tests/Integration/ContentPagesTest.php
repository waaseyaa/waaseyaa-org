<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Content\ContentReader;
use App\Content\ContentSync;
use App\Controller\ProductionController;
use App\Controller\ReleasesController;
use App\Controller\RoadmapController;
use App\Docs\SpecCorpus;
use App\Provider\ContentServiceProvider;
use App\Support\PiTelemetry;
use App\Support\SiteUrl;
use App\Tests\Support\ContentEntityHarness;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Routing\WaaseyaaRouter;
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

    private function production(?PiTelemetry $telemetry = null): ProductionController
    {
        return new ProductionController(
            new ContentReader($this->manager),
            $telemetry ?? new PiTelemetry(null),
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
    public function content_routes_are_registered(): void
    {
        $router = new WaaseyaaRouter();
        new ContentServiceProvider()->routes($router);

        self::assertSame('releases.index', $router->match('/releases')['_route'] ?? null);
        self::assertSame('releases.show', $router->match('/releases/v0.1.0-alpha.276')['_route'] ?? null);
        self::assertSame('roadmap', $router->match('/roadmap')['_route'] ?? null);
        self::assertSame('production.index', $router->match('/production')['_route'] ?? null);
        self::assertSame('production.show', $router->match('/production/fnpi')['_route'] ?? null);
    }

    #[Test]
    public function production_index_renders_case_studies_from_the_corpus(): void
    {
        $response = $this->production()->index(Request::create('/production'));
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('First Nations Procurement', (string) $response->getContent());
    }

    #[Test]
    public function production_index_negotiates_markdown(): void
    {
        $response = $this->production()->index(Request::create('/production', server: ['HTTP_ACCEPT' => 'text/markdown']));
        self::assertStringStartsWith('text/markdown', (string) $response->headers->get('Content-Type'));
        self::assertStringContainsString('First Nations Procurement', (string) $response->getContent());
    }

    #[Test]
    public function production_show_serves_html_and_404s_unknown_slugs(): void
    {
        $html = $this->production()->show(Request::create('/production/fnpi'), 'fnpi');
        self::assertSame(200, $html->getStatusCode());
        self::assertStringContainsString('First Nations Procurement', (string) $html->getContent());

        $missing = $this->production()->show(Request::create('/production/nonexistent'), 'nonexistent');
        self::assertSame(404, $missing->getStatusCode());
    }

    #[Test]
    public function production_index_shows_the_pi_block_only_with_fresh_telemetry(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'pi_');
        file_put_contents($file, json_encode(['uptime_days' => 5, 'temp_c' => 48.0, 'generated_at' => time()]));

        try {
            $withTelemetry = $this->production(new PiTelemetry($file))->index(Request::create('/production'));
            self::assertStringContainsString('Live from the Raspberry Pi', (string) $withTelemetry->getContent());
        } finally {
            @unlink($file);
        }

        $withoutTelemetry = $this->production()->index(Request::create('/production'));
        self::assertStringNotContainsString('Live from the Raspberry Pi', (string) $withoutTelemetry->getContent());
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
