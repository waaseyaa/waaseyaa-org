<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Content\ContentReader;
use App\Mcp\SpecReaderAccount;
use App\Support\SiteUrl;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\AI\Tools\AbstractAgentTool;
use Waaseyaa\AI\Tools\AgentToolResult;
use Waaseyaa\AI\Tools\Attribute\AsAgentTool;

#[AsAgentTool(
    name: 'roadmap_read',
    capability: SpecReaderAccount::CAPABILITY,
    destructive: false,
    dryRunSupported: true,
    category: 'roadmap',
)]
final class RoadmapReadTool extends AbstractAgentTool
{
    public function __construct(
        private readonly ContentReader $reader,
        private readonly SiteUrl $urls,
    ) {
    }

    public function execute(array $arguments, AccountInterface $account): AgentToolResult
    {
        $denied = $this->requireCapability(SpecReaderAccount::CAPABILITY, $account);
        if ($denied !== null) {
            return $denied;
        }

        $horizons = [];
        foreach ($this->reader->roadmap() as $horizon => $items) {
            $horizons[$horizon] = array_map(fn ($item) => [
                'title' => (string) $item->get('title'),
                'status_note' => (string) $item->get('status_note'),
                'related_specs' => array_values(array_filter(array_map('trim', explode(',', (string) $item->get('related_specs'))))),
            ], $items);
        }

        $payload = ['canonical_url' => $this->urls->to('/roadmap'), 'horizons' => $horizons];

        return AgentToolResult::success(
            content: [['type' => 'text', 'text' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)]],
            summary: 'Roadmap read',
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
        return 'Read the Waaseyaa roadmap grouped by stage-based horizon (now, next, later) with per-item status notes and related specs.';
    }
}
