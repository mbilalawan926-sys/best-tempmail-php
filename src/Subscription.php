<?php

declare(strict_types=1);

namespace BestTempMail;

/**
 * The paid subscription behind the key.
 */
final class Subscription
{
    public function __construct(
        public readonly string $status,
        public readonly ?\DateTimeImmutable $expiresAt = null,
        public readonly int $daysRemaining = 0,
        public readonly bool $inGracePeriod = false,
        public readonly bool $neverExpires = false,
        public readonly ?\DateTimeImmutable $graceUntil = null,
        public readonly ?string $provider = null,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            status: is_string($data['status'] ?? null) ? $data['status'] : '',
            expiresAt: Timestamps::parse($data['expires_at'] ?? null),
            daysRemaining: (int) ($data['days_remaining'] ?? 0),
            inGracePeriod: (bool) ($data['in_grace_period'] ?? false),
            neverExpires: (bool) ($data['never_expires'] ?? false),
            graceUntil: Timestamps::parse($data['grace_until'] ?? null),
            provider: is_string($data['provider'] ?? null) ? $data['provider'] : null,
        );
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Whether renewal is close enough to be worth a reminder.
     *
     * Note that a cancelled annual subscription keeps working until it
     * expires, so an inactive status does not mean the key has stopped.
     */
    public function expiringWithinDays(int $days = 14): bool
    {
        return !$this->neverExpires && $this->daysRemaining <= $days;
    }
}
