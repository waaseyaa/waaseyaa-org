<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controller\HomeController;
use App\Provider\AppServiceProvider;
use App\Support\PiTelemetry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Routing\WaaseyaaRouter;
use Waaseyaa\SSR\SsrServiceProvider;

final class HomepageTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $provider = new SsrServiceProvider();
        $provider->setKernelContext(dirname(__DIR__, 2), [], []);
        $provider->boot();
    }

    #[Test]
    public function app_provider_registers_the_home_route(): void
    {
        $router = new WaaseyaaRouter();
        new AppServiceProvider()->routes($router);

        $this->assertSame('home', $router->match('/')['_route'] ?? null);
    }

    #[Test]
    public function homepage_renders_the_hero_and_install_block(): void
    {
        $response = new HomeController(new PiTelemetry(null))->index();
        $html = (string) $response->getContent();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Sovereign content platforms', $html);
        $this->assertStringContainsString('composer create-project waaseyaa/waaseyaa', $html);
        $this->assertStringContainsString('bimaaji:install', $html);
        $this->assertStringContainsString('OCAP audit log in core', $html);
        $this->assertStringNotContainsString('{%', $html, 'No raw Twig tags in output');
    }

    #[Test]
    public function homepage_content_is_served_without_javascript(): void
    {
        $response = new HomeController(new PiTelemetry(null))->index();
        $html = (string) $response->getContent();

        // Strip every script block: all page content must survive.
        $withoutScripts = (string) preg_replace('#<script\b[^>]*>.*?</script>#si', '', $html);

        $this->assertStringContainsString('Sovereign content platforms', $withoutScripts);
        $this->assertStringContainsString('Entity system', $withoutScripts);
        $this->assertStringContainsString('Two-axis storage', $withoutScripts);
    }

    #[Test]
    public function homepage_states_the_alpha_stage_plainly(): void
    {
        $response = new HomeController(new PiTelemetry(null))->index();
        $html = (string) $response->getContent();

        $this->assertStringContainsString('alpha', $html);
        $this->assertStringContainsString('in production at First Nations Procurement', $html);
    }

    #[Test]
    public function pi_chip_is_hidden_without_telemetry(): void
    {
        $response = new HomeController(new PiTelemetry(null))->index();
        $html = (string) $response->getContent();

        $this->assertStringNotContainsString('served from a raspberry pi', $html);
    }

    #[Test]
    public function pi_chip_renders_with_fresh_telemetry(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'pi_status_');
        file_put_contents($file, json_encode([
            'uptime_days' => 41,
            'temp_c' => 47.2,
            'generated_at' => time(),
        ]));

        try {
            $response = new HomeController(new PiTelemetry($file))->index();
            $html = (string) $response->getContent();

            $this->assertStringContainsString('served from a raspberry pi', $html);
            $this->assertStringContainsString('up 41d', $html);
            $this->assertStringContainsString('47.2', $html);
        } finally {
            @unlink($file);
        }
    }
}
