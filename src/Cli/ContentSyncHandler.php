<?php

declare(strict_types=1);

namespace App\Cli;

use App\Content\ContentSync;
use App\Content\ContentSyncException;
use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Foundation\Kernel\ConsoleKernel;

/**
 * `waaseyaa content:sync`: sync content/*.md into entities. Runs as a
 * one-shot on deploy right after db:init; a failure exits non-zero so
 * the deploy fails rather than half-publishing.
 */
final class ContentSyncHandler
{
    public function __construct(
        private readonly string $projectRoot,
    ) {
    }

    public function execute(SymfonyCommandIO $io): int
    {
        $kernel = new ConsoleKernel($this->projectRoot);
        $kernel->bootForCli();

        // Trigger the (lazy) kernel boot and capture the services BEFORE the
        // try/finally, mirroring DbInitHandler::syncSchema: if boot fails, the
        // exception propagates cleanly here, and the finally never re-triggers
        // a boot by calling a kernel accessor again.
        $entityTypeManager = $kernel->getEntityTypeManager();
        $database = $kernel->getDatabase();

        try {
            $report = new ContentSync($entityTypeManager, $this->projectRoot . '/content')->sync();
            $io->writeln('content:sync ' . $report->summary());

            return 0;
        } catch (ContentSyncException $e) {
            $io->error('content:sync failed: ' . $e->getMessage());

            return 1;
        } finally {
            // Release this kernel's DB connection so a one-shot CLI run never
            // holds a lock on the SQLite file (matches DbInitHandler::syncSchema;
            // db:init runs right before this in the deploy sequence).
            if ($database instanceof DBALDatabase) {
                $database->getConnection()->close();
            }
        }
    }
}
