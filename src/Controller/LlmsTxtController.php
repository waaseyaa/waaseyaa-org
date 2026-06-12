<?php

declare(strict_types=1);

namespace App\Controller;

use App\Docs\SpecCorpus;
use App\Support\SiteUrl;
use Symfony\Component\HttpFoundation\Response;

/**
 * /llms.txt: an INDEX of per-topic Markdown URLs, one line per published
 * spec, per the llms.txt convention. Deliberately no llms-full.txt: agents
 * fetch the per-topic .md pages they need.
 */
final class LlmsTxtController
{
    public function __construct(
        private readonly SpecCorpus $corpus,
        private readonly SiteUrl $urls,
    ) {}

    public function serve(): Response
    {
        $version = $this->corpus->frameworkVersion() ?? 'unknown';

        $lines = [
            '# Waaseyaa',
            '',
            '> Waaseyaa is a sovereignty-first PHP framework for content platforms readable by humans and agents alike. Alpha, in production at First Nations Procurement Inc. This site serves the framework spec corpus three ways: HTML pages, Markdown at the .md URLs below (or via Accept: text/markdown on any docs URL), and a read-only MCP server at ' . $this->urls->to('/mcp') . ' (server card: ' . $this->urls->to('/.well-known/mcp.json') . ').',
            '',
            sprintf('Framework version: %s', $version),
            '',
            '## Docs',
            '',
            sprintf('- [Docs index](%s): all specs, also negotiable as Markdown', $this->urls->to('/docs')),
            '',
            '## Specs',
            '',
        ];

        foreach ($this->corpus->all() as $spec) {
            $description = $this->corpus->description($spec['name']);
            $lines[] = sprintf(
                '- [%s](%s)%s',
                $spec['title'],
                $this->urls->specMarkdown($spec['name']),
                $description !== null ? ': ' . $description : '',
            );
        }

        return new Response(implode("\n", $lines) . "\n", 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]);
    }
}
