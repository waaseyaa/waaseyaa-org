<?php

declare(strict_types=1);

namespace App\Controller;

use App\Chat\ChatPrompt;
use App\Chat\ConversationStore;
use App\Chat\DocsRetriever;
use App\Chat\ExtractiveAnswerer;
use App\Chat\Passage;
use App\Support\SiteUrl;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Waaseyaa\AI\Agent\Provider\MessageRequest;
use Waaseyaa\AI\Agent\Provider\ProviderInterface;
use Waaseyaa\AI\Agent\Provider\StreamChunk;
use Waaseyaa\AI\Agent\Provider\StreamingProviderInterface;

/**
 * The public docs chat: single-turn grounded answers over the spec
 * corpus, streamed as SSE per the workspace chat contract
 * (workspace-chat-surface.md). Every answer carries at least one
 * citation to a canonical docs URL; without a model key the chat
 * quotes the relevant passages verbatim instead of imitating a model.
 */
final class DocsChatController
{
    public const MAX_QUESTION_CHARS = 500;
    private const MAX_TOKENS = 900;
    private const VISITOR_COOKIE = 'waaseyaa_docs_chat';

    public function __construct(
        private readonly DocsRetriever $retriever,
        private readonly ChatPrompt $prompts,
        private readonly ExtractiveAnswerer $extractive,
        private readonly ConversationStore $conversations,
        private readonly SiteUrl $urls,
        private readonly ?ProviderInterface $provider,
    ) {}

    public function send(Request $request): Response
    {
        $payload = json_decode((string) $request->getContent(), true);
        $data = is_array($payload) ? $payload : [];

        $question = trim((string) ($data['question'] ?? ''));
        if ($question === '' || mb_strlen($question) > self::MAX_QUESTION_CHARS) {
            return new JsonResponse([
                'ok' => false,
                'error' => sprintf('Provide a non-empty question of at most %d characters.', self::MAX_QUESTION_CHARS),
                'limit' => self::MAX_QUESTION_CHARS,
            ], 422);
        }

        [$visitor, $isNewVisitor] = $this->visitor($request);

        $conversationId = (int) ($data['conversation_id'] ?? 0);
        $conversation = $conversationId > 0 ? $this->conversations->findForVisitor($conversationId, $visitor) : null;
        if ($conversation === null) {
            $conversationId = $this->conversations->create($visitor, $this->titleFrom($question));
            $conversation = ['id' => $conversationId, 'title' => $this->titleFrom($question)];
        }
        $this->conversations->addMessage($conversationId, 'user', $question);

        $passages = $this->retriever->retrieve($question);
        $sources = $this->sources($passages);

        $response = $this->provider instanceof StreamingProviderInterface
            ? $this->streamModelAnswer($conversationId, $conversation['title'], $question, $passages, $sources)
            : $this->streamExtractiveAnswer($conversationId, $conversation['title'], $question, $passages, $sources);

        if ($isNewVisitor) {
            $response->headers->setCookie($this->visitorCookie($visitor));
        }

        return $response;
    }

    public function messages(Request $request, string $id): Response
    {
        [$visitor] = $this->visitor($request);

        $conversationId = (int) $id;
        $conversation = $conversationId > 0 ? $this->conversations->findForVisitor($conversationId, $visitor) : null;
        if ($conversation === null) {
            return new JsonResponse(['ok' => false, 'error' => 'Unknown conversation.'], 404);
        }

        $limit = (int) $request->query->get('limit', '30');
        $beforeRaw = $request->query->get('before');
        $before = is_numeric($beforeRaw) ? (int) $beforeRaw : null;

        $page = $this->conversations->page($conversationId, $limit > 0 ? $limit : 30, $before);

        return new JsonResponse([
            'ok' => true,
            'messages' => $page['messages'],
            'has_more' => $page['has_more'],
            'oldest' => $page['oldest'],
        ]);
    }

    /**
     * @param list<Passage> $passages
     * @param list<array{title: string, source_url: string}> $sources
     */
    private function streamModelAnswer(int $conversationId, string $title, string $question, array $passages, array $sources): StreamedResponse
    {
        $provider = $this->provider;
        \assert($provider instanceof StreamingProviderInterface);

        $messageRequest = new MessageRequest(
            messages: [['role' => 'user', 'content' => $this->prompts->userMessage($question, $passages)]],
            system: $this->prompts->system(),
            tools: [],
            maxTokens: self::MAX_TOKENS,
        );
        $conversations = $this->conversations;
        $extractive = $this->extractive;

        return $this->sse(static function () use ($provider, $messageRequest, $sources, $conversations, $conversationId, $title, $question, $passages, $extractive): void {
            self::emit('meta', ['conversation_id' => $conversationId, 'title' => $title]);

            $answer = '';
            try {
                $provider->streamMessage($messageRequest, static function (StreamChunk $chunk) use (&$answer): void {
                    if ($chunk->type === 'text_delta' && $chunk->text !== '') {
                        $clean = ChatPrompt::sanitizeDashes($chunk->text);
                        $answer .= $clean;
                        self::emit('delta', ['text' => $clean]);
                    }
                });
            } catch (\Throwable) {
                // Model unavailable mid-request: fall back to the grounded
                // extractive answer rather than a dead end.
                $fallback = $extractive->answer($question, $passages);
                $answer = $fallback;
                self::emit('delta', ['text' => "\n" . $fallback]);
            }

            if (trim($answer) === '') {
                $answer = ChatPrompt::NO_ANSWER;
                self::emit('delta', ['text' => $answer]);
            }

            // The client's source pills are plain text; the citation LINKS
            // live in the answer body, so every answer ends with them.
            $links = implode(' · ', array_map(
                static fn(array $s): string => sprintf('[%s](%s)', $s['title'], $s['source_url']),
                $sources,
            ));
            $sourcesLine = "\n\nSources: " . $links;
            $answer .= $sourcesLine;
            self::emit('delta', ['text' => $sourcesLine]);

            $conversations->addMessage($conversationId, 'assistant', $answer, $sources);
            self::emit('done', ['sources' => $sources]);
        });
    }

    /**
     * @param list<Passage> $passages
     * @param list<array{title: string, source_url: string}> $sources
     */
    private function streamExtractiveAnswer(int $conversationId, string $title, string $question, array $passages, array $sources): StreamedResponse
    {
        $answer = $this->extractive->answer($question, $passages);
        $conversations = $this->conversations;

        $conversations->addMessage($conversationId, 'assistant', $answer, $sources);

        return $this->sse(static function () use ($conversationId, $title, $answer, $sources): void {
            self::emit('meta', ['conversation_id' => $conversationId, 'title' => $title]);
            self::emit('delta', ['text' => $answer]);
            self::emit('done', ['sources' => $sources]);
        });
    }

    /**
     * Every answer cites: passage citations when retrieval hit, the docs
     * index when it did not.
     *
     * @param list<Passage> $passages
     * @return non-empty-list<array{title: string, source_url: string}>
     */
    private function sources(array $passages): array
    {
        $seen = [];
        $out = [];
        foreach ($passages as $passage) {
            $title = $passage->citationTitle();
            if (isset($seen[$title])) {
                continue;
            }
            $seen[$title] = true;
            $out[] = ['title' => $title, 'source_url' => $passage->url];
        }

        if ($out === []) {
            $out[] = ['title' => 'Docs index', 'source_url' => $this->urls->to('/docs')];
        }

        return $out;
    }

    /**
     * @return array{0: string, 1: bool} visitor token and whether it is new
     */
    private function visitor(Request $request): array
    {
        $token = (string) $request->cookies->get(self::VISITOR_COOKIE, '');
        if (preg_match('/^[a-f0-9]{32}$/', $token) === 1) {
            return [$token, false];
        }

        return [bin2hex(random_bytes(16)), true];
    }

    private function visitorCookie(string $token): Cookie
    {
        return Cookie::create(self::VISITOR_COOKIE)
            ->withValue($token)
            ->withExpires(new \DateTimeImmutable('+1 year'))
            ->withPath('/')
            ->withHttpOnly(true)
            ->withSameSite('lax')
            ->withSecure(false);
    }

    private function sse(callable $body): StreamedResponse
    {
        return new StreamedResponse(static function () use ($body): void {
            $body();
        }, Response::HTTP_OK, [
            'Content-Type' => 'text/event-stream; charset=utf-8',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function emit(string $event, array $data): void
    {
        echo 'event: ' . $event . "\n";
        echo 'data: ' . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";
        if (ob_get_level() > 0) {
            @ob_flush();
        }
        @flush();
    }

    private function titleFrom(string $question): string
    {
        $title = trim((string) preg_replace('/\s+/', ' ', $question));

        return mb_strlen($title) > 60 ? mb_substr($title, 0, 57) . '...' : $title;
    }
}
