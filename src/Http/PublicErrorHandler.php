<?php

declare(strict_types=1);

namespace App\Http;

use Symfony\Component\HttpFoundation\Response;

/**
 * Last-resort public error boundary for public/index.php.
 *
 * The public body is always the same generic JSON:API document plus a
 * correlation id; the throwable's class, message, file and trace go only
 * to the server-side log, keyed by that same id. Nothing derived from
 * the exception may reach the response.
 */
final class PublicErrorHandler
{
    /**
     * @param callable(string): void|null $log Log sink; defaults to error_log().
     *        Injectable so tests can capture the server-side record.
     */
    public static function respond(\Throwable $e, ?callable $log = null): Response
    {
        $correlationId = 'req_' . bin2hex(random_bytes(8));

        $record = json_encode([
            'level' => 'critical',
            'event' => 'public_error_boundary',
            'correlation_id' => $correlationId,
            'exception' => $e::class,
            'message' => $e->getMessage(),
            'file' => $e->getFile() . ':' . $e->getLine(),
            'trace' => array_slice(explode("\n", $e->getTraceAsString()), 0, 10),
        ], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: 'public_error_boundary encode failure ' . $correlationId;

        ($log ?? static function (string $line): void {
            error_log($line);
        })($record);

        $payload = json_encode([
            'jsonapi' => ['version' => '1.1'],
            'errors' => [[
                'status' => '500',
                'title' => 'Internal Server Error',
                'detail' => 'An unexpected error occurred. Reference: ' . $correlationId,
                'id' => $correlationId,
            ]],
        ], JSON_UNESCAPED_SLASHES) ?: '{"errors":[{"status":"500","title":"Internal Server Error"}]}';

        return new Response($payload, 500, [
            'Content-Type' => 'application/vnd.api+json',
            'X-Request-Id' => $correlationId,
            'Cache-Control' => 'no-store',
        ]);
    }
}
