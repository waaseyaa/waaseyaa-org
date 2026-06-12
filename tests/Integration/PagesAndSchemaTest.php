<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controller\DocsController;
use App\Controller\SitemapController;
use App\Controller\StaticPageController;
use App\Docs\SpecCorpus;
use App\Provider\AppServiceProvider;
use App\Support\SiteUrl;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Routing\WaaseyaaRouter;
use Waaseyaa\SSR\SsrServiceProvider;

final class PagesAndSchemaTest extends TestCase
{
    private static StaticPageController $pages;
    private static SpecCorpus $corpus;

    public static function setUpBeforeClass(): void
    {
        $provider = new SsrServiceProvider();
        $provider->setKernelContext(dirname(__DIR__, 2), [], []);
        $provider->boot();

        self::$pages = new StaticPageController(new SiteUrl('https://waaseyaa.org'));
        self::$corpus = SpecCorpus::default();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function jsonLdBlocks(string $html): array
    {
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);

        $blocks = [];
        foreach ($m[1] as $raw) {
            $blocks[] = json_decode(trim($raw), true, 32, JSON_THROW_ON_ERROR);
        }

        return $blocks;
    }

    #[Test]
    public function why_and_compare_routes_are_registered(): void
    {
        $router = new WaaseyaaRouter();
        new AppServiceProvider()->routes($router);

        $this->assertSame('why', $router->match('/why')['_route'] ?? null);
        $this->assertSame('compare', $router->match('/compare')['_route'] ?? null);
    }

    #[Test]
    public function why_page_renders_honestly_without_js(): void
    {
        $html = (string) self::$pages->why()->getContent();
        $stripped = (string) preg_replace('#<script\b[^>]*>.*?</script>#si', '', $html);

        $this->assertStringContainsString('OCAP', $stripped);
        $this->assertStringContainsString('alpha software', $stripped);
        $this->assertStringContainsString('ocap-audit-log', $stripped);
        $this->assertStringContainsString('fnprocure.ca', $stripped);

        $blocks = $this->jsonLdBlocks($html);
        $this->assertSame('TechArticle', $blocks[0]['@type']);
    }

    #[Test]
    public function compare_page_is_factual_and_carries_faq_schema(): void
    {
        $html = (string) self::$pages->compare()->getContent();
        $stripped = (string) preg_replace('#<script\b[^>]*>.*?</script>#si', '', $html);

        // The honest-gap row must survive any edit.
        $this->assertStringContainsString('largest gap', $stripped);
        $this->assertStringContainsString('Drupal 11', $stripped);
        $this->assertStringContainsString('GPL-2.0-or-later', $stripped);

        $blocks = $this->jsonLdBlocks($html);
        $faq = null;
        foreach ($blocks as $block) {
            if (($block['@type'] ?? '') === 'FAQPage') {
                $faq = $block;
            }
        }
        $this->assertNotNull($faq);
        $this->assertNotEmpty($faq['mainEntity']);
        foreach ($faq['mainEntity'] as $q) {
            $this->assertSame('Question', $q['@type']);
            $this->assertNotEmpty($q['acceptedAnswer']['text']);
        }
    }

    #[Test]
    public function home_carries_software_application_schema_with_real_version(): void
    {
        $response = new \App\Controller\HomeController(new \App\Support\PiTelemetry(null))->index();
        $blocks = $this->jsonLdBlocks((string) $response->getContent());

        $this->assertSame('SoftwareApplication', $blocks[0]['@type']);
        $this->assertSame('Waaseyaa', $blocks[0]['name']);
        $this->assertStringStartsWith('v0.1.0-alpha', $blocks[0]['softwareVersion']);
        $this->assertSame('PHP', $blocks[0]['programmingLanguage']);
    }

    #[Test]
    public function spec_pages_carry_tech_article_schema(): void
    {
        $docs = new DocsController(self::$corpus, new SiteUrl('https://waaseyaa.org'));
        $html = (string) $docs->spec(Request::create('/docs/specs/entity-system'), 'entity-system')->getContent();

        $blocks = $this->jsonLdBlocks($html);
        $this->assertSame('TechArticle', $blocks[0]['@type']);
        $this->assertSame('https://waaseyaa.org/docs/specs/entity-system', $blocks[0]['url']);
        $this->assertStringStartsWith('v0.1.0-alpha', $blocks[0]['version']);
    }

    #[Test]
    public function sitemap_lists_every_page(): void
    {
        $xml = (string) new SitemapController(self::$corpus, new SiteUrl('https://waaseyaa.org'))->serve()->getContent();

        $this->assertStringContainsString('<loc>https://waaseyaa.org/</loc>', $xml);
        $this->assertStringContainsString('<loc>https://waaseyaa.org/why</loc>', $xml);
        $this->assertStringContainsString('<loc>https://waaseyaa.org/compare</loc>', $xml);
        foreach (self::$corpus->all() as $spec) {
            $this->assertStringContainsString('/docs/specs/' . $spec['name'] . '</loc>', $xml);
        }

        $parsed = simplexml_load_string($xml);
        $this->assertNotFalse($parsed, 'sitemap.xml is well-formed XML');
    }
}
