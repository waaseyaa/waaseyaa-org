<?php

declare(strict_types=1);

namespace App\Health;

use App\Docs\SpecCorpus;

/**
 * Readiness probe shared by the /healthz route and the container
 * health command (bin/health-probe.php).
 *
 * Checks report only pass/fail names; no paths, credentials, versions
 * beyond the public framework version, or exception text ever appear in
 * the result. The write probe rewrites PRAGMA user_version with its own
 * current value: it proves the SQLite file and directory are writable
 * without creating tables (schema changes would invalidate the
 * field-access activation artifact).
 */
final class ReadinessCheck
{
    public const MIN_SPECS = 80;

    public function __construct(
        private readonly string $databasePath,
        private readonly SpecCorpus $corpus,
        private readonly string $environment,
    ) {
    }

    /**
     * @return array{status: 'ok'|'fail', checks: array<string, 'pass'|'fail'>}
     */
    public function run(): array
    {
        $checks = [
            'database_readable' => $this->databaseReadable(),
            'database_writable' => $this->databaseWritable(),
            'corpus_loaded' => $this->corpusLoaded(),
            'corpus_indexed' => $this->corpusIndexed(),
            'config_present' => $this->configPresent(),
        ];

        $status = in_array('fail', $checks, true) ? 'fail' : 'ok';

        return ['status' => $status, 'checks' => $checks];
    }

    /** @return 'pass'|'fail' */
    private function databaseReadable(): string
    {
        try {
            $pdo = $this->pdo();
            $pdo->query('SELECT 1')->fetchColumn();

            return 'pass';
        } catch (\Throwable) {
            return 'fail';
        }
    }

    /** @return 'pass'|'fail' */
    private function databaseWritable(): string
    {
        try {
            $pdo = $this->pdo();
            $version = (int) $pdo->query('PRAGMA user_version')->fetchColumn();
            $pdo->exec('PRAGMA user_version = ' . $version);

            return 'pass';
        } catch (\Throwable) {
            return 'fail';
        }
    }

    /** @return 'pass'|'fail' */
    private function corpusLoaded(): string
    {
        try {
            return count($this->corpus->all()) >= self::MIN_SPECS ? 'pass' : 'fail';
        } catch (\Throwable) {
            return 'fail';
        }
    }

    /**
     * The FTS index must be stamped with the same framework version the
     * synced corpus manifest declares, or search and chat retrieval are
     * serving a stale (or absent) index.
     *
     * @return 'pass'|'fail'
     */
    private function corpusIndexed(): string
    {
        try {
            $expected = (string) ($this->corpus->frameworkVersion() ?? '');
            if ($expected === '') {
                return 'fail';
            }

            $indexed = $this->pdo()
                ->query('SELECT framework_version FROM spec_index_state WHERE id = 1')
                ->fetchColumn();

            return $indexed === $expected ? 'pass' : 'fail';
        } catch (\Throwable) {
            return 'fail';
        }
    }

    /** @return 'pass'|'fail' */
    private function configPresent(): string
    {
        if (in_array($this->environment, ['local', 'dev', 'development', 'test'], true)) {
            return 'pass';
        }

        $canonical = (getenv('WAASEYAA_ORG_CANONICAL_URL') ?: '') !== '' || (getenv('APP_URL') ?: '') !== '';
        $secret = (getenv('WAASEYAA_APP_SECRET') ?: '') !== '';

        return $canonical && $secret ? 'pass' : 'fail';
    }

    private function pdo(): \PDO
    {
        if (!is_file($this->databasePath)) {
            throw new \RuntimeException('database file absent');
        }

        return new \PDO('sqlite:' . $this->databasePath, options: [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_TIMEOUT => 5,
        ]);
    }
}
