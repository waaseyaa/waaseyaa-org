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
 * /roadmap: stage-based horizons (now / next / later), served from
 * roadmap_item entities. Same three-rendering contract as the docs pages:
 * HTML, Markdown on the same URL, and the MCP roadmap_read tool reads the
 * same entities.
 */
final class RoadmapController
{
    private const HORIZONS = ['now' => 'Now', 'next' => 'Next', 'later' => 'Later'];

    public function __construct(
        private readonly ContentReader $reader,
        private readonly SpecCorpus $corpus,
        private readonly SiteUrl $urls,
    ) {
    }

    public function page(Request $request): Response
    {
        $grouped = $this->reader->roadmap();

        if (MarkdownNegotiation::wantsMarkdown($request)) {
            $lines = ['# Waaseyaa roadmap', ''];
            foreach (self::HORIZONS as $horizon => $label) {
                $lines[] = '## ' . $label;
                $lines[] = '';
                if ($grouped[$horizon] === []) {
                    $lines[] = 'Nothing in this horizon.';
                } else {
                    foreach ($grouped[$horizon] as $item) {
                        $lines[] = sprintf('- %s: %s', $item->get('title'), $item->get('status_note'));
                    }
                }
                $lines[] = '';
            }

            return $this->markdownResponse(implode("\n", $lines) . "\n", $this->urls->to('/roadmap'));
        }

        return $this->render('roadmap.html.twig', [
            'roadmap' => [
                'now' => array_map($this->view(...), $grouped['now']),
                'next' => array_map($this->view(...), $grouped['next']),
                'later' => array_map($this->view(...), $grouped['later']),
            ],
            'framework_version' => $this->corpus->frameworkVersion(),
            'canonical_base' => $this->urls->base(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function view(EntityInterface $item): array
    {
        $specs = array_values(array_filter(array_map(
            static fn (string $spec): string => trim($spec),
            explode(',', (string) $item->get('related_specs')),
        ), static fn (string $spec): bool => $spec !== ''));

        return [
            'title' => (string) $item->get('title'),
            'status_note' => (string) $item->get('status_note'),
            'body_html' => Markdown::toHtml((string) $item->get('body')),
            'specs' => $specs,
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
