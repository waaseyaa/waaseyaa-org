<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

final class SearchDatabase
{
    public static function create(): DBALDatabase
    {
        $database = DBALDatabase::createSqlite(':memory:');
        $migration = require dirname(__DIR__, 2) . '/vendor/waaseyaa/search/migrations/2026_09_24_000001_search_projection_schema.php';
        $migration->up(new SchemaBuilder($database->getConnection()));
        $appMigration = require dirname(__DIR__, 2) . '/migrations/2026_10_04_000001_docs_runtime_schema.php';
        $appMigration->up(new SchemaBuilder($database->getConnection()));

        return $database;
    }
}
