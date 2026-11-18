<?php

declare(strict_types=1);

namespace BestTempMail;

/**
 * The result of registering a webhook URL.
 */
final class WebhookRegistration
{
    public function __construct(
        public readonly string $url,
        /**
         * Signs every delivery, and is shown exactly once. Store it now.
         *
         * Registering again replaces the URL and issues a new secret, which is
         * how you rotate it.
         */
        public readonly string $secret,
        public readonly ?string $note = null,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            url: is_string($data['url'] ?? null) ? $data['url'] : '',
            secret: is_string($data['secret'] ?? null) ? $data['secret'] : '',
            note: is_string($data['note'] ?? null) ? $data['note'] : null,
        );
    }
}
