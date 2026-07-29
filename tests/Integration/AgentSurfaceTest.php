<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controller\LlmsTxtController;
use App\Docs\SpecCorpus;
use App\Provider\DocsServiceProvider;
use App\Support\SiteUrl;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Routing\WaaseyaaRouter;
use Waaseyaa\SSR\SsrServiceProvider;

/**
 * The agent-readable surface, tested at the shapes production actually
 * serves. The route-precedence tests register the framework's SSR/SEO
 * routes first and the app's routes second, exactly like a production
 * boot, so a framework route silently shadowing an app surface (the
 * /llms.txt regression) fails here instead of in production.
 */
final class AgentSurfaceTest extends TestCase
{
    private const MIN_SPEC_LINKS = 80;

    private static SpecCorpus $corpus;
    private static string $llms;

    public static function setUpBeforeClass(): void
    {
        self::$corpus = SpecCorpus::default();
        $response = new LlmsTxtController(self::$corpus, new SiteUrl('https://waaseyaa.org'))->serve();
        self::$llms = (string) $response->getContent();
    }

    private function productionShapedRouter(): WaaseyaaRouter
    {
        $router = new WaaseyaaRouter();

        // Framework providers register first, app providers second, the
        // same order the kernel uses. The SSR provider is the one that
        // contributes the competing seo.* public routes.
        new SsrServiceProvider()->routes($router, new EntityTypeManager(new EventDispatcher()));
        new DocsServiceProvider()->routes($router);

        return $router;
    }

    #[Test]
    public function app_llms_txt_wins_route_precedence_over_the_framework_seo_route(): void
    {
        $match = $this->productionShapedRouter()->match('/llms.txt');

        $this->assertSame('llms.txt', $match['_route'] ?? null);
    }

    #[Test]
    public function app_sitemap_wins_route_precedence_over_the_framework_seo_route(): void
    {
        $match = $this->productionShapedRouter()->match('/sitemap.xml');

        $this->assertSame('sitemap.xml', $match['_route'] ?? null);
    }

    #[Test]
    public function llms_txt_carries_the_current_framework_version(): void
    {
        $version = (string) self::$corpus->frameworkVersion();

        $this->assertNotSame('', $version);
        $this->assertStringContainsString('Framework version: ' . $version, self::$llms);
    }

    #[Test]
    public function llms_txt_links_at_least_eighty_specs_so_a_placeholder_cannot_pass(): void
    {
        $links = preg_match_all(
            '#\]\(https://waaseyaa\.org/docs/specs/[a-z0-9._-]+\.md\)#i',
            self::$llms,
        );

        $this->assertGreaterThanOrEqual(self::MIN_SPEC_LINKS, $links);
    }

    #[Test]
    public function llms_txt_advertises_the_mcp_surface(): void
    {
        $this->assertStringContainsString('/.well-known/mcp.json', self::$llms);
        $this->assertStringContainsString('/mcp', self::$llms);
    }
}
