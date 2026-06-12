<?php

declare(strict_types=1);

namespace App\Controller;

use App\Docs\MarkdownNegotiation;
use App\Docs\SpecCorpus;
use App\Support\SiteUrl;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\Autolink\AutolinkExtension;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\MarkdownConverter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\SSR\SsrServiceProvider;

/**
 * One docs corpus, addressable two ways from this controller: HTML for
 * humans and AI search, verbatim Markdown for agents (Accept header or
 * .md suffix). The third rendering, MCP, is served by the /mcp endpoint
 * from the same corpus.
 */
final class DocsController
{
    private ?MarkdownConverter $converter = null;

    public function __construct(
        private readonly SpecCorpus $corpus,
        private readonly SiteUrl $urls,
    ) {}

    public function index(Request $request): Response
    {
        if (MarkdownNegotiation::wantsMarkdown($request)) {
            return $this->markdownResponse($this->indexMarkdown(), $this->urls->to('/docs'));
        }

        return $this->render('docs-index.html.twig', [
            'specs' => $this->specsWithDescriptions(),
            'framework_version' => $this->corpus->frameworkVersion(),
        ]);
    }

    public function spec(Request $request, string $name): Response
    {
        $explicitMarkdown = str_ends_with($name, '.md');
        if ($explicitMarkdown) {
            $name = substr($name, 0, -3);
        }

        $markdown = $this->corpus->markdown($name);
        if ($markdown === null) {
            return new Response('Spec not found.', 404, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        if ($explicitMarkdown || MarkdownNegotiation::wantsMarkdown($request)) {
            return $this->markdownResponse($markdown, $this->urls->spec($name), $name);
        }

        return $this->render('docs-spec.html.twig', [
            'name' => $name,
            'title' => $this->corpus->title($name) ?? $name,
            'body' => $this->converter()->convert($markdown)->getContent(),
            'markdown_url' => '/docs/specs/' . $name . '.md',
            'canonical_url' => $this->urls->spec($name),
            'framework_version' => $this->corpus->frameworkVersion(),
        ]);
    }

    private function indexMarkdown(): string
    {
        $version = $this->corpus->frameworkVersion() ?? 'unknown';
        $lines = [
            '# Waaseyaa docs',
            '',
            sprintf('Spec corpus of the Waaseyaa framework, version %s.', $version),
            'Each spec is also available as Markdown at its .md URL, and over MCP at /mcp.',
            '',
        ];

        foreach ($this->corpus->all() as $spec) {
            $lines[] = sprintf('- [%s](%s)', $spec['title'], $this->urls->specMarkdown($spec['name']));
        }

        return implode("\n", $lines) . "\n";
    }

    private function markdownResponse(string $markdown, string $canonical, ?string $specName = null): Response
    {
        $headers = [
            'Content-Type' => 'text/markdown; charset=UTF-8',
            'Link' => sprintf('<%s>; rel="canonical"', $canonical),
            'Vary' => 'Accept',
            'X-Waaseyaa-Framework-Version' => $this->corpus->frameworkVersion() ?? 'unknown',
        ];
        if ($specName !== null) {
            $headers['X-Waaseyaa-Spec'] = $specName . '.md';
        }

        return new Response($markdown, 200, $headers);
    }

    /**
     * @return list<array{name: string, title: string, description: ?string}>
     */
    private function specsWithDescriptions(): array
    {
        $out = [];
        foreach ($this->corpus->all() as $spec) {
            $out[] = $spec + ['description' => $this->corpus->description($spec['name'])];
        }

        return $out;
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

        $html = $twig->render($template, $context);

        return new Response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Vary' => 'Accept',
        ]);
    }

    private function converter(): MarkdownConverter
    {
        if ($this->converter !== null) {
            return $this->converter;
        }

        $environment = new Environment([
            'html_input' => 'allow',
            'allow_unsafe_links' => false,
        ]);
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new TableExtension());
        $environment->addExtension(new AutolinkExtension());

        return $this->converter = new MarkdownConverter($environment);
    }
}
