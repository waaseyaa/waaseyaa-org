<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Mcp\Tool\SpecListTool;
use App\Mcp\Tool\SpecReadTool;
use App\Mcp\Tool\SpecSearchTool;
use Waaseyaa\AI\Tools\AgentTool;
use Waaseyaa\AI\Tools\AgentToolInterface;
use Waaseyaa\AI\Tools\Attribute\AsAgentTool;
use Waaseyaa\AI\Tools\ToolNotFoundException;
use Waaseyaa\AI\Tools\ToolRegistryInterface;

/**
 * The complete tool surface of the public MCP endpoint: three read-only
 * spec tools, nothing else. Tool metadata is read from each class's own
 * #[AsAgentTool] attribute so it cannot drift from the implementation.
 *
 * Hand-built rather than the framework AttributeToolRegistry for the
 * same reason as fnpi-waaseyaa: on this framework line nothing binds
 * PackageManifest onto the kernel bus, so the shared registry hydrates
 * empty. Here the explicit list is also the security boundary: what is
 * not listed does not exist on the public surface.
 */
final class SpecToolRegistry implements ToolRegistryInterface
{
    /** @var array<string, AgentTool> */
    private array $tools = [];

    private bool $hydrated = false;

    /**
     * @param list<AgentToolInterface> $implementations
     */
    public function __construct(
        private readonly array $implementations,
    ) {}

    /**
     * @return list<class-string<AgentToolInterface>>
     */
    public static function toolClasses(): array
    {
        return [SpecListTool::class, SpecSearchTool::class, SpecReadTool::class];
    }

    public function register(AgentTool $tool): void
    {
        $this->hydrate();
        $this->tools[$tool->name] = $tool;
    }

    public function get(string $name): AgentTool
    {
        $this->hydrate();
        if (!isset($this->tools[$name])) {
            throw ToolNotFoundException::forName($name);
        }

        return $this->tools[$name];
    }

    public function has(string $name): bool
    {
        $this->hydrate();

        return isset($this->tools[$name]);
    }

    public function all(): iterable
    {
        $this->hydrate();

        return array_values($this->tools);
    }

    private function hydrate(): void
    {
        if ($this->hydrated) {
            return;
        }
        $this->hydrated = true;

        foreach ($this->implementations as $impl) {
            $attributes = new \ReflectionClass($impl)->getAttributes(AsAgentTool::class);
            if ($attributes === []) {
                continue;
            }
            /** @var AsAgentTool $meta */
            $meta = $attributes[0]->newInstance();

            $this->tools[$meta->name] = new AgentTool(
                name: $meta->name,
                capability: $meta->capability,
                destructive: $meta->destructive,
                dryRunSupported: $meta->dryRunSupported,
                category: $meta->category,
                inputSchema: $impl->inputSchema(),
                impl: $impl,
            );
        }
    }
}
