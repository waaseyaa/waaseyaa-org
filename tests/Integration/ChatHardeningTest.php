<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Chat\ChatGuard;
use App\Chat\ChatLimits;
use App\Chat\ChatMaintenance;
use App\Chat\ChatPrompt;
use App\Chat\ChatSchema;
use App\Chat\ConversationStore;
use App\Chat\DocsRetriever;
use App\Chat\ExtractiveAnswerer;
use App\Controller\DocsChatController;
use App\Docs\SpecCorpus;
use App\Docs\SpecIndex;
use App\Support\SiteUrl;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\AI\Agent\Provider\MessageRequest;
use Waaseyaa\AI\Agent\Provider\MessageResponse;
use Waaseyaa\AI\Agent\Provider\StreamChunk;
use Waaseyaa\AI\Agent\Provider\StreamingProviderInterface;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DBALDatabase;

/**
 * Abuse, spend and retention bounds for the public docs chat. No test
 * here ever invokes a real model provider: the streaming provider is a
 * local fake that records whether it was called.
 */
final class ChatHardeningTest extends TestCase
{
    private DatabaseInterface $db;
    private FakeStreamingProvider $fake;

    protected function setUp(): void
    {
        $this->db = DBALDatabase::createSqlite(':memory:');
        new ChatSchema($this->db)->ensure();
        $this->fake = new FakeStreamingProvider();
    }

    private function controller(?ChatLimits $limits = null, ?StreamingProviderInterface $provider = null, ?ChatGuard $guard = null): DocsChatController
    {
        $limits ??= new ChatLimits();
        $corpus = SpecCorpus::default();
        $urls = new SiteUrl('https://waaseyaa.org');
        $index = new SpecIndex($corpus, $this->db);
        $index->ensure();

        return new DocsChatController(
            retriever: new DocsRetriever($corpus, $index, $urls),
            prompts: new ChatPrompt(),
            extractive: new ExtractiveAnswerer(),
            conversations: new ConversationStore($this->db),
            urls: $urls,
            provider: $provider,
            guard: $guard ?? new ChatGuard($this->db, $limits, hashKey: 'test-key'),
            limits: $limits,
        );
    }

    private function send(DocsChatController $controller, array $payload, array $cookies = [], string $ip = '203.0.113.7'): Response
    {
        $request = Request::create(
            '/docs-chat/send',
            'POST',
            cookies: $cookies,
            server: ['REMOTE_ADDR' => $ip],
            content: json_encode($payload, JSON_THROW_ON_ERROR),
        );

        return $controller->send($request);
    }

    private function drain(Response $response): string
    {
        $raw = '';
        ob_start(static function (string $chunk) use (&$raw): string {
            $raw .= $chunk;

            return '';
        });
        $response->sendContent();
        ob_end_flush();

        return $raw;
    }

    #[Test]
    public function per_visitor_minute_limit_returns_429_with_retry_after(): void
    {
        $limits = new ChatLimits(perVisitorPerMinute: 2);
        $controller = $this->controller($limits);
        $cookie = ['waaseyaa_docs_chat' => str_repeat('a', 32)];

        $this->drain($this->send($controller, ['question' => 'entities?'], $cookie));
        $this->drain($this->send($controller, ['question' => 'entities?'], $cookie));
        $third = $this->send($controller, ['question' => 'entities?'], $cookie);

        self::assertSame(429, $third->getStatusCode());
        self::assertGreaterThan(0, (int) $third->headers->get('Retry-After'));
        $doc = json_decode((string) $third->getContent(), true);
        self::assertFalse($doc['ok']);
    }

    #[Test]
    public function per_ip_limit_holds_across_different_visitors(): void
    {
        $limits = new ChatLimits(perIpPerMinute: 2, perVisitorPerMinute: 100);
        $controller = $this->controller($limits);

        $this->drain($this->send($controller, ['question' => 'a?'], ['waaseyaa_docs_chat' => str_repeat('a', 32)]));
        $this->drain($this->send($controller, ['question' => 'b?'], ['waaseyaa_docs_chat' => str_repeat('b', 32)]));
        $third = $this->send($controller, ['question' => 'c?'], ['waaseyaa_docs_chat' => str_repeat('c', 32)]);

        self::assertSame(429, $third->getStatusCode());
    }

    #[Test]
    public function raw_ip_addresses_never_reach_storage(): void
    {
        $controller = $this->controller();
        $this->drain($this->send($controller, ['question' => 'entities?'], ip: '198.51.100.42'));

        $stored = '';
        foreach ($this->db->query('SELECT bucket FROM ' . ChatSchema::LIMITS, []) as $row) {
            $stored .= ($row['bucket'] ?? '') . "\n";
        }

        self::assertNotSame('', $stored);
        self::assertStringNotContainsString('198.51.100.42', $stored);
        self::assertStringNotContainsString('198.51', $stored);
    }

    #[Test]
    public function oversized_request_body_is_rejected_with_413(): void
    {
        $controller = $this->controller(new ChatLimits(maxRequestBytes: 256));
        $response = $this->send($controller, ['question' => 'x', 'padding' => str_repeat('p', 512)]);

        self::assertSame(413, $response->getStatusCode());
    }

    #[Test]
    public function daily_model_budget_exhaustion_degrades_to_extractive_not_a_dead_end(): void
    {
        $limits = new ChatLimits(modelRequestsPerDay: 1, perVisitorPerMinute: 100);
        $guard = new ChatGuard($this->db, $limits, hashKey: 'test-key');
        $controller = $this->controller($limits, $this->fake, $guard);
        $cookie = ['waaseyaa_docs_chat' => str_repeat('a', 32)];

        $this->drain($this->send($controller, ['question' => 'entity types?'], $cookie));
        self::assertSame(1, $this->fake->calls, 'first request may use the model');

        $second = $this->send($controller, ['question' => 'entity storage?'], $cookie);
        self::assertSame(200, $second->getStatusCode());
        $body = $this->drain($second);

        self::assertSame(1, $this->fake->calls, 'budget exhausted: model must not be called again');
        self::assertStringContainsString('delta', $body, 'extractive answer still streams');
    }

    #[Test]
    public function circuit_breaker_opens_after_repeated_provider_failures(): void
    {
        $limits = new ChatLimits(breakerFailureThreshold: 2, perVisitorPerMinute: 100);
        $guard = new ChatGuard($this->db, $limits, hashKey: 'test-key');
        $failing = new FakeStreamingProvider(fail: true);
        $controller = $this->controller($limits, $failing, $guard);
        $cookie = ['waaseyaa_docs_chat' => str_repeat('a', 32)];

        // Two failures trip the breaker; each still answers extractively.
        foreach ([1, 2] as $n) {
            $body = $this->drain($this->send($controller, ['question' => 'q' . $n . '?'], $cookie));
            self::assertStringContainsString('delta', $body);
        }
        self::assertSame(2, $failing->calls);

        // Breaker open: the provider is not called at all.
        $this->drain($this->send($controller, ['question' => 'q3?'], $cookie));
        self::assertSame(2, $failing->calls, 'open breaker must bypass the provider');
    }

    #[Test]
    public function concurrency_cap_diverts_to_extractive(): void
    {
        $limits = new ChatLimits(maxConcurrentModelStreams: 1, perVisitorPerMinute: 100);
        $guard = new ChatGuard($this->db, $limits, hashKey: 'test-key');

        // Hold the only slot, as a concurrent stream would.
        self::assertTrue($guard->acquireModelSlot());

        $controller = $this->controller($limits, $this->fake, $guard);
        $this->drain($this->send($controller, ['question' => 'busy?'], ['waaseyaa_docs_chat' => str_repeat('a', 32)]));

        self::assertSame(0, $this->fake->calls, 'no free slot: model must not be called');
    }

    #[Test]
    public function visitors_cannot_read_each_others_conversations(): void
    {
        $controller = $this->controller();
        $mine = ['waaseyaa_docs_chat' => str_repeat('a', 32)];
        $theirs = ['waaseyaa_docs_chat' => str_repeat('b', 32)];

        $meta = $this->metaFrom($this->drain($this->send($controller, ['question' => 'entities?'], $mine)));
        $conversationId = (int) $meta['conversation_id'];
        self::assertGreaterThan(0, $conversationId);

        $other = $controller->messages(
            Request::create('/docs-chat/' . $conversationId . '/messages', cookies: $theirs),
            (string) $conversationId,
        );

        self::assertSame(404, $other->getStatusCode());
    }

    #[Test]
    public function clear_deletes_everything_the_visitor_owns_and_expires_the_cookie(): void
    {
        $controller = $this->controller();
        $cookie = ['waaseyaa_docs_chat' => str_repeat('a', 32)];

        $meta = $this->metaFrom($this->drain($this->send($controller, ['question' => 'entities?'], $cookie)));
        $conversationId = (int) $meta['conversation_id'];

        $cleared = $controller->clear(Request::create('/docs-chat/clear', 'POST', cookies: $cookie));
        self::assertSame(200, $cleared->getStatusCode());
        self::assertSame(1, json_decode((string) $cleared->getContent(), true)['cleared']);

        $expired = array_filter(
            $cleared->headers->getCookies(),
            static fn ($c) => $c->getName() === 'waaseyaa_docs_chat' && $c->isCleared(),
        );
        self::assertNotEmpty($expired, 'clear must expire the visitor cookie');

        $after = $controller->messages(
            Request::create('/docs-chat/' . $conversationId . '/messages', cookies: $cookie),
            (string) $conversationId,
        );
        self::assertSame(404, $after->getStatusCode());
    }

    #[Test]
    public function retention_prune_removes_expired_transcripts(): void
    {
        $store = new ConversationStore($this->db);
        $id = $store->create(str_repeat('a', 32), 'old conversation');
        $store->addMessage($id, 'user', 'ancient question');
        $this->db->query(
            'UPDATE ' . ChatSchema::CONVERSATIONS . " SET updated_at = '2020-01-01 00:00:00' WHERE id = ?",
            [$id],
        );

        ChatMaintenance::prune($this->db, new ChatLimits(retentionDays: 30));

        self::assertNull($store->findForVisitor($id, str_repeat('a', 32)));
        $messages = iterator_to_array($this->db->query('SELECT id FROM ' . ChatSchema::MESSAGES . ' WHERE conversation_id = ?', [$id]));
        self::assertSame([], $messages);
    }

    #[Test]
    public function visitor_cookie_is_bounded_httponly_and_secure_on_https(): void
    {
        $controller = $this->controller();
        $request = Request::create(
            'https://waaseyaa.org/docs-chat/send',
            'POST',
            server: ['REMOTE_ADDR' => '203.0.113.7', 'HTTPS' => 'on'],
            content: json_encode(['question' => 'entities?'], JSON_THROW_ON_ERROR),
        );

        $response = $controller->send($request);
        $this->drain($response);

        $cookies = array_filter(
            $response->headers->getCookies(),
            static fn ($c) => $c->getName() === 'waaseyaa_docs_chat',
        );
        self::assertCount(1, $cookies);
        $cookie = array_values($cookies)[0];

        self::assertTrue($cookie->isSecure(), 'Secure on HTTPS');
        self::assertTrue($cookie->isHttpOnly());
        self::assertSame('lax', strtolower((string) $cookie->getSameSite()));

        $maxAgeDays = ($cookie->getExpiresTime() - time()) / 86400;
        self::assertLessThanOrEqual(31, $maxAgeDays, 'cookie lifetime must be about 30 days, not a year');
        self::assertGreaterThan(27, $maxAgeDays);
    }

    /**
     * @return array<string, mixed>
     */
    private function metaFrom(string $sse): array
    {
        foreach (explode("\n\n", trim($sse)) as $frame) {
            if (preg_match('/^event: meta\ndata: (.+)$/s', trim($frame), $m) === 1) {
                return json_decode($m[1], true, 32, JSON_THROW_ON_ERROR);
            }
        }

        return [];
    }
}

/**
 * Local stand-in for the streaming provider. Never talks to a network;
 * records call counts so tests can assert the model was (not) used.
 */
final class FakeStreamingProvider implements StreamingProviderInterface
{
    public int $calls = 0;

    public function __construct(private readonly bool $fail = false)
    {
    }

    public function sendMessage(MessageRequest $request): MessageResponse
    {
        throw new \LogicException('tests use streaming only');
    }

    public function streamMessage(MessageRequest $request, callable $onChunk): MessageResponse
    {
        $this->calls++;
        if ($this->fail) {
            throw new \RuntimeException('simulated provider outage');
        }

        $onChunk(new StreamChunk(type: 'text_delta', text: 'A grounded answer.'));

        return new MessageResponse(
            content: [['type' => 'text', 'text' => 'A grounded answer.']],
            stopReason: 'end_turn',
        );
    }
}
