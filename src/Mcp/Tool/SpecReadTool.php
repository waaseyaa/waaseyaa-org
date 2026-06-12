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
    name: 'spec_read',
    capability: SpecReaderAccount::CAPABILITY,
    destructive: false,
    dryRunSupported: true,
    category: 'docs',
)]
final class SpecReadTool extends AbstractAgentTool
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

        $name = $arguments['name'] ?? null;
        if (!is_string($name) || $name === '') {
            return AgentToolResult::error('Missing required argument: name', 'invalid arguments');
        }

        $name = str_ends_with($name, '.md') ? substr($name, 0, -3) : $name;
        $markdown = $this->corpus->markdown($name);
        if ($markdown === null) {
            return AgentToolResult::error(
                sprintf('Unknown spec "%s". Use spec_list for available names.', $name),
                'spec not found',
            );
        }

        $payload = [
            'name' => $name,
            'title' => $this->corpus->title($name) ?? $name,
            'canonical_url' => $this->urls->spec($name),
            'markdown_url' => $this->urls->specMarkdown($name),
            'framework_version' => $this->corpus->frameworkVersion(),
            'markdown' => $markdown,
        ];

        return AgentToolResult::success(
            content: [['type' => 'text', 'text' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)]],
            summary: sprintf('read spec %s', $name),
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
                'name' => [
                    'type' => 'string',
                    'description' => 'Spec name, e.g. "entity-system" (with or without the .md suffix).',
                ],
            ],
            'required' => ['name'],
            'additionalProperties' => false,
        ];
    }

    public function description(): string
    {
        return 'Read the full Markdown of one Waaseyaa framework spec, with its canonical URL and framework version.';
    }
}
