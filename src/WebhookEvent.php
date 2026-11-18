<?php

declare(strict_types=1);

namespace BestTempMail;

/**
 * The payload posted to your webhook URL.
 */
final class WebhookEvent
{
    public function __construct(
        public readonly string $event,
        public readonly int $timestamp,
        public readonly string $address,
        public readonly MessageSummary $message,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $message = is_array($data['message'] ?? null) ? $data['message'] : [];

        return new self(
            event: is_string($data['event'] ?? null) ? $data['event'] : '',
            timestamp: (int) ($data['timestamp'] ?? 0),
            address: is_string($data['address'] ?? null) ? $data['address'] : '',
            message: MessageSummary::fromArray($message),
        );
    }

    public function time(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('@' . $this->timestamp, new \DateTimeZone('UTC'));
    }
}
