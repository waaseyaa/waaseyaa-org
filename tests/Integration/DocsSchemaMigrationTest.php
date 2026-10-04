<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Docs\SpecCorpus;
use App\Docs\SpecIndex;
use App\Tests\Support\SearchDatabase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DocsSchemaMigrationTest extends TestCase
{
    #[Test]
    public function indexing_populates_migrated_tables_without_changing_schema(): void
    {
        $database = SearchDatabase::create();
        $connection = $database->getConnection();
        $before = $connection->fetchAllAssociative('SELECT name, sql FROM sqlite_master ORDER BY name');

        $index = new SpecIndex(SpecCorpus::default(), $database);
        $index->ensure();
        $index->ensure();

        $this->assertSame($before, $connection->fetchAllAssociative('SELECT name, sql FROM sqlite_master ORDER BY name'));
        $this->assertSame(SpecCorpus::default()->frameworkVersion(), $connection->fetchOne('SELECT framework_version FROM spec_index_state WHERE id = 1'));
        $this->assertGreaterThanOrEqual(80, (int) $connection->fetchOne('SELECT COUNT(*) FROM search_index'));
        $this->assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM docs_chat_message'));
    }
}
