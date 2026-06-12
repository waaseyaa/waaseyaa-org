<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Reads Raspberry Pi telemetry for the homepage status chip.
 *
 * The chip is honest-by-construction: it renders only when a fresh
 * telemetry file exists (written on the Pi by an operator-diagnostics
 * cron). On any other host, or when the file is missing or stale,
 * read() returns null and the chip is not rendered at all.
 *
 * Expected JSON shape: {"uptime_days": int, "temp_c": float, "generated_at": unix-timestamp}
 */
final class PiTelemetry
{
    private const MAX_AGE_SECONDS = 900;

    public function __construct(
        private readonly ?string $filePath = null,
        private readonly ?int $now = null,
    ) {}

    public static function fromEnvironment(): self
    {
        $file = getenv('WAASEYAA_ORG_PI_STATUS_FILE');

        return new self(is_string($file) && $file !== '' ? $file : null);
    }

    /**
     * @return array{uptime_days: int, temp_c: float}|null
     */
    public function read(): ?array
    {
        if ($this->filePath === null || !is_file($this->filePath)) {
            return null;
        }

        $raw = file_get_contents($this->filePath);
        if ($raw === false) {
            return null;
        }

        try {
            $data = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($data)) {
            return null;
        }

        $generatedAt = $data['generated_at'] ?? null;
        $uptimeDays = $data['uptime_days'] ?? null;
        $tempC = $data['temp_c'] ?? null;

        if (!is_int($generatedAt) || !is_numeric($uptimeDays) || !is_numeric($tempC)) {
            return null;
        }

        $now = $this->now ?? time();
        if ($now - $generatedAt > self::MAX_AGE_SECONDS) {
            return null;
        }

        return [
            'uptime_days' => (int) $uptimeDays,
            'temp_c' => round((float) $tempC, 1),
        ];
    }
}
