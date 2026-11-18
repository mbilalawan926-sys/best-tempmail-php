<?php

declare(strict_types=1);

namespace BestTempMail;

/**
 * The request quota for the current hour.
 */
final class Hourly
{
    public function __construct(
        public readonly int $limit,
        public readonly int $used,
        public readonly int $remaining,
        public readonly ?\DateTimeImmutable $resetsAt = null,
        public readonly int $resetsInSeconds = 0,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            limit: (int) ($data['limit'] ?? 0),
            used: (int) ($data['used'] ?? 0),
            remaining: (int) ($data['remaining'] ?? 0),
            resetsAt: Timestamps::parse($data['resets_at'] ?? null),
            resetsInSeconds: (int) ($data['resets_in_seconds'] ?? 0),
        );
    }

    /**
     * Whether fewer than $threshold requests remain, for slowing down before a
     * 429 rather than handling one afterwards.
     */
    public function nearlyExhausted(int $threshold = 50): bool
    {
        return $this->remaining < $threshold;
    }
}
