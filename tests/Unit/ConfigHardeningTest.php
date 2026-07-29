<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The parts of config/waaseyaa.php that carry production cookie and
 * proxy posture. The config file is env-driven, so each case loads it
 * fresh under controlled env values.
 */
final class ConfigHardeningTest extends TestCase
{
    /** @var list<string> */
    private array $touched = [];

    protected function tearDown(): void
    {
        foreach ($this->touched as $name) {
            putenv($name);
        }
        $this->touched = [];
    }

    private function env(string $name, string $value): void
    {
        putenv($name . '=' . $value);
        $this->touched[] = $name;
    }

    /**
     * @return array<string, mixed>
     */
    private function load(): array
    {
        /** @var array<string, mixed> */
        return require dirname(__DIR__, 2) . '/config/waaseyaa.php';
    }

    #[Test]
    public function session_cookie_is_forced_secure_in_production(): void
    {
        $this->env('APP_ENV', 'production');

        $config = $this->load();

        self::assertTrue($config['session']['cookie']['secure']);
    }

    #[Test]
    public function session_cookie_uses_https_detection_outside_production(): void
    {
        $this->env('APP_ENV', 'local');

        $config = $this->load();

        self::assertSame('auto', $config['session']['cookie']['secure']);
    }

    #[Test]
    public function trusted_proxies_parse_from_the_environment(): void
    {
        $this->env('WAASEYAA_TRUSTED_PROXIES', ' 172.18.0.0/16 , 10.0.0.5 ');

        $config = $this->load();

        self::assertSame(['172.18.0.0/16', '10.0.0.5'], $config['trusted_proxies']);
    }

    #[Test]
    public function trusted_proxies_default_empty_meaning_no_forwarded_headers_are_honored(): void
    {
        $this->env('WAASEYAA_TRUSTED_PROXIES', '');

        $config = $this->load();

        self::assertSame([], $config['trusted_proxies']);
    }
}
