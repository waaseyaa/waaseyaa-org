<?php

declare(strict_types=1);

namespace App\Mcp;

use Waaseyaa\Access\AuthorizationPrincipalInterface;

/**
 * The anonymous identity behind the public MCP endpoint. Grants exactly
 * one capability: reading the published spec corpus. Anything else a
 * tool asks for is denied, so even a misregistered tool fails closed.
 *
 * Implements AuthorizationPrincipalInterface (not just AccountInterface)
 * because McpEndpoint routes every request through
 * DecisionAccountResolver, which fails closed on plain accounts.
 */
final class SpecReaderAccount implements AuthorizationPrincipalInterface
{
    public const CAPABILITY = 'site.specs.read';

    public function id(): string
    {
        return 'public-spec-reader';
    }

    public function claimsGeneration(): string
    {
        return 'public-spec-reader';
    }

    public function tenantId(): ?string
    {
        return null;
    }

    public function communityId(): ?string
    {
        return null;
    }

    public function hasPermission(string $permission): bool
    {
        return $permission === self::CAPABILITY;
    }

    public function getRoles(): array
    {
        return [];
    }

    public function isAuthenticated(): bool
    {
        return false;
    }
}
