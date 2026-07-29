<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controller\HealthController;
use App\Docs\SpecCorpus;
use App\Health\ReadinessCheck;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HealthEndpointTest extends TestCase
{
    #[Test]
    public function healthz_returns_503_with_a_safe_body_when_not_ready(): void
    {
        $controller = new HealthController(new ReadinessCheck(
            databasePath: '/nonexistent/absent.sqlite',
            corpus: SpecCorpus::default(),
            environment: 'test',
        ));

        $response = $controller->healthz();

        self::assertSame(503, $response->getStatusCode());
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $body = (string) $response->getContent();
        $doc = json_decode($body, true);
        self::assertSame('fail', $doc['status']);
        self::assertStringNotContainsString('/nonexistent', $body);
        self::assertStringNotContainsString('sqlite', $body);
    }

    #[Test]
    public function healthz_returns_200_when_ready(): void
    {
        $dbPath = sys_get_temp_dir() . '/healthz-' . bin2hex(random_bytes(6)) . '.sqlite';
        $pdo = new \PDO('sqlite:' . $dbPath);
        $pdo->exec('CREATE TABLE spec_index_state (id INTEGER PRIMARY KEY CHECK (id = 1), framework_version TEXT NOT NULL)');
        $pdo->prepare('INSERT INTO spec_index_state (id, framework_version) VALUES (1, ?)')
            ->execute([(string) SpecCorpus::default()->frameworkVersion()]);
        unset($pdo);

        try {
            $controller = new HealthController(new ReadinessCheck(
                databasePath: $dbPath,
                corpus: SpecCorpus::default(),
                environment: 'test',
            ));

            $response = $controller->healthz();

            self::assertSame(200, $response->getStatusCode());
            $doc = json_decode((string) $response->getContent(), true);
            self::assertSame('ok', $doc['status']);
            self::assertSame(
                ['database_readable', 'database_writable', 'corpus_loaded', 'corpus_indexed', 'config_present'],
                array_keys($doc['checks']),
            );
        } finally {
            @unlink($dbPath);
        }
    }
}
