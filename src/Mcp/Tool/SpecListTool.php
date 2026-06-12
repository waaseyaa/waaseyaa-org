<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Docs\SpecCorpus;
use App\Mcp\SpecReaderAccount;
use App\Support\SiteUrl;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\AI\Tools\AbstractAgentTool;
use Waaseyaa\AI\Tools\AgentToolResult;
use Waaseyaa\AI\Tools\Attribute\AsAgentTool;

#[AsAgentTool(
    name: 'spec_list',
    capability: SpecReaderAccount::CAPABILITY,
    destructive: false,
    dryRunSupported: true,
    category: 'docs',
)]
final class SpecListTool extends AbstractAgentTool
{
    public function __construct(
        private readonly SpecCorpus $corpus,
        private readonly SiteUrl $urls,
    ) {}

    public function execute(array $arguments, AccountInterface $account): AgentToolResult
    {
        $denied = $this->requireCapability(SpecReaderAccount::CAPABILITY, $account);
        if ($denied !== null) {
            return $denied;
        }

        $specs = [];
        foreach ($this->corpus->all() as $spec) {
            $specs[] = [
                'name' => $spec['name'],
                'title' => $spec['title'],
                'canonical_url' => $this->urls->spec($spec['name']),
                'markdown_url' => $this->urls->specMarkdown($spec['name']),
            ];
        }

        $payload = [
            'framework_version' => $this->corpus->frameworkVersion(),
            'count' => count($specs),
            'specs' => $specs,
        ];

        return AgentToolResult::success(
            content: [['type' => 'text', 'text' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)]],
            summary: sprintf('%d specs listed', count($specs)),
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
            'properties' => new \stdClass(),
            'additionalProperties' => false,
        ];
    }

    public function description(): string
    {
        return 'List every published Waaseyaa framework spec with its title and canonical URLs (HTML and Markdown).';
    }
}
