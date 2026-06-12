<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Docs\SpecCorpus;
use App\Support\SiteUrl;
use Symfony\Component\HttpFoundation\Response;

/**
 * Server card at /.well-known/mcp.json describing the PUBLIC read-only
 * MCP endpoint. Replaces the framework default card, which advertises
 * bearer authentication; this endpoint requires none.
 */
final readonly class PublicServerCard
{
    public function __construct(
        private SpecCorpus $corpus,
        private SiteUrl $urls,
    ) {}

    public function serve(): Response
    {
        $card = [
            'name' => 'waaseyaa.org',
            'version' => $this->corpus->frameworkVersion() ?? '0.1.0',
            'description' => 'Read-only MCP access to the Waaseyaa framework spec corpus. Tools: spec_list, spec_search, spec_read. No authentication required.',
            'endpoint' => '/mcp',
            'endpoint_url' => $this->urls->to('/mcp'),
            'transport' => 'streamable-http',
            'capabilities' => [
                'tools' => true,
                'resources' => false,
                'prompts' => false,
            ],
            'authentication' => [
                'type' => 'none',
            ],
        ];

        return new Response(
            json_encode($card, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            200,
            ['Content-Type' => 'application/json'],
        );
    }
}
