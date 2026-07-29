<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Structured one-line JSON logging for degradation paths. Lines land on
 * error_log (the container's stderr in production), so every silent
 * fallback leaves an operator-visible record. Never used to build
 * response bodies.
 */
final class OperationalLog
{
    /** @var callable(string): void|null Test seam; null means error_log(). */
    private static $sink = null;

    /** @param array<string, scalar> $context */
    public static function warning(string $event, \Throwable $e, array $context = []): void
    {
        self::write('warning', $event, $e, $context);
    }

    /** @param array<string, scalar> $context */
    public static function error(string $event, \Throwable $e, array $context = []): void
    {
        self::write('error', $event, $e, $context);
    }

    /** @param callable(string): void|null $sink */
    public static function setSink(?callable $sink): void
    {
        self::$sink = $sink;
    }

    /** @param array<string, scalar> $context */
    private static function write(string $level, string $event, \Throwable $e, array $context): void
    {
        $line = json_encode([
            'level' => $level,
            'event' => $event,
            'exception' => $e::class,
            'message' => $e->getMessage(),
            'file' => $e->getFile() . ':' . $e->getLine(),
        ] + $context, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: $event;

        $sink = self::$sink ?? static function (string $l): void {
            error_log($l);
        };
        $sink($line);
    }
}
