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
use Waaseyaa\AI\Agent\Provider\MessageRequest;
use Waaseyaa\AI\Agent\Provider\MessageResponse;
use Waaseyaa\AI\Agent\Provider\StreamChunk;
use Waaseyaa\AI\Agent\Provider\StreamingProviderInterface;
use Waaseyaa\Database\DBALDatabase;

/**
 * Failures that happen AFTER the request passes validation must not take the
 * answer, or the process, down with them.
 *
 * Two blast radii are covered. Retrieval runs inside handle(), so its failure
 * is catchable but was reaching the visitor as a 500 on a site the docs
 * remained readable on. Transcript persistence in the model path runs inside
 * the StreamedResponse callback, which executes during Response::send() —
 * after the headers and the whole answer have already gone out, and outside
 * the front controller's try/catch. A throwable there had no boundary left to
 * catch it and the client never received `done`.
 *
 * Failures are injected through the real collaborators (a dropped FTS table, a
 * SQLite trigger that rejects the assistant row) rather than doubles, because
 * DocsRetriever and ConversationStore are final and injected as concrete
 * types. The trigger shape is deliberate: the visitor's own message must still
 * insert, so the request reaches the assistant write that this test is about.
 */
final class ChatFailureBoundariesTest extends TestCase
{
    private DBALDatabase $db;
    private SpecCorpus $corpus;
    private SpecIndex $index;
    private SiteUrl $urls;

    protected function setUp(): void
    {
        $this->corpus = SpecCorpus::default();
        $this->urls = new SiteUrl('https://waaseyaa.org');

        $this->db = DBALDatabase::createSqlite(':memory:');
        new ChatSchema($this->db)->ensure();

        $this->index = new SpecIndex($this->corpus, $this->db);
        $this->index->ensure();
    }

    private function controller(?object $provider = null): DocsChatController
    {
        return new DocsChatController(
            retriever: new DocsRetriever($this->corpus, $this->index, $this->urls),
            prompts: new ChatPrompt(),
            extractive: new ExtractiveAnswerer(),
            conversations: new ConversationStore($this->db),
            urls: $this->urls,
            provider: $provider,
        );
    }

    private function send(DocsChatController $controller, string $question): Response
    {
        $request = Request::create(
            '/docs-chat/send',
            'POST',
            content: json_encode(['question' => $question], JSON_THROW_ON_ERROR),
        );

        return $controller->send($request);
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function sseEvents(Response $response): array
    {
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

    /**
     * Reject only the assistant row, so the visitor's message still persists
     * and the request reaches the write under test.
     */
    private function rejectAssistantWrites(): void
    {
        $this->db->getConnection()->executeStatement(
            'CREATE TRIGGER reject_assistant BEFORE INSERT ON ' . ChatSchema::MESSAGES
            . " WHEN NEW.role = 'assistant' BEGIN SELECT RAISE(ABORT, 'simulated storage failure'); END",
        );
    }

    #[Test]
    public function a_storage_failure_while_streaming_still_closes_the_stream(): void
    {
        $controller = $this->controller(new SucceedingStreamProvider());
        $this->rejectAssistantWrites();

        $response = $this->send($controller, 'How do I add an entity type?');
        $this->assertSame(200, $response->getStatusCode());

        // sendContent() is where the closure runs. Before the fix the write
        // threw straight through it, so this call raised instead of returning.
        $events = $this->sseEvents($response);

        $this->assertArrayHasKey('done', $events, 'client must still receive done after a failed transcript write');
        $this->assertNotEmpty($events['delta'] ?? [], 'the answer must still reach the client');
        $this->assertNotEmpty($events['done'][0]['sources'], 'done must still carry citations');
    }

    #[Test]
    public function a_storage_failure_on_the_extractive_path_still_answers(): void
    {
        $controller = $this->controller();
        $this->rejectAssistantWrites();

        $response = $this->send($controller, 'How does the OCAP audit log work?');
        $this->assertSame(200, $response->getStatusCode());

        $events = $this->sseEvents($response);
        $this->assertArrayHasKey('done', $events);
        $this->assertNotEmpty($events['delta'] ?? []);
    }

    #[Test]
    public function an_unavailable_search_index_still_answers_with_a_citation(): void
    {
        // Characterization, not a regression: SpecIndex already degrades to
        // substring search when its FTS table is gone, so this passes both
        // before and after the retrieval guard. It is here to pin the
        // documented contract, that a degraded index still answers and still
        // cites, so a future change cannot quietly turn it into a 500.
        $this->db->getConnection()->executeStatement('DROP TABLE search_index');

        $response = $this->send($this->controller(), 'How do I add an entity type?');

        $this->assertSame(200, $response->getStatusCode());

        $events = $this->sseEvents($response);
        $this->assertArrayHasKey('done', $events);
        $this->assertNotEmpty($events['done'][0]['sources'], 'the >=1 citation invariant must survive a degraded index');
    }
}

/**
 * Streams one chunk and returns, so the test exercises the successful model
 * path right up to the transcript write. Local to this file rather than
 * shared, so running this test alone still works.
 */
final class SucceedingStreamProvider implements StreamingProviderInterface
{
    public function sendMessage(MessageRequest $request): MessageResponse
    {
        throw new \LogicException('streaming only');
    }

    public function streamMessage(MessageRequest $request, callable $onChunk): MessageResponse
    {
        $onChunk(new StreamChunk(type: 'text_delta', text: 'A grounded answer.'));

        return new MessageResponse(
            content: [['type' => 'text', 'text' => 'A grounded answer.']],
            stopReason: 'end_turn',
        );
    }
}
