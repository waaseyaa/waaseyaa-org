<?php

declare(strict_types=1);

namespace App\Chat;

/**
 * Abuse and cost bounds for the public docs chat. Every number is
 * env-tunable without a deploy; the defaults are sized for a public
 * single-Pi deployment where the model spend is the scarce resource.
 *
 * What is stored, and for how long: transcripts (question, answer,
 * cited sources) keyed by a random visitor token, and rate counters
 * keyed by that token plus an HMAC of the client IP. Raw IP addresses
 * are never written. Both expire: transcripts after $retentionDays,
 * counters when their window lapses.
 */
final readonly class ChatLimits
{
    public function __construct(
        public int $maxRequestBytes = 4096,
        public int $perVisitorPerMinute = 6,
        public int $perVisitorPerDay = 60,
        public int $perIpPerMinute = 12,
        public int $perIpPerDay = 120,
        public int $maxConcurrentModelStreams = 3,
        public int $modelRequestsPerDay = 500,
        public int $modelStreamBudgetSeconds = 45,
        public int $breakerFailureThreshold = 3,
        public int $breakerCooldownSeconds = 300,
        public int $retentionDays = 30,
        public int $visitorCookieDays = 30,
    ) {
    }

    public static function fromEnvironment(): self
    {
        $int = static function (string $name, int $default): int {
            $raw = getenv($name);

            return is_string($raw) && ctype_digit($raw) && (int) $raw > 0 ? (int) $raw : $default;
        };

        return new self(
            maxRequestBytes: $int('WAASEYAA_CHAT_MAX_REQUEST_BYTES', 4096),
            perVisitorPerMinute: $int('WAASEYAA_CHAT_PER_VISITOR_PER_MINUTE', 6),
            perVisitorPerDay: $int('WAASEYAA_CHAT_PER_VISITOR_PER_DAY', 60),
            perIpPerMinute: $int('WAASEYAA_CHAT_PER_IP_PER_MINUTE', 12),
            perIpPerDay: $int('WAASEYAA_CHAT_PER_IP_PER_DAY', 120),
            maxConcurrentModelStreams: $int('WAASEYAA_CHAT_MAX_CONCURRENT_STREAMS', 3),
            modelRequestsPerDay: $int('WAASEYAA_CHAT_MODEL_REQUESTS_PER_DAY', 500),
            modelStreamBudgetSeconds: $int('WAASEYAA_CHAT_STREAM_BUDGET_SECONDS', 45),
            breakerFailureThreshold: $int('WAASEYAA_CHAT_BREAKER_THRESHOLD', 3),
            breakerCooldownSeconds: $int('WAASEYAA_CHAT_BREAKER_COOLDOWN_SECONDS', 300),
            retentionDays: $int('WAASEYAA_CHAT_RETENTION_DAYS', 30),
            visitorCookieDays: $int('WAASEYAA_CHAT_COOKIE_DAYS', 30),
        );
    }
}
