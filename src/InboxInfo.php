<?php

declare(strict_types=1);

namespace BestTempMail;

/**
 * An existing inbox's metadata.
 */
final class InboxInfo
{
    public function __construct(
        public readonly string $address,
        public readonly ?\DateTimeImmutable $createdAt = null,
        public readonly ?\DateTimeImmutable $expiresAt = null,
        public readonly int $messageCount = 0,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            address: is_string($data['address'] ?? null) ? $data['address'] : '',
            createdAt: Timestamps::parse($data['created_at'] ?? null),
            expiresAt: Timestamps::parse($data['expires_at'] ?? null),
            messageCount: (int) ($data['message_count'] ?? 0),
        );
    }
}
