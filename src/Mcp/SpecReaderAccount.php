<?php

declare(strict_types=1);

namespace App\Mcp;

use Waaseyaa\Access\AccountInterface;

/**
 * The anonymous identity behind the public MCP endpoint. Grants exactly
 * one capability: reading the published spec corpus. Anything else a
 * tool asks for is denied, so even a misregistered tool fails closed.
 */
final class SpecReaderAccount implements AccountInterface
{
    public const CAPABILITY = 'site.specs.read';

    public function id(): int|string
    {
        return 'public-spec-reader';
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
