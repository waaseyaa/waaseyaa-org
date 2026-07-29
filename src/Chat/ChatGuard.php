<?php

declare(strict_types=1);

namespace App\Chat;

use Waaseyaa\Database\DatabaseInterface;

/**
 * Admission control for the public docs chat: fixed-window rate limits
 * per visitor token and per HMAC-hashed client IP, a global daily model
 * budget, a concurrent-stream cap, and a failure circuit breaker.
 *
 * All state lives in two small SQLite tables (see ChatSchema), so the
 * limits hold across php-fpm workers. Raw IP addresses never touch
 * storage: the IP bucket key is a truncated HMAC-SHA-256 whose key is
 * derived from the application secret, so the hash is stable for
 * limiting but not reversible or comparable across deployments.
 *
 * Denials are two-tier by design: request-level limits produce 429s at
 * the controller; model-capacity limits (budget, concurrency, open
 * breaker) degrade to the grounded extractive answerer instead, so the
 * chat stays honest and available while spend is protected.
 */
final class ChatGuard
{
    private const MINUTE = 60;
    private const DAY = 86400;
    private const ACTIVE_STALE_AFTER = 120;

    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly ChatLimits $limits,
        private readonly string $hashKey,
        private readonly ?\Closure $clock = null,
    ) {}

    /**
     * @return array{allowed: bool, retry_after: int}
     */
    public function admit(string $visitor, ?string $clientIp): array
    {
        $now = $this->now();

        $checks = [
            ['v1:' . $visitor, self::MINUTE, $this->limits->perVisitorPerMinute],
            ['vd:' . $visitor, self::DAY, $this->limits->perVisitorPerDay],
        ];
        if ($clientIp !== null && $clientIp !== '') {
            $ip = $this->hashIp($clientIp);
            $checks[] = ['i1:' . $ip, self::MINUTE, $this->limits->perIpPerMinute];
            $checks[] = ['id:' . $ip, self::DAY, $this->limits->perIpPerDay];
        }

        foreach ($checks as [$prefix, $window, $max]) {
            [$count, $expiresAt] = $this->increment($prefix, $window, $now);
            if ($count > $max) {
                return ['allowed' => false, 'retry_after' => max(1, $expiresAt - $now)];
            }
        }

        return ['allowed' => true, 'retry_after' => 0];
    }

    /**
     * Try to reserve model capacity for one streamed answer. A false
     * return means "answer extractively", never "refuse".
     */
    public function acquireModelSlot(): bool
    {
        $now = $this->now();

        if ($this->control('breaker_open_until') > $now) {
            return false;
        }

        [$budget] = $this->increment('gm:model', self::DAY, $now);
        if ($budget > $this->limits->modelRequestsPerDay) {
            return false;
        }

        $touched = $this->control('active_touched');
        $active = $this->control('active_streams');
        if ($touched < $now - self::ACTIVE_STALE_AFTER) {
            // A crashed worker never decremented; do not let a stale
            // counter disable the model path forever.
            $active = 0;
        }
        if ($active >= $this->limits->maxConcurrentModelStreams) {
            return false;
        }

        $this->setControl('active_streams', $active + 1);
        $this->setControl('active_touched', $now);

        return true;
    }

    public function releaseModelSlot(): void
    {
        $active = $this->control('active_streams');
        $this->setControl('active_streams', max(0, $active - 1));
        $this->setControl('active_touched', $this->now());
    }

    public function recordModelFailure(): void
    {
        $failures = $this->control('breaker_failures') + 1;
        if ($failures >= $this->limits->breakerFailureThreshold) {
            $this->setControl('breaker_open_until', $this->now() + $this->limits->breakerCooldownSeconds);
            $this->setControl('breaker_failures', 0);

            return;
        }
        $this->setControl('breaker_failures', $failures);
    }

    public function recordModelSuccess(): void
    {
        $this->setControl('breaker_failures', 0);
    }

    /** Drop lapsed rate windows; called from the retention prune. */
    public function pruneExpired(): void
    {
        $this->db->query(
            'DELETE FROM ' . ChatSchema::LIMITS . ' WHERE expires_at < ?',
            [$this->now()],
        );
    }

    public function hashIp(string $ip): string
    {
        return substr(hash_hmac('sha256', $ip, $this->hashKey), 0, 24);
    }

    /**
     * @return array{0: int, 1: int} count within the window, window expiry (unix)
     */
    private function increment(string $prefix, int $windowSeconds, int $now): array
    {
        $windowId = intdiv($now, $windowSeconds);
        $bucket = $prefix . ':' . $windowSeconds . ':' . $windowId;
        $expiresAt = ($windowId + 1) * $windowSeconds;

        $this->db->query(
            'INSERT INTO ' . ChatSchema::LIMITS . ' (bucket, expires_at, count) VALUES (?, ?, 1)
             ON CONFLICT(bucket) DO UPDATE SET count = count + 1',
            [$bucket, $expiresAt],
        );

        $count = 0;
        foreach ($this->db->query('SELECT count FROM ' . ChatSchema::LIMITS . ' WHERE bucket = ?', [$bucket]) as $row) {
            $count = (int) ($row['count'] ?? 0);
        }

        return [$count, $expiresAt];
    }

    private function control(string $key): int
    {
        foreach ($this->db->query('SELECT value FROM ' . ChatSchema::CONTROL . ' WHERE ctl_key = ?', [$key]) as $row) {
            return (int) ($row['value'] ?? 0);
        }

        return 0;
    }

    private function setControl(string $key, int $value): void
    {
        $this->db->query(
            'INSERT INTO ' . ChatSchema::CONTROL . ' (ctl_key, value) VALUES (?, ?)
             ON CONFLICT(ctl_key) DO UPDATE SET value = excluded.value',
            [$key, $value],
        );
    }

    private function now(): int
    {
        return $this->clock !== null ? ($this->clock)() : time();
    }
}
