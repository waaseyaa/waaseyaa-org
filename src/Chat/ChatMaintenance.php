<?php

declare(strict_types=1);

namespace App\Chat;

use App\Support\OperationalLog;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Scheduler\ScheduledTask;
use Waaseyaa\Scheduler\ScheduleEntriesInterface;
use Waaseyaa\Scheduler\ScheduleInterface;

/**
 * Scheduled retention for the docs chat: transcripts older than the
 * retention window and lapsed rate-limit rows are deleted daily.
 * Discovered via the package manifest (ScheduleEntriesInterface) and
 * executed by `waaseyaa schedule:run`; DocsChatController additionally
 * prunes opportunistically so retention holds even where no scheduler
 * cron is wired.
 */
final class ChatMaintenance implements ScheduleEntriesInterface
{
    public function __construct(private readonly DatabaseInterface $database)
    {
    }

    public function register(ScheduleInterface $schedule): array
    {
        $database = $this->database;
        $task = new ScheduledTask(
            name: 'docs-chat-retention',
            expression: '10 4 * * *',
            command: static function () use ($database): void {
                self::prune($database, ChatLimits::fromEnvironment());
            },
            preventOverlap: true,
            description: 'Delete docs-chat transcripts past retention and lapsed rate-limit rows.',
        );
        $schedule->add($task);

        return ['docs-chat-retention' => $task];
    }

    public static function prune(DatabaseInterface $database, ChatLimits $limits): void
    {
        try {
            new ChatSchema($database)->ensure();
            $removed = new ConversationStore($database)->pruneOlderThan($limits->retentionDays);
            new ChatGuard($database, $limits, hashKey: 'prune-only')->pruneExpired();
            if ($removed > 0) {
                error_log(json_encode([
                    'level' => 'info',
                    'event' => 'chat_retention_prune',
                    'conversations_removed' => $removed,
                    'retention_days' => $limits->retentionDays,
                ], JSON_UNESCAPED_SLASHES) ?: 'chat_retention_prune');
            }
        } catch (\Throwable $e) {
            OperationalLog::warning('chat_retention_prune_failed', $e);
        }
    }
}
