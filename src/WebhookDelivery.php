<?php

declare(strict_types=1);

namespace BestTempMail;

/**
 * One delivery attempt, for debugging your own endpoint without having to ask
 * what the server saw.
 */
final class WebhookDelivery
{
    public function __construct(
        public readonly int $attempt,
        public readonly ?int $statusCode = null,
        public readonly ?string $error = null,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            attempt: (int) ($data['attempt'] ?? 0),
            statusCode: isset($data['status_code']) ? (int) $data['status_code'] : null,
            error: is_string($data['error'] ?? null) ? $data['error'] : null,
        );
    }

    public function succeeded(): bool
    {
        return $this->statusCode !== null && $this->statusCode >= 200 && $this->statusCode < 300;
    }
}
