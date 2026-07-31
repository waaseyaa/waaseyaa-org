<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Provider\ContentServiceProvider;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\RevisionableStorageDriver;
use Waaseyaa\EntityStorage\Driver\RevisionableStorageDriverV2;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\Driver\StorageBoundary;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory;

/**
 * In-memory entity stack registering exactly the types production
 * registers (ContentServiceProvider::entityTypes()).
 */
final class ContentEntityHarness
{
    public static function entityTypeManager(): EntityTypeManager
    {
        $database = DBALDatabase::createSqlite();
        $dispatcher = new EventDispatcher();

        $manager = new EntityTypeManager(
            $dispatcher,
            repositoryFactory: function (string $entityTypeId, EntityTypeInterface $definition) use ($database, $dispatcher) {
                $schemaHandler = new SqlSchemaHandler($definition, $database);
                $schemaHandler->ensureTable();
                if ($definition->isRevisionable()) {
                    $schemaHandler->ensureRevisionTable();
                }

                $resolver = new SingleConnectionResolver($database);
                $storageBoundary = new StorageBoundary();
                $driver = new SqlStorageDriver($resolver, $definition->getKeys()['id'] ?? 'id');

                // Revisionable entity types need an explicit revision driver;
                // the base factory call alone does not wire one, and
                // EntityRepository::listRevisions()/save() then throw
                // "Revision driver not configured" (mirrors how
                // EntityTypeManagerFactory::build() wires this in production).
                $revisionDriver = $definition->isRevisionable()
                    ? new RevisionableStorageDriverV2(
                        new RevisionableStorageDriver($resolver, $definition),
                        $storageBoundary->driverRowFactory(),
                        $storageBoundary->driverSnapshotReader(),
                    )
                    : null;

                return V2EntityRepositoryFactory::createFromSqlStorageDriver(
                    $definition,
                    $driver,
                    $dispatcher,
                    revisionDriver: $revisionDriver,
                    database: $database,
                    storageBoundary: $storageBoundary,
                );
            },
        );

        foreach (ContentServiceProvider::entityTypes() as $type) {
            $manager->registerEntityType($type);
        }

        return $manager;
    }
}
