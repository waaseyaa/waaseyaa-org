<?php

declare(strict_types=1);

namespace App\Tests\Integration;

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
use Waaseyaa\Database\DBALDatabase;

final class DocsChatTest extends TestCase
{
    private DocsChatController $controller;

    protected function setUp(): void
    {
        $corpus = SpecCorpus::default();
        $urls = new SiteUrl('https://waaseyaa.org');

        $db = DBALDatabase::createSqlite(':memory:');
        new ChatSchema($db)->ensure();

        $index = new SpecIndex($corpus, $db);
        $index->ensure();

        $this->controller = new DocsChatController(
            retriever: new DocsRetriever($corpus, $index, $urls),
            prompts: new ChatPrompt(),
            extractive: new ExtractiveAnswerer(),
            conversations: new ConversationStore($db),
            urls: $urls,
            provider: null,
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $cookies
     */
    private function send(array $payload, array $cookies = []): Response
    {
        $request = Request::create('/docs-chat/send', 'POST', cookies: $cookies, content: json_encode($payload, JSON_THROW_ON_ERROR));

        return $this->controller->send($request);
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function sseEvents(Response $response): array
    {
        // The SSE emitter calls ob_flush(), which would dump a plain
        // ob_start() buffer to stdout; a callback buffer captures the
        // flushed chunks instead.
        $raw = '';
        ob_start(static function (string $chunk) use (&$raw): string {
            $raw .= $chunk;

            return '';
        });
        $response->sendContent();
        ob_end_flush();

        $events = [];
        foreach (explode("\n\n", trim($raw)) as $frame) {
            if (preg_match('/^event: (.+)\ndata: (.+)$/s', trim($frame), $m) !== 1) {
                continue;
            }
            $events[$m[1]][] = json_decode($m[2], true, 32, JSON_THROW_ON_ERROR);
        }

        return $events;
    }

    #[Test]
    public function rejects_empty_and_oversized_questions_with_machine_readable_limit(): void
    {
        $empty = $this->send(['question' => '']);
        $this->assertSame(422, $empty->getStatusCode());

        $long = $this->send(['question' => str_repeat('a', 501)]);
        $this->assertSame(422, $long->getStatusCode());
        $decoded = json_decode((string) $long->getContent(), true);
        $this->assertFalse($decoded['ok']);
        $this->assertSame(DocsChatController::MAX_QUESTION_CHARS, $decoded['limit']);
    }

    #[Test]
    public function every_answer_includes_at_least_one_citation_link(): void
    {
        $questions = [
            'How do I add an entity type?',
            'What is Bimaaji?',
            'How does the OCAP audit log work?',
            'Tell me about revisions and translations',
            'completely unrelated zzyzx gibberish qwxyz',
        ];

        foreach ($questions as $question) {
            $events = $this->sseEvents($this->send(['question' => $question]));

            $this->assertArrayHasKey('meta', $events, $question);
            $this->assertArrayHasKey('done', $events, $question);

            $sources = $events['done'][0]['sources'] ?? [];
            $this->assertNotEmpty($sources, 'No sources for: ' . $question);
            foreach ($sources as $source) {
                $this->assertStringStartsWith('https://waaseyaa.org/', $source['source_url'], $question);
                $this->assertNotSame('', $source['title'], $question);
            }

            $answer = implode('', array_map(static fn(array $d): string => (string) ($d['text'] ?? ''), $events['delta'] ?? []));
            $this->assertStringContainsString('](https://waaseyaa.org/', $answer, 'No citation link in answer body for: ' . $question);
        }
    }

    #[Test]
    public function grounded_answer_quotes_the_relevant_spec(): void
    {
        $events = $this->sseEvents($this->send(['question' => 'How do I add an entity type?']));

        $answer = implode('', array_map(static fn(array $d): string => (string) ($d['text'] ?? ''), $events['delta'] ?? []));
        $this->assertStringContainsString('entity', strtolower($answer));
        $this->assertStringContainsString('/docs/specs/', $answer);

        // The fix: title-weighted ranking surfaces the entity-system spec, not
        // an access-control spec that merely repeats the phrase in its body.
        $sources = $events['done'][0]['sources'] ?? [];
        $sourceUrls = array_column($sources, 'source_url');
        $this->assertContains(
            'https://waaseyaa.org/docs/specs/entity-system',
            $sourceUrls,
            'The entity-type question must rank and cite the entity-system spec.',
        );
    }

    #[Test]
    public function unanswerable_question_is_an_honest_miss_citing_the_docs_index(): void
    {
        $events = $this->sseEvents($this->send(['question' => 'zzyzx qwxyz plonk']));

        $sources = $events['done'][0]['sources'] ?? [];
        $this->assertSame('https://waaseyaa.org/docs', $sources[0]['source_url']);
    }

    #[Test]
    public function conversations_are_visitor_scoped(): void
    {
        $first = $this->send(['question' => 'What is Bimaaji?']);
        $this->sseEvents($first);

        $cookies = $first->headers->getCookies();
        $this->assertNotEmpty($cookies, 'First send sets the visitor cookie');
        $visitor = $cookies[0]->getValue();

        $events = $this->sseEvents($this->send(['question' => 'What is Bimaaji?'], ['waaseyaa_docs_chat' => $visitor]));
        $conversationId = $events['meta'][0]['conversation_id'];

        // The owning visitor can read the thread, per the messages contract.
        $mine = $this->controller->messages(
            Request::create('/docs-chat/' . $conversationId . '/messages', cookies: ['waaseyaa_docs_chat' => $visitor]),
            (string) $conversationId,
        );
        $decoded = json_decode((string) $mine->getContent(), true);
        $this->assertTrue($decoded['ok']);
        $this->assertNotEmpty($decoded['messages']);
        $this->assertArrayHasKey('has_more', $decoded);
        $this->assertArrayHasKey('oldest', $decoded);
        $this->assertSame('user', $decoded['messages'][0]['role']);

        // Another visitor gets a 404, not a view.
        $other = $this->controller->messages(
            Request::create('/docs-chat/' . $conversationId . '/messages', cookies: ['waaseyaa_docs_chat' => str_repeat('ab', 16)]),
            (string) $conversationId,
        );
        $this->assertSame(404, $other->getStatusCode());
    }

    #[Test]
    public function chat_routes_are_registered_publicly(): void
    {
        $router = new \Waaseyaa\Routing\WaaseyaaRouter();
        new \App\Provider\DocsServiceProvider()->routes($router);

        $this->assertSame('docs.chat.messages', $router->match('/docs-chat/7/messages')['_route'] ?? null);

        // match() probes with GET; the send route existing as POST-only
        // surfaces as method-not-allowed rather than not-found.
        $this->expectException(\Waaseyaa\Routing\Exception\RouteMethodNotAllowedException::class);
        $router->match('/docs-chat/send');
    }
}
