<?php

declare(strict_types=1);

namespace App\Support;

use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DBALDatabase;

/**
 * Shared accessor for the app's persistent SQLite file, mirroring the
 * fnpi-waaseyaa pattern: resolve(DatabaseInterface) at route-build time
 * can hand back an ephemeral connection, so chat data is pinned to the
 * same on-disk file the kernel uses. WAASEYAA_DB if set (relative paths
 * against the project root), else storage/waaseyaa.sqlite.
 */
final class Db
{
    public static function path(): string
    {
        $root = dirname(__DIR__, 2);
        $configured = getenv('WAASEYAA_DB') ?: '';
        if ($configured === '') {
            return $root . '/storage/waaseyaa.sqlite';
        }
        $isAbsolute = str_starts_with($configured, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $configured) === 1;

        return $isAbsolute ? $configured : $root . '/' . ltrim($configured, './');
    }

    public static function persistent(): DatabaseInterface
    {
        return DBALDatabase::createSqlite(self::path());
    }
}
