<?php

declare(strict_types=1);

namespace App\Controller;

use App\Health\ReadinessCheck;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * /healthz: readiness for the deploy gate and uptime monitoring.
 * Reaching this controller at all proves HTTP routing; the checks
 * cover storage, corpus, index freshness and required configuration.
 * The body carries check names and pass/fail only.
 */
final class HealthController
{
    public function __construct(
        private readonly ReadinessCheck $readiness,
    ) {}

    public function healthz(): Response
    {
        $result = $this->readiness->run();

        return new JsonResponse(
            $result,
            $result['status'] === 'ok' ? 200 : 503,
            ['Cache-Control' => 'no-store'],
        );
    }
}
