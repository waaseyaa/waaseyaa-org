<?php

declare(strict_types=1);

/**
 * Container health command: run the readiness checks in-process without
 * booting the HTTP kernel (cheap enough for a 30-second Docker health
 * interval on a Pi). Exit 0 when ready, 1 otherwise; prints the same
 * pass/fail JSON the /healthz route serves.
 *
 * Usage: php bin/health-probe.php
 */

use App\Docs\SpecCorpus;
use App\Health\ReadinessCheck;
use App\Support\Db;

require __DIR__ . '/../vendor/autoload.php';

$projectRoot = dirname(__DIR__);
if (is_file($projectRoot . '/.env')) {
    (new \Symfony\Component\Dotenv\Dotenv())->loadEnv($projectRoot . '/.env', 'APP_ENV', 'production');
}

$result = new ReadinessCheck(
    databasePath: Db::path(),
    corpus: SpecCorpus::default(),
    environment: getenv('APP_ENV') ?: 'production',
)->run();

echo json_encode($result, JSON_UNESCAPED_SLASHES), "\n";

exit($result['status'] === 'ok' ? 0 : 1);
