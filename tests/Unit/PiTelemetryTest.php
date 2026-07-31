<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Support\PiTelemetry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PiTelemetryTest extends TestCase
{
    #[Test]
    public function returns_null_without_a_file(): void
    {
        $this->assertNull(new PiTelemetry(null)->read());
        $this->assertNull(new PiTelemetry('/nonexistent/pi.json')->read());
    }

    #[Test]
    public function returns_null_for_stale_telemetry(): void
    {
        $file = $this->telemetryFile(['uptime_days' => 10, 'temp_c' => 50.0, 'generated_at' => 1000]);

        try {
            $this->assertNull(new PiTelemetry($file, now: 1000 + 901)->read());
        } finally {
            @unlink($file);
        }
    }

    #[Test]
    public function returns_null_for_malformed_json(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'pi_');
        file_put_contents($file, '{not json');

        try {
            $this->assertNull(new PiTelemetry($file)->read());
        } finally {
            @unlink($file);
        }
    }

    #[Test]
    public function returns_reading_for_fresh_telemetry(): void
    {
        $file = $this->telemetryFile(['uptime_days' => 41, 'temp_c' => 47.25, 'generated_at' => 5000]);

        try {
            $reading = new PiTelemetry($file, now: 5060)->read();

            $this->assertSame(['uptime_days' => 41, 'temp_c' => 47.3, 'response_ms' => null], $reading);
        } finally {
            @unlink($file);
        }
    }

    #[Test]
    public function response_ms_passes_through_when_present(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'pi');
        file_put_contents($file, json_encode(['uptime_days' => 3, 'temp_c' => 51.2, 'generated_at' => 1000, 'response_ms' => 41.5]));
        $data = new \App\Support\PiTelemetry($file, now: 1100)->read();
        self::assertSame(41.5, $data['response_ms']);
    }

    #[Test]
    public function response_ms_is_null_when_absent(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'pi');
        file_put_contents($file, json_encode(['uptime_days' => 3, 'temp_c' => 51.2, 'generated_at' => 1000]));
        self::assertNull(new \App\Support\PiTelemetry($file, now: 1100)->read()['response_ms']);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function telemetryFile(array $payload): string
    {
        $file = tempnam(sys_get_temp_dir(), 'pi_');
        file_put_contents($file, json_encode($payload, JSON_THROW_ON_ERROR));

        return $file;
    }
}
