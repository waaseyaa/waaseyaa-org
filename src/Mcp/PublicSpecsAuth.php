<?php

declare(strict_types=1);

namespace App\Mcp;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Mcp\Auth\McpAuthInterface;

/**
 * Public, unauthenticated MCP access. Every request resolves to the
 * SpecReaderAccount, whose only capability is reading the published
 * spec corpus. There is nothing to protect on this endpoint and nothing
 * a caller can mutate through it.
 */
final class PublicSpecsAuth implements McpAuthInterface
{
    public function authenticate(?string $authorizationHeader): ?AccountInterface
    {
        return new SpecReaderAccount();
    }
}
