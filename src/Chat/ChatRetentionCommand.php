<?php

declare(strict_types=1);

namespace App\Chat;

use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Scheduler\Execution\LeaseAwareCommandInterface;
use Waaseyaa\Scheduler\Execution\LeaseExecutionContext;

final readonly class ChatRetentionCommand implements LeaseAwareCommandInterface
{
    public function __construct(private DatabaseInterface $database)
    {
    }

    public function run(LeaseExecutionContext $context): void
    {
        $context->effect('docs-chat-retention', 'prune', function (): void {
            ChatMaintenance::prune($this->database, ChatLimits::fromEnvironment());
        });
    }
}
