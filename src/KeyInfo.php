<?php

declare(strict_types=1);

namespace BestTempMail;

/**
 * The API key in use.
 *
 * The key itself is never returned, only its last four characters. That is
 * enough to tell two keys apart in a log without the log becoming somewhere a
 * key leaks from.
 */
final class KeyInfo
{
    public function __construct(
        public readonly int $id,
        public readonly string $lastFour,
        public readonly string $status,
        public readonly ?\DateTimeImmutable $createdAt = null,
        public readonly ?\DateTimeImmutable $lastUsedAt = null,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (int) ($data['id'] ?? 0),
            lastFour: is_string($data['last_four'] ?? null) ? $data['last_four'] : '',
            status: is_string($data['status'] ?? null) ? $data['status'] : '',
            createdAt: Timestamps::parse($data['created_at'] ?? null),
            lastUsedAt: Timestamps::parse($data['last_used_at'] ?? null),
        );
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
