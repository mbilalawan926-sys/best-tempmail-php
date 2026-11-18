<?php

declare(strict_types=1);

namespace BestTempMail;

/**
 * The result of a connectivity check.
 */
final class Health
{
    public function __construct(
        public readonly string $status,
        public readonly string $version = '',
        public readonly ?\DateTimeImmutable $time = null,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            status: is_string($data['status'] ?? null) ? $data['status'] : '',
            version: is_string($data['version'] ?? null) ? $data['version'] : '',
            time: Timestamps::parse($data['time'] ?? null),
        );
    }

    public function ok(): bool
    {
        return $this->status === 'ok';
    }
}
