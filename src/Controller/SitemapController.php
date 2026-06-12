<?php

declare(strict_types=1);

namespace App\Controller;

use App\Docs\SpecCorpus;
use App\Support\SiteUrl;
use Symfony\Component\HttpFoundation\Response;

/**
 * /sitemap.xml: every published page. Part of the agent-readiness
 * checklist alongside llms.txt and the MCP server card.
 */
final class SitemapController
{
    public function __construct(
        private readonly SpecCorpus $corpus,
        private readonly SiteUrl $urls,
    ) {}

    public function serve(): Response
    {
        $paths = ['/', '/why', '/compare', '/docs'];
        foreach ($this->corpus->all() as $spec) {
            $paths[] = '/docs/specs/' . $spec['name'];
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($paths as $path) {
            $xml .= '  <url><loc>' . htmlspecialchars($this->urls->to($path), ENT_XML1) . '</loc></url>' . "\n";
        }
        $xml .= '</urlset>' . "\n";

        return new Response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }
}
