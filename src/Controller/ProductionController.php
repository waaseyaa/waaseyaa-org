<?php

declare(strict_types=1);

namespace App\Controller;

use App\Content\ContentReader;
use App\Docs\MarkdownNegotiation;
use App\Docs\SpecCorpus;
use App\Support\Markdown;
use App\Support\PiTelemetry;
use App\Support\SiteUrl;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\SSR\SsrServiceProvider;

/**
 * /production: where Waaseyaa runs today, served from case_study
 * entities. Same three-rendering contract as the docs pages: HTML,
 * Markdown on the same URL, and (later tasks) the same corpus over MCP.
 * The index also renders a live telemetry passthrough from the
 * Raspberry Pi serving this page, when a fresh reading is available.
 */
final class ProductionController
{
    public function __construct(
        private readonly ContentReader $reader,
        private readonly PiTelemetry $telemetry,
        private readonly SpecCorpus $corpus,
        private readonly SiteUrl $urls,
    ) {
    }

    public function index(Request $request): Response
    {
        $studies = $this->reader->caseStudies();

        if (MarkdownNegotiation::wantsMarkdown($request)) {
            $lines = ['# Waaseyaa in production', ''];
            foreach ($studies as $study) {
                $lines[] = sprintf(
                    '- [%s](%s): %s',
                    $study->get('title'),
                    $this->urls->to('/production/' . $study->get('slug') . '.md'),
                    $study->get('summary'),
                );
            }

            return $this->markdownResponse(implode("\n", $lines) . "\n", $this->urls->to('/production'));
        }

        return $this->render('production.html.twig', [
            'studies' => array_map($this->view(...), $studies),
            'pi' => $this->telemetry->read(),
            'framework_version' => $this->corpus->frameworkVersion(),
            'canonical_base' => $this->urls->base(),
        ]);
    }

    public function show(Request $request, string $slug): Response
    {
        $explicitMarkdown = str_ends_with($slug, '.md');
        if ($explicitMarkdown) {
            $slug = substr($slug, 0, -3);
        }

        $study = $this->reader->caseStudy($slug);
        if ($study === null) {
            return new Response('Case study not found.', 404, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        if ($explicitMarkdown || MarkdownNegotiation::wantsMarkdown($request)) {
            $markdown = sprintf(
                "# %s\n\n%s\n\n%s",
                $study->get('title'),
                $study->get('summary'),
                $study->get('body'),
            );

            return $this->markdownResponse($markdown, $this->urls->to('/production/' . $slug));
        }

        return $this->render('case-study.html.twig', [
            'study' => $this->view($study),
            'revisions' => $this->reader->revisionCount('case_study', (string) $study->id()),
            'framework_version' => $this->corpus->frameworkVersion(),
            'canonical_base' => $this->urls->base(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function view(EntityInterface $study): array
    {
        return [
            'slug' => (string) $study->get('slug'),
            'title' => (string) $study->get('title'),
            'org' => (string) $study->get('org'),
            'site_url' => (string) $study->get('site_url'),
            'summary' => (string) $study->get('summary'),
            'body_html' => Markdown::toHtml((string) $study->get('body')),
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
