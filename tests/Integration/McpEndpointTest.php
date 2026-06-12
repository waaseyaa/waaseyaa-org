<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Docs\SpecCorpus;
use App\Docs\SpecSearch;
use App\Mcp\McpEndpointController;
use App\Mcp\PublicServerCard;
use App\Mcp\PublicSpecsAuth;
use App\Mcp\SpecReaderAccount;
use App\Mcp\SpecToolRegistry;
use App\Mcp\Tool\SpecListTool;
use App\Mcp\Tool\SpecReadTool;
use App\Mcp\Tool\SpecSearchTool;
use App\Support\SiteUrl;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Mcp\McpEndpoint;

final class McpEndpointTest extends TestCase
{
    private static McpEndpointController $controller;
    private static SpecCorpus $corpus;
    private static SpecToolRegistry $registry;

    public static function setUpBeforeClass(): void
    {
        self::$corpus = SpecCorpus::default();
        $urls = new SiteUrl('https://waaseyaa.org');

        self::$registry = new SpecToolRegistry([
            new SpecListTool(self::$corpus, $urls),
            new SpecSearchTool(self::$corpus, new SpecSearch(self::$corpus), $urls),
            new SpecReadTool(self::$corpus, $urls),
        ]);

        self::$controller = new McpEndpointController(
            new McpEndpoint(new PublicSpecsAuth(), self::$registry),
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
    public function tools_list_exposes_exactly_the_three_read_only_spec_tools(): void
    {
        $result = $this->rpc('tools/list');

        $names = array_column($result['result']['tools'] ?? [], 'name');
        sort($names);
        $this->assertSame(['spec_list', 'spec_read', 'spec_search'], $names);

        foreach ($result['result']['tools'] as $tool) {
            $this->assertNotEmpty($tool['description']);
            $this->assertArrayHasKey('inputSchema', $tool);
        }
    }

    #[Test]
    public function no_destructive_tool_exists_on_the_public_surface(): void
    {
        foreach (self::$registry->all() as $tool) {
            $this->assertFalse($tool->destructive, $tool->name);
            $this->assertSame(SpecReaderAccount::CAPABILITY, $tool->capability, $tool->name);
        }
    }

    #[Test]
    public function spec_read_round_trip_returns_content_with_canonical_url(): void
    {
        $result = $this->rpc('tools/call', [
            'name' => 'spec_read',
            'arguments' => ['name' => 'entity-system'],
        ]);

        $content = $result['result']['content'][0]['text'] ?? '';
        $payload = json_decode($content, true, 32, JSON_THROW_ON_ERROR);

        $this->assertSame('https://waaseyaa.org/docs/specs/entity-system', $payload['canonical_url']);
        $this->assertSame(self::$corpus->frameworkVersion(), $payload['framework_version']);
        $this->assertStringContainsString('# Entity System', $payload['markdown']);
        $this->assertFalse($result['result']['isError'] ?? false);
    }

    #[Test]
    public function spec_search_round_trip_returns_cited_matches(): void
    {
        $result = $this->rpc('tools/call', [
            'name' => 'spec_search',
            'arguments' => ['query' => 'EntityRepository', 'limit' => 5],
        ]);

        $payload = json_decode($result['result']['content'][0]['text'] ?? '', true, 32, JSON_THROW_ON_ERROR);

        $this->assertNotEmpty($payload['matches']);
        $this->assertLessThanOrEqual(5, count($payload['matches']));
        foreach ($payload['matches'] as $match) {
            $this->assertStringStartsWith('https://waaseyaa.org/docs/specs/', $match['canonical_url']);
            $this->assertArrayHasKey('snippet', $match);
            $this->assertArrayHasKey('line', $match);
        }
    }

    #[Test]
    public function spec_list_includes_every_synced_spec(): void
    {
        $result = $this->rpc('tools/call', ['name' => 'spec_list', 'arguments' => []]);
        $payload = json_decode($result['result']['content'][0]['text'] ?? '', true, 32, JSON_THROW_ON_ERROR);

        $this->assertSame(count(self::$corpus->all()), $payload['count']);
    }

    #[Test]
    public function unknown_tool_and_unknown_spec_fail_cleanly(): void
    {
        $unknownTool = $this->rpc('tools/call', ['name' => 'entity_delete', 'arguments' => []]);
        $this->assertArrayHasKey('error', $unknownTool);

        $unknownSpec = $this->rpc('tools/call', ['name' => 'spec_read', 'arguments' => ['name' => 'nope']]);
        $this->assertTrue($unknownSpec['result']['isError']);
    }

    #[Test]
    public function server_card_advertises_the_public_endpoint(): void
    {
        $response = new PublicServerCard(self::$corpus, new SiteUrl('https://waaseyaa.org'))->serve();
        $this->assertSame(200, $response->getStatusCode());

        $card = json_decode((string) $response->getContent(), true, 16, JSON_THROW_ON_ERROR);
        $this->assertSame('/mcp', $card['endpoint']);
        $this->assertSame('https://waaseyaa.org/mcp', $card['endpoint_url']);
        $this->assertSame('none', $card['authentication']['type']);
        $this->assertTrue($card['capabilities']['tools']);
    }
}
