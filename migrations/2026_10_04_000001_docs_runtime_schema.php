<?php

declare(strict_types=1);

use App\Chat\ChatSchema;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

return new class extends Migration {
    public function up(SchemaBuilder $schema): void
    {
        new ChatSchema(new DBALDatabase($schema->getConnection()))->ensure();
        $schema->getConnection()->executeStatement(
            'CREATE TABLE IF NOT EXISTS spec_index_state (id INTEGER PRIMARY KEY CHECK (id = 1), framework_version TEXT NOT NULL)',
        );
    }
};
