<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controller\DocsController;
use App\Docs\SpecCorpus;
use App\Provider\DocsServiceProvider;
use App\Support\SiteUrl;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Routing\WaaseyaaRouter;
use Waaseyaa\SSR\SsrServiceProvider;

final class DocsRenderingTest extends TestCase
{
    private static SpecCorpus $corpus;
    private static DocsController $controller;

    public static function setUpBeforeClass(): void
    {
        $provider = new SsrServiceProvider();
        $provider->setKernelContext(dirname(__DIR__, 2), [], []);
        $provider->boot();

        self::$corpus = SpecCorpus::default();
        self::$controller = new DocsController(self::$corpus, new SiteUrl('https://waaseyaa.org'));
    }

    #[Test]
    public function the_synced_corpus_is_present_with_provenance(): void
    {
        $this->assertNotEmpty(self::$corpus->all(), 'Run php bin/sync-specs.php');
        $this->assertNotNull(self::$corpus->frameworkVersion());
        $this->assertStringStartsWith('v0.1.0', (string) self::$corpus->frameworkVersion());
    }

    #[Test]
    public function docs_routes_are_registered(): void
    {
        $router = new WaaseyaaRouter();
        new DocsServiceProvider()->routes($router);

        $this->assertSame('docs.index', $router->match('/docs')['_route'] ?? null);
        $this->assertSame('docs.spec', $router->match('/docs/specs/entity-system')['_route'] ?? null);
        $this->assertSame('docs.spec', $router->match('/docs/specs/entity-system.md')['_route'] ?? null);
        $this->assertSame('llms.txt', $router->match('/llms.txt')['_route'] ?? null);
        $this->assertSame('mcp.endpoint', $router->match('/mcp')['_route'] ?? null);
        $this->assertSame('mcp.server_card', $router->match('/.well-known/mcp.json')['_route'] ?? null);
    }

    #[Test]
    public function markdown_negotiation_works_on_every_docs_url(): void
    {
        $accept = Request::create('/docs/specs/x', server: ['HTTP_ACCEPT' => 'text/markdown']);

        foreach (self::$corpus->all() as $spec) {
            $response = self::$controller->spec($accept, $spec['name']);

            $this->assertSame(200, $response->getStatusCode(), $spec['name']);
            $this->assertStringStartsWith('text/markdown', (string) $response->headers->get('Content-Type'), $spec['name']);
            $this->assertSame(self::$corpus->markdown($spec['name']), $response->getContent(), $spec['name']);
            $this->assertStringContainsString('Accept', (string) $response->headers->get('Vary'), $spec['name']);
            $this->assertStringContainsString(
                'https://waaseyaa.org/docs/specs/' . $spec['name'],
                (string) $response->headers->get('Link'),
                $spec['name'],
            );
        }

        $index = self::$controller->index($accept);
        $this->assertStringStartsWith('text/markdown', (string) $index->headers->get('Content-Type'));
        $this->assertStringContainsString('# Waaseyaa docs', (string) $index->getContent());
    }

    #[Test]
    public function md_suffix_serves_markdown_without_accept_header(): void
    {
        $plain = Request::create('/docs/specs/entity-system.md');
        $response = self::$controller->spec($plain, 'entity-system.md');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringStartsWith('text/markdown', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('# Entity System', (string) $response->getContent());
        $this->assertSame('entity-system.md', $response->headers->get('X-Waaseyaa-Spec'));
    }

    #[Test]
    public function html_rendering_carries_content_and_provenance_without_js(): void
    {
        $response = self::$controller->spec(Request::create('/docs/specs/entity-system'), 'entity-system');
        $html = (string) $response->getContent();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringStartsWith('text/html', (string) $response->headers->get('Content-Type'));

        $withoutScripts = (string) preg_replace('#<script\b[^>]*>.*?</script>#si', '', $html);
        $this->assertStringContainsString('Entity System', $withoutScripts);
        $this->assertStringContainsString('synced verbatim', $withoutScripts);
        $this->assertStringContainsString((string) self::$corpus->frameworkVersion(), $withoutScripts);
        $this->assertStringContainsString('rel="canonical"', $withoutScripts);
        $this->assertStringContainsString('/docs/specs/entity-system.md', $withoutScripts);
    }

    #[Test]
    public function docs_index_lists_every_spec(): void
    {
        $response = self::$controller->index(Request::create('/docs'));
        $html = (string) $response->getContent();

        foreach (self::$corpus->all() as $spec) {
            $this->assertStringContainsString('/docs/specs/' . $spec['name'], $html);
        }
    }

    #[Test]
    public function unknown_spec_returns_404(): void
    {
        $response = self::$controller->spec(Request::create('/docs/specs/nope'), 'nope');
        $this->assertSame(404, $response->getStatusCode());

        $traversal = self::$controller->spec(Request::create('/docs/specs/x'), '../CLAUDE');
        $this->assertSame(404, $traversal->getStatusCode());
    }
}
