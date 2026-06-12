<?php

declare(strict_types=1);

namespace App\Docs;

use Symfony\Component\HttpFoundation\Request;

/**
 * Content negotiation for the docs corpus: the same URL serves HTML by
 * default and clean Markdown when the client asks for it, either via
 * "Accept: text/markdown" or the explicit .md path suffix.
 */
final class MarkdownNegotiation
{
    public static function wantsMarkdown(Request $request): bool
    {
        $accept = (string) $request->headers->get('Accept', '');

        return stripos($accept, 'text/markdown') !== false
            || stripos($accept, 'text/x-markdown') !== false;
    }
}
