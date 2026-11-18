<?php

declare(strict_types=1);

namespace BestTempMail;

/**
 * The registered webhook.
 */
final class WebhookInfo
{
    public function __construct(
        public readonly string $url,
        public readonly string $status,
        /**
         * Drives automatic disabling. Failed deliveries are retried three times
         * with growing gaps, and after repeated failures across many messages
         * the webhook is disabled. Registering again re-enables it.
         */
        public readonly int $consecutiveFailures = 0,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            url: is_string($data['url'] ?? null) ? $data['url'] : '',
            status: is_string($data['status'] ?? null) ? $data['status'] : '',
            consecutiveFailures: (int) ($data['consecutive_failures'] ?? 0),
        );
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
