<?php

declare(strict_types=1);

namespace App\Mcp;

use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Mcp\McpEndpoint;

/**
 * HTTP adapter for the framework's MCP endpoint: delegates to the real
 * McpEndpoint and converts its McpResponse value object into the Symfony
 * Response the SSR app-controller dispatcher expects. Same upstream gap
 * and same fix as fnpi-waaseyaa (the package route targets a controller
 * whose return type the dispatcher cannot convert).
 */
final readonly class McpEndpointController
{
    public function __construct(
        private McpEndpoint $endpoint,
    ) {}

    public function handle(AccountInterface $account, HttpRequest $request): HttpResponse
    {
        $mcpResponse = $this->endpoint->handle($account, $request);

        return new HttpResponse(
            $mcpResponse->body,
            $mcpResponse->statusCode,
            [
                'Content-Type' => $mcpResponse->contentType,
                'Cache-Control' => 'no-store',
            ],
        );
    }
}
