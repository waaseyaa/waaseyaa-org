<?php

declare(strict_types=1);

namespace App\Controller;

use App\Support\FrameworkVersion;
use App\Support\SiteUrl;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\SSR\SsrServiceProvider;

/**
 * The narrative pages (Start, Why Waaseyaa, Compare): server-rendered Twig
 * in the same design system, schema.org JSON-LD in the head blocks. The
 * Start page's code samples are mirrored by tests/Tutorial/TodoAppTest.php.
 */
final class StaticPageController
{
    public function __construct(
        private readonly SiteUrl $urls,
    ) {}

    public function start(): Response
    {
        return $this->render('start.html.twig');
    }

    public function why(): Response
    {
        return $this->render('why.html.twig');
    }

    public function compare(): Response
    {
        return $this->render('compare.html.twig');
    }

    private function render(string $template): Response
    {
        $twig = SsrServiceProvider::getTwigEnvironment();
        if ($twig === null) {
            return new Response('Template engine unavailable.', 500);
        }

        $html = $twig->render($template, [
            'framework_version' => FrameworkVersion::pretty(),
            'canonical_base' => $this->urls->base(),
        ]);

        return new Response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
