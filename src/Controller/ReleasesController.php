<?php

declare(strict_types=1);

namespace App\Controller;

use App\Content\ContentReader;
use App\Docs\MarkdownNegotiation;
use App\Docs\SpecCorpus;
use App\Support\Markdown;
use App\Support\SiteUrl;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\SSR\SsrServiceProvider;

/**
 * /releases: the changelog, served from release entities. Same
 * three-rendering contract as the docs pages: HTML, Markdown on the
 * same URL, and the MCP release_list tool reads the same entities.
 */
final class ReleasesController
{
    public function __construct(
        private readonly ContentReader $reader,
        private readonly SpecCorpus $corpus,
        private readonly SiteUrl $urls,
    ) {
    }

    public function index(Request $request): Response
    {
        $releases = $this->reader->releases();

        if (MarkdownNegotiation::wantsMarkdown($request)) {
            $lines = ['# Waaseyaa releases', ''];
            foreach ($releases as $release) {
                $lines[] = sprintf(
                    '- [%s](%s) (%s)%s: %s',
                    $release->get('version'),
                    $this->urls->to('/releases/' . $release->get('version') . '.md'),
                    $release->get('released_at'),
                    ((bool) $release->get('breaking')) ? ' [breaking]' : '',
                    $release->get('summary'),
                );
            }

            return $this->markdownResponse(implode("\n", $lines) . "\n", $this->urls->to('/releases'));
        }

        return $this->render('releases-index.html.twig', [
            'releases' => array_map($this->view(...), $releases),
            'framework_version' => $this->corpus->frameworkVersion(),
            'canonical_base' => $this->urls->base(),
        ]);
    }

    public function show(Request $request, string $version): Response
    {
        $explicitMarkdown = str_ends_with($version, '.md');
        if ($explicitMarkdown) {
            $version = substr($version, 0, -3);
        }

        $release = $this->reader->release($version);
        if ($release === null) {
            return new Response('Release not found.', 404, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        if ($explicitMarkdown || MarkdownNegotiation::wantsMarkdown($request)) {
            $markdown = sprintf(
                "# %s\n\nVersion: %s\nReleased: %s\nBreaking changes: %s\n%s\n%s\n\n%s",
                $release->get('title'),
                $release->get('version'),
                $release->get('released_at'),
                ((bool) $release->get('breaking')) ? 'yes' : 'no',
                (string) $release->get('tag_url') !== '' ? 'Tag: ' . $release->get('tag_url') . "\n" : '',
                $release->get('summary'),
                $release->get('body'),
            );

            return $this->markdownResponse($markdown, $this->urls->to('/releases/' . $version));
        }

        return $this->render('release.html.twig', [
            'release' => $this->view($release),
            'revisions' => $this->reader->revisionCount('release', (string) $release->id()),
            'framework_version' => $this->corpus->frameworkVersion(),
            'canonical_base' => $this->urls->base(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function view(EntityInterface $release): array
    {
        return [
            'version' => (string) $release->get('version'),
            'title' => (string) $release->get('title'),
            'released_at' => (string) $release->get('released_at'),
            'summary' => (string) $release->get('summary'),
            'breaking' => (bool) $release->get('breaking'),
            'tag_url' => (string) $release->get('tag_url'),
            'body_html' => Markdown::toHtml((string) $release->get('body')),
        ];
    }

    private function markdownResponse(string $markdown, string $canonical): Response
    {
        return new Response($markdown, 200, [
            'Content-Type' => 'text/markdown; charset=UTF-8',
            'Link' => sprintf('<%s>; rel="canonical"', $canonical),
            'Vary' => 'Accept',
            'X-Waaseyaa-Framework-Version' => $this->corpus->frameworkVersion() ?? 'unknown',
        ]);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function render(string $template, array $context): Response
    {
        $twig = SsrServiceProvider::getTwigEnvironment();
        if ($twig === null) {
            return new Response('Template engine unavailable.', 500);
        }

        return new Response($twig->render($template, $context), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Vary' => 'Accept',
        ]);
    }
}
