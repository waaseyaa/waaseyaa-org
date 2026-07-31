<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Content\ContentReader;
use App\Docs\SpecCorpus;
use App\Mcp\SpecReaderAccount;
use App\Support\SiteUrl;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\AI\Tools\AbstractAgentTool;
use Waaseyaa\AI\Tools\AgentToolResult;
use Waaseyaa\AI\Tools\Attribute\AsAgentTool;

#[AsAgentTool(
    name: 'release_list',
    capability: SpecReaderAccount::CAPABILITY,
    destructive: false,
    dryRunSupported: true,
    category: 'releases',
)]
final class ReleaseListTool extends AbstractAgentTool
{
    public function __construct(
        private readonly ContentReader $reader,
        private readonly SpecCorpus $corpus,
        private readonly SiteUrl $urls,
    ) {
    }

    public function execute(array $arguments, AccountInterface $account): AgentToolResult
    {
        $denied = $this->requireCapability(SpecReaderAccount::CAPABILITY, $account);
        if ($denied !== null) {
            return $denied;
        }

        $releases = [];
        foreach ($this->reader->releases() as $release) {
            $releases[] = [
                'version' => (string) $release->get('version'),
                'released_at' => (string) $release->get('released_at'),
                'summary' => (string) $release->get('summary'),
                'breaking' => (bool) $release->get('breaking'),
                'canonical_url' => $this->urls->to('/releases/' . $release->get('version')),
                'markdown_url' => $this->urls->to('/releases/' . $release->get('version') . '.md'),
            ];
        }

        $payload = [
            'framework_version' => $this->corpus->frameworkVersion(),
            'count' => count($releases),
            'releases' => $releases,
        ];

        return AgentToolResult::success(
            content: [['type' => 'text', 'text' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)]],
            summary: sprintf('%d releases listed', count($releases)),
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
        return 'List every tracked waaseyaa/framework release with date, summary, breaking flag, and canonical URLs (HTML and Markdown).';
    }
}
