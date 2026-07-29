<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Docs\SpecCorpus;
use App\Health\ReadinessCheck;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ReadinessCheckTest extends TestCase
{
    private string $dbPath;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/readiness-' . bin2hex(random_bytes(6)) . '.sqlite';
        $pdo = new \PDO('sqlite:' . $this->dbPath);
        $pdo->exec('CREATE TABLE spec_index_state (id INTEGER PRIMARY KEY CHECK (id = 1), framework_version TEXT NOT NULL)');
        $version = (string) SpecCorpus::default()->frameworkVersion();
        $pdo->prepare('INSERT INTO spec_index_state (id, framework_version) VALUES (1, ?)')->execute([$version]);
        unset($pdo);
    }

    protected function tearDown(): void
    {
        @unlink($this->dbPath);
    }

    private function check(?string $dbPath = null): ReadinessCheck
    {
        return new ReadinessCheck(
            databasePath: $dbPath ?? $this->dbPath,
            corpus: SpecCorpus::default(),
            environment: 'test',
        );
    }

    #[Test]
    public function ready_when_database_corpus_and_index_agree(): void
    {
        $result = $this->check()->run();

        self::assertSame('ok', $result['status'], json_encode($result['checks']) ?: '');
        self::assertSame('pass', $result['checks']['database_readable']);
        self::assertSame('pass', $result['checks']['database_writable']);
        self::assertSame('pass', $result['checks']['corpus_loaded']);
        self::assertSame('pass', $result['checks']['corpus_indexed']);
    }

    #[Test]
    public function missing_database_fails_without_disclosing_anything(): void
    {
        $result = $this->check('/nonexistent/dir/absent.sqlite')->run();

        self::assertSame('fail', $result['status']);
        self::assertSame('fail', $result['checks']['database_readable']);

        // The result must contain only check names and pass/fail values.
        $flat = json_encode($result) ?: '';
        self::assertStringNotContainsString('nonexistent', $flat);
        self::assertStringNotContainsString('.sqlite', $flat);
        self::assertStringNotContainsString('Exception', $flat);
    }

    #[Test]
    public function stale_index_version_fails_readiness(): void
    {
        $pdo = new \PDO('sqlite:' . $this->dbPath);
        $pdo->exec("UPDATE spec_index_state SET framework_version = 'v0.0.0-old'");
        unset($pdo);

        $result = $this->check()->run();

        self::assertSame('fail', $result['status']);
        self::assertSame('fail', $result['checks']['corpus_indexed']);
    }

    #[Test]
    public function corpus_minimum_is_a_real_bar(): void
    {
        // The synced corpus must clear the same >=80 bar the /llms.txt
        // shape test enforces, so a placeholder corpus cannot pass.
        self::assertGreaterThanOrEqual(ReadinessCheck::MIN_SPECS, count(SpecCorpus::default()->all()));
    }
}
