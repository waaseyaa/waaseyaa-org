<?php

declare(strict_types=1);

namespace App\Provider;

use App\Chat\ChatPrompt;
use App\Chat\ChatSchema;
use App\Chat\ConversationStore;
use App\Chat\DocsRetriever;
use App\Chat\ExtractiveAnswerer;
use App\Controller\DocsChatController;
use App\Controller\DocsController;
use App\Controller\LlmsTxtController;
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

    public function register(): void {}

    public function boot(): void
    {
        // Best effort: the chat tables exist before the first request needs
        // them; a bare bootstrap (tests, CLI without a DB) skips silently.
        try {
            new ChatSchema(\App\Support\Db::persistent())->ensure();
        } catch (\Throwable) {
        }
    }

    public function routes(WaaseyaaRouter $router, ?\Waaseyaa\Entity\EntityTypeManager $entityTypeManager = null): void
    {
        $corpus = SpecCorpus::default();
        $urls = SiteUrl::fromEnvironment();

        $docs = new DocsController($corpus, $urls);
        $llms = new LlmsTxtController($corpus, $urls);

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

        $sitemap = new \App\Controller\SitemapController($corpus, $urls);
        $router->addRoute(
            'sitemap.xml',
            RouteBuilder::create('/sitemap.xml')
                ->controller(fn () => $sitemap->serve())
                ->allowAll()
                ->methods('GET')
                ->build(),
        );

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
            new SpecSearchTool($corpus, new SpecSearch($corpus), $urls),
            new SpecReadTool($corpus, $urls),
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
        // quotes otherwise.
        $anthropicKey = getenv('ANTHROPIC_API_KEY') ?: '';
        $chat = new DocsChatController(
            retriever: new DocsRetriever($corpus, new SpecSearch($corpus), $urls),
            prompts: new ChatPrompt(),
            extractive: new ExtractiveAnswerer(),
            conversations: new ConversationStore(\App\Support\Db::persistent()),
            urls: $urls,
            provider: $anthropicKey !== '' ? new \Waaseyaa\AI\Agent\Provider\AnthropicProvider($anthropicKey, self::CHAT_MODEL) : null,
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
