<?php

declare(strict_types=1);

namespace App\Chat;

use Waaseyaa\Database\DatabaseInterface;

/**
 * Schema for the docs chat: plain non-entity tables (transcript storage,
 * no workflow or access semantics), so DatabaseInterface is the right
 * layer per the framework's persistence rules.
 */
final class ChatSchema
{
    public const CONVERSATIONS = 'docs_chat_conversation';
    public const MESSAGES = 'docs_chat_message';

    public function __construct(private readonly DatabaseInterface $db) {}

    public function ensure(): void
    {
        $this->db->query(
            'CREATE TABLE IF NOT EXISTS ' . self::CONVERSATIONS . ' (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                visitor TEXT NOT NULL,
                title TEXT NOT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )',
            [],
        );
        $this->db->query(
            'CREATE INDEX IF NOT EXISTS idx_docs_chat_conversation_visitor ON ' . self::CONVERSATIONS . ' (visitor)',
            [],
        );
        $this->db->query(
            'CREATE TABLE IF NOT EXISTS ' . self::MESSAGES . ' (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                conversation_id INTEGER NOT NULL,
                role TEXT NOT NULL,
                content TEXT NOT NULL,
                sources TEXT NOT NULL DEFAULT \'[]\',
                created_at TEXT NOT NULL
            )',
            [],
        );
        $this->db->query(
            'CREATE INDEX IF NOT EXISTS idx_docs_chat_message_conversation ON ' . self::MESSAGES . ' (conversation_id, id)',
            [],
        );
    }
}
