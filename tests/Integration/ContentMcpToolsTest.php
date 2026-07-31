<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Content\ContentReader;
use App\Content\ContentSync;
use App\Docs\SpecCorpus;
use App\Docs\SpecSearch;
use App\Mcp\McpEndpointController;
use App\Mcp\PublicSpecsAuth;
use App\Mcp\SpecReaderAccount;
use App\Mcp\SpecToolRegistry;
use App\Mcp\Tool\ReleaseListTool;
use App\Mcp\Tool\RoadmapReadTool;
use App\Mcp\Tool\SpecListTool;
use App\Mcp\Tool\SpecReadTool;
use App\Mcp\Tool\SpecSearchTool;
use App\Support\SiteUrl;
use App\Tests\Support\ContentEntityHarness;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Mcp\McpEndpoint;

final class ContentMcpToolsTest extends TestCase
{
    private static McpEndpointController $controller;

    public static function setUpBeforeClass(): void
    {
        $corpus = SpecCorpus::default();
        $urls = new SiteUrl('https://waaseyaa.org');

        $manager = ContentEntityHarness::entityTypeManager();
        // Sync the REAL repo corpus so tests cover the shipped content.
        new ContentSync($manager, dirname(__DIR__, 2) . '/content')->sync();
        $content = new ContentReader($manager);

        $registry = new SpecToolRegistry([
            new SpecListTool($corpus, $urls),
            new SpecSearchTool($corpus, new SpecSearch($corpus), $urls),
            new SpecReadTool($corpus, $urls),
            new ReleaseListTool($content, $corpus, $urls),
            new RoadmapReadTool($content, $urls),
        ]);

        self::$controller = new McpEndpointController(
            new McpEndpoint(new PublicSpecsAuth(), $registry),
        );
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function rpc(string $method, array $params = []): array
    {
        $request = Request::create('/mcp', 'POST', content: json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => $method,
            'params' => $params === [] ? new \stdClass() : $params,
        ], JSON_THROW_ON_ERROR));

        // No Authorization header on purpose: the endpoint is public.
        $response = self::$controller->handle(new SpecReaderAccount(), $request);
        $this->assertSame(200, $response->getStatusCode(), $method);

        $decoded = json_decode((string) $response->getContent(), true, 64, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    #[Test]
    public function release_list_returns_releases_with_urls(): void
    {
        $result = $this->rpc('tools/call', ['name' => 'release_list', 'arguments' => new \stdClass()]);
        $payload = json_decode($result['result']['content'][0]['text'], true, 16, JSON_THROW_ON_ERROR);

        self::assertGreaterThanOrEqual(1, $payload['count']);
        self::assertSame('v0.1.0-alpha.276', $payload['releases'][0]['version']);
        self::assertSame('https://waaseyaa.org/releases/v0.1.0-alpha.276', $payload['releases'][0]['canonical_url']);
        self::assertSame('https://waaseyaa.org/releases/v0.1.0-alpha.276.md', $payload['releases'][0]['markdown_url']);
    }

    #[Test]
    public function roadmap_read_returns_grouped_horizons(): void
    {
        $result = $this->rpc('tools/call', ['name' => 'roadmap_read', 'arguments' => new \stdClass()]);
        $payload = json_decode($result['result']['content'][0]['text'], true, 16, JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('now', $payload['horizons']);
        $titles = array_column($payload['horizons']['now'], 'title');
        self::assertContains('Curated guides tier on waaseyaa.org', $titles);
    }
}
