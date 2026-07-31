<?php

declare(strict_types=1);

namespace App\Support;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\Autolink\AutolinkExtension;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\MarkdownConverter;

/**
 * The one CommonMark configuration this site renders with (docs pages
 * and content pages alike).
 */
final class Markdown
{
    private static ?MarkdownConverter $converter = null;

    public static function toHtml(string $markdown): string
    {
        if (self::$converter === null) {
            $environment = new Environment([
                'html_input' => 'allow',
                'allow_unsafe_links' => false,
            ]);
            $environment->addExtension(new CommonMarkCoreExtension());
            $environment->addExtension(new TableExtension());
            $environment->addExtension(new AutolinkExtension());
            self::$converter = new MarkdownConverter($environment);
        }

        return self::$converter->convert($markdown)->getContent();
    }
}
