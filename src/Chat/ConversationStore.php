<?php

declare(strict_types=1);

namespace App\Chat;

use Waaseyaa\Database\DatabaseInterface;

/**
 * Visitor-scoped conversation persistence for the public docs chat.
 *
 * The site has no public accounts, so the contract's "conversations are
 * account-scoped" rule maps to an opaque per-visitor token (random
 * cookie): messages and resuming filter by it, and another visitor's
 * conversation id is a miss, not a view.
 */
final class ConversationStore
{
    public function __construct(private readonly DatabaseInterface $db) {}

    public function create(string $visitor, string $title): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $title = mb_substr(trim($title), 0, 120);
        $this->db->query(
            'INSERT INTO ' . ChatSchema::CONVERSATIONS . ' (visitor, title, created_at, updated_at) VALUES (?, ?, ?, ?)',
            [$visitor, $title !== '' ? $title : 'New conversation', $now, $now],
        );

        foreach ($this->db->query('SELECT last_insert_rowid() AS id', []) as $row) {
            return (int) ($row['id'] ?? 0);
        }

        return 0;
    }

    /**
     * @return array{id: int, title: string}|null
     */
    public function findForVisitor(int $conversationId, string $visitor): ?array
    {
        foreach ($this->db->query(
            'SELECT id, title FROM ' . ChatSchema::CONVERSATIONS . ' WHERE id = ? AND visitor = ?',
            [$conversationId, $visitor],
        ) as $row) {
            return ['id' => (int) ($row['id'] ?? 0), 'title' => (string) ($row['title'] ?? '')];
        }

        return null;
    }

    /**
     * @param list<array{title: string, source_url: string}> $sources
     */
    public function addMessage(int $conversationId, string $role, string $content, array $sources = []): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->db->query(
            'INSERT INTO ' . ChatSchema::MESSAGES . ' (conversation_id, role, content, sources, created_at) VALUES (?, ?, ?, ?, ?)',
            [$conversationId, $role, $content, json_encode($sources, JSON_UNESCAPED_SLASHES), $now],
        );
        $this->db->query(
            'UPDATE ' . ChatSchema::CONVERSATIONS . ' SET updated_at = ? WHERE id = ?',
            [$now, $conversationId],
        );
    }

    /**
     * Newest page of turns per the workspace chat contract: without
     * $before the LAST $limit turns; with $before, the $limit turns
     * older than that message id. Returned oldest first.
     *
     * @return array{messages: list<array<string, mixed>>, has_more: bool, oldest: int}
     */
    public function page(int $conversationId, int $limit, ?int $before = null): array
    {
        $limit = max(1, min($limit, 100));
        $sql = 'SELECT id, role, content, sources FROM ' . ChatSchema::MESSAGES . ' WHERE conversation_id = ?';
        $params = [$conversationId];
        if ($before !== null) {
            $sql .= ' AND id < ?';
            $params[] = $before;
        }
        $sql .= ' ORDER BY id DESC LIMIT ?';
        $params[] = $limit + 1;

        $rows = [];
        foreach ($this->db->query($sql, $params) as $row) {
            $rows[] = $row;
        }

        $hasMore = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $rows = array_reverse($rows);

        $messages = [];
        $oldest = 0;
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            $oldest = $oldest === 0 ? $id : min($oldest, $id);
            $decoded = json_decode((string) ($row['sources'] ?? '[]'), true);
            $messages[] = [
                'id' => $id,
                'role' => (string) ($row['role'] ?? ''),
                'author' => (string) ($row['role'] ?? '') === 'user' ? 'You' : 'Docs assistant',
                'content' => (string) ($row['content'] ?? ''),
                'sources' => is_array($decoded) ? $decoded : [],
            ];
        }

        return ['messages' => $messages, 'has_more' => $hasMore, 'oldest' => $oldest];
    }
}
