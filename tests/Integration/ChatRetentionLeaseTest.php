<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Chat\ChatRetentionCommand;
use App\Chat\ChatSchema;
use App\Tests\Support\SearchDatabase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Scheduler\Execution\LeaseExecutionContext;
use Waaseyaa\Scheduler\Lease\LeaseLostException;
use Waaseyaa\Scheduler\Testing\InMemoryFenceGuard;
use Waaseyaa\Scheduler\Testing\InMemoryLeaseAuthority;

final class ChatRetentionLeaseTest extends TestCase
{
    #[Test]
    public function lost_lease_refuses_retention_before_any_database_effect(): void
    {
        $database = SearchDatabase::create();
        new ChatSchema($database)->ensure();
        $authority = new InMemoryLeaseAuthority();
        $handle = $authority->acquire('docs-chat-retention', 300000);
        self::assertNotNull($handle);
        $context = new LeaseExecutionContext($authority, $handle, 300000, new InMemoryFenceGuard());
        $authority->release($handle);

        $this->expectException(LeaseLostException::class);
        new ChatRetentionCommand($database)->run($context);
    }
}
