<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Content\FrontMatter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FrontMatterTest extends TestCase
{
    #[Test]
    public function parses_meta_and_body(): void
    {
        $raw = "---\ntitle: Hello\nbreaking: true\nweight: 3\n---\n\nBody text.\n";
        $parsed = FrontMatter::parse($raw);

        self::assertSame('Hello', $parsed['meta']['title']);
        self::assertTrue($parsed['meta']['breaking']);
        self::assertSame(3, $parsed['meta']['weight']);
        self::assertSame("Body text.\n", $parsed['body']);
    }

    #[Test]
    public function empty_body_is_allowed(): void
    {
        $parsed = FrontMatter::parse("---\ntitle: X\n---\n");
        self::assertSame('', $parsed['body']);
    }

    #[Test]
    public function missing_opening_delimiter_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FrontMatter::parse("title: X\n");
    }

    #[Test]
    public function unterminated_front_matter_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FrontMatter::parse("---\ntitle: X\n");
    }

    #[Test]
    public function non_map_yaml_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FrontMatter::parse("---\n- just\n- a list\n---\nbody\n");
    }
}
