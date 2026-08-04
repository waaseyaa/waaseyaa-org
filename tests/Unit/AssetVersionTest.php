<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AssetVersionTest extends TestCase
{
    #[Test]
    public function stylesheet_url_uses_the_css_content_hash(): void
    {
        $root = dirname(__DIR__, 2);
        $css = file_get_contents($root.'/public/css/site.css');
        $template = file_get_contents($root.'/templates/base.html.twig');

        self::assertIsString($css);
        self::assertIsString($template);

        $version = substr(sha1($css), 0, 12);
        self::assertStringContainsString('/css/site.css?v='.$version, $template);
    }
}
