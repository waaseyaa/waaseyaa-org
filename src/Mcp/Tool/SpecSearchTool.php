<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Docs\SpecCorpus;
use App\Docs\SpecSearch;
use App\Mcp\SpecReaderAccount;
use App\Support\SiteUrl;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\AI\Tools\AbstractAgentTool;
use Waaseyaa\AI\Tools\AgentToolResult;
use Waaseyaa\AI\Tools\Attribute\AsAgentTool;

#[AsAgentTool(
    name: 'spec_search',
    capability: SpecReaderAccount::CAPABILITY,
    destructive: false,
    dryRunSupported: true,
    category: 'docs',
)]
final class SpecSearchTool extends AbstractAgentTool
{
    public function __construct(
        private readonly SpecCorpus $corpus,
        private readonly SpecSearch $search,
        private readonly SiteUrl $urls,
    ) {}

    public function execute(array $arguments, AccountInterface $account): AgentToolResult
    {
        $denied = $this->requireCapability(SpecReaderAccount::CAPABILITY, $account);
        if ($denied !== null) {
            return $denied;
        }

        $query = $arguments['query'] ?? null;
        if (!is_string($query) || trim($query) === '') {
            return AgentToolResult::error('Missing required argument: query', 'invalid arguments');
        }

        $limit = $arguments['limit'] ?? SpecSearch::DEFAULT_LIMIT;
        if (!is_int($limit)) {
            $limit = SpecSearch::DEFAULT_LIMIT;
        }

        $matches = [];
        foreach ($this->search->search($query, $limit) as $match) {
            $matches[] = $match + [
                'canonical_url' => $this->urls->spec($match['spec']),
                'markdown_url' => $this->urls->specMarkdown($match['spec']),
            ];
        }

        $payload = [
            'query' => $query,
            'framework_version' => $this->corpus->frameworkVersion(),
            'matches' => $matches,
        ];

        return AgentToolResult::success(
            content: [['type' => 'text', 'text' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)]],
            summary: sprintf('%d matches for "%s"', count($matches), $query),
        );
    }

    public function dryRun(array $arguments, AccountInterface $account): AgentToolResult
    {
        return $this->execute($arguments, $account);
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'minLength' => 1,
                    'description' => 'Case-insensitive substring to find across the spec corpus.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => SpecSearch::MAX_LIMIT,
                    'default' => SpecSearch::DEFAULT_LIMIT,
                ],
            ],
            'required' => ['query'],
            'additionalProperties' => false,
        ];
    }

    public function description(): string
    {
        return 'Search the Waaseyaa framework spec corpus. Returns matching lines with section, line number, and canonical URLs.';
    }
}
