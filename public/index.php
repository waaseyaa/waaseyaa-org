<?php

declare(strict_types=1);

// Built-in PHP server: serve existing files from public/ directly.
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if (is_file($file)) {
        return false;
    }
}

require __DIR__ . '/../vendor/autoload.php';

$projectRoot = dirname(__DIR__);
if (is_file($projectRoot . '/.env')) {
    // Match Minoo: default missing APP_ENV to production (not Symfony's implicit "dev").
    (new \Symfony\Component\Dotenv\Dotenv())->loadEnv($projectRoot . '/.env', 'APP_ENV', 'production');
}

// Last-resort boundary: whatever escapes the kernel (including kernel
// construction) becomes a generic 500 carrying only a correlation id.
// The throwable is logged server-side under that id and never reaches
// the response body.
try {
    $kernel = new \Waaseyaa\Foundation\Kernel\HttpKernel($projectRoot);
    $response = $kernel->handle();
} catch (\Throwable $e) {
    $response = \App\Http\PublicErrorHandler::respond($e);
}

$response->send();
