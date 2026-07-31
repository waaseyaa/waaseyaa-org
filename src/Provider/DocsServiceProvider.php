<?php

declare(strict_types=1);

namespace App\Provider;

use App\Chat\ChatPrompt;
use App\Chat\ChatSchema;
use App\Chat\ConversationStore;
use App\Chat\DocsRetriever;
use App\Chat\ExtractiveAnswerer;
use App\Content\ContentReader;
use App\Controller\DocsChatController;
use App\Controller\DocsController;
use App\Controller\LlmsTxtController;
use App\Docs\SpecCorpus;
use App\Docs\SpecIndex;
use App\Docs\SpecSearch;
use App\Mcp\McpEndpointController;
use App\Mcp\PublicServerCard;
use App\Mcp\PublicSpecsAuth;
use App\Mcp\SpecReaderAccount;
use App\Mcp\SpecToolRegistry;
use App\Mcp\Tool\ReleaseListTool;
use App\Mcp\Tool\RoadmapReadTool;
use App\Mcp\Tool\SpecListTool;
use App\Mcp\Tool\SpecReadTool;
use App\Mcp\Tool\SpecSearchTool;
use App\Support\SiteUrl;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Mcp\McpEndpoint;
use Waaseyaa\Routing\RouteBuilder;
use Waaseyaa\Routing\WaaseyaaRouter;

/**
 * The docs corpus surface: HTML docs pages, Markdown negotiation,
 * llms.txt, and the PUBLIC read-only MCP endpoint. One corpus, three
 * renderings; the MCP tools and the docs pages read the same files.
 */
final class DocsServiceProvider extends ServiceProvider
{
    public const string CHAT_MODEL = 'claude-sonnet-4-6';

    public function register(): void
    {
    }

    public function boot(): void
    {
        // Best effort: the chat tables exist before the first request needs
        // them; a bare bootstrap (tests, CLI without a DB) degrades, but
        // never silently.
        try {
            new ChatSchema(\App\Support\Db::persistent())->ensure();
        } catch (\Throwable $e) {
            \App\Support\OperationalLog::warning('chat_schema_ensure_failed', $e);
        }
    }

    public function routes(WaaseyaaRouter $router, ?\Waaseyaa\Entity\EntityTypeManager $entityTypeManager = null): void
    {
        $corpus = SpecCorpus::default();
        $urls = SiteUrl::fromEnvironment();
        $content = new ContentReader($entityTypeManager);

        // Title-weighted FTS5 ranking over the synced corpus, shared by the MCP
        // spec_search tool and the docs chat. ensure() is idempotent and rebuilds
        // only when the synced framework version changes; a failure degrades to
        // the corpus-order substring fallback (MCP) and an honest "not covered"
        // (chat) rather than a 500.
        $specIndex = new SpecIndex($corpus, \App\Support\Db::persistent());
        try {
            $specIndex->ensure();
        } catch (\Throwable $e) {
            \App\Support\OperationalLog::warning('spec_index_ensure_failed', $e);
        }
        $search = new SpecSearch($corpus, $specIndex);

        $docs = new DocsController($corpus, $urls);
        $llms = new LlmsTxtController($corpus, $urls, $content);

        $router->addRoute(
            'docs.index',
            RouteBuilder::create('/docs')
                ->controller(fn (Request $request) => $docs->index($request))
                ->allowAll()
                ->methods('GET')
                ->build(),
        );

        $router->addRoute(
            'docs.spec',
            RouteBuilder::create('/docs/specs/{name}')
                ->controller(fn (Request $request, string $name) => $docs->spec($request, $name))
                ->allowAll()
                ->methods('GET')
                ->build(),
        );

        $sitemap = new \App\Controller\SitemapController($corpus, $urls, $content);
        // waaseyaa/ssr registers its own entity-driven /sitemap.xml
        // (seo.sitemap_xml, priority 10) which would shadow this corpus
        // sitemap with an empty urlset on a site that registers no
        // entities. Same override lever as the MCP routes above.
        $router->removeRoute('seo.sitemap_xml');
        $router->addRoute(
            'sitemap.xml',
            RouteBuilder::create('/sitemap.xml')
                ->controller(fn () => $sitemap->serve())
                ->allowAll()
                ->methods('GET')
                ->build(),
        );

        // waaseyaa/ssr registers its own /llms.txt (seo.llms_txt, priority
        // 10) whose generic body shadowed this corpus index in production.
        // Same documented override lever as the MCP and sitemap routes.
        $router->removeRoute('seo.llms_txt');
        $router->addRoute(
            'llms.txt',
            RouteBuilder::create('/llms.txt')
                ->controller(fn () => $llms->serve())
                ->allowAll()
                ->methods('GET')
                ->build(),
        );

        // Public read-only MCP. The framework registers /mcp against its
        // bearer-token endpoint whose McpResponse return the SSR dispatcher
        // cannot convert (same alpha-line gap fnpi-waaseyaa works around);
        // app providers register after waaseyaa/mcp, so removeRoute() is the
        // documented override lever.
        $registry = new SpecToolRegistry([
            new SpecListTool($corpus, $urls),
            new SpecSearchTool($corpus, $search, $urls),
            new SpecReadTool($corpus, $urls),
            new ReleaseListTool($content, $corpus, $urls),
            new RoadmapReadTool($content, $urls),
        ]);
        $mcp = new McpEndpointController(new McpEndpoint(new PublicSpecsAuth(), $registry));

        $router->removeRoute('mcp.endpoint');
        $router->addRoute(
            'mcp.endpoint',
            RouteBuilder::create('/mcp')
                // The closure dispatcher injects the Request only. The typed
                // account parameter on McpEndpoint::handle() is ignored by the
                // endpoint (the Authorization header is the MCP identity), so
                // the public reader account is a faithful placeholder.
                ->controller(fn (Request $request) => $mcp->handle(new SpecReaderAccount(), $request))
                ->allowAll()
                ->methods('POST', 'GET')
                ->csrfExempt()
                ->build(),
        );

        // Docs chat: grounded on the same corpus and search engine as the
        // MCP tools. Anthropic when a key is configured; honest extractive
        // quotes otherwise. ChatGuard bounds abuse and spend: per-visitor
        // and hashed-per-IP rate limits, a daily model budget, a stream
        // concurrency cap and a failure circuit breaker.
        $anthropicKey = getenv('ANTHROPIC_API_KEY') ?: '';
        $chatLimits = \App\Chat\ChatLimits::fromEnvironment();
        $chat = new DocsChatController(
            retriever: new DocsRetriever($corpus, $specIndex, $urls),
            prompts: new ChatPrompt(),
            extractive: new ExtractiveAnswerer(),
            conversations: new ConversationStore(\App\Support\Db::persistent()),
            urls: $urls,
            provider: $anthropicKey !== '' ? new \Waaseyaa\AI\Agent\Provider\AnthropicProvider($anthropicKey, self::CHAT_MODEL) : null,
            guard: new \App\Chat\ChatGuard(
                \App\Support\Db::persistent(),
                $chatLimits,
                hashKey: hash('sha256', 'waaseyaa-chat-ip.' . (getenv('WAASEYAA_APP_SECRET') ?: bin2hex(random_bytes(16)))),
            ),
            limits: $chatLimits,
        );

        $router->addRoute(
            'docs.chat.send',
            RouteBuilder::create('/docs-chat/send')
                ->controller(fn (Request $request) => $chat->send($request))
                ->allowAll()
                ->methods('POST')
                ->csrfExempt()
                ->build(),
        );

        $router->addRoute(
            'docs.chat.messages',
            RouteBuilder::create('/docs-chat/{id}/messages')
                ->controller(fn (Request $request, string $id) => $chat->messages($request, $id))
                ->allowAll()
                ->methods('GET')
                ->build(),
        );

        $router->addRoute(
            'docs.chat.clear',
            RouteBuilder::create('/docs-chat/clear')
                ->controller(fn (Request $request) => $chat->clear($request))
                ->allowAll()
                ->methods('POST')
                ->csrfExempt()
                ->build(),
        );

        // Replace the framework server card (it advertises bearer auth;
        // this endpoint is public).
        $card = new PublicServerCard($corpus, $urls);
        $router->removeRoute('mcp.server_card');
        $router->addRoute(
            'mcp.server_card',
            RouteBuilder::create('/.well-known/mcp.json')
                ->controller(fn () => $card->serve())
                ->allowAll()
                ->methods('GET')
                ->build(),
        );
    }
}
