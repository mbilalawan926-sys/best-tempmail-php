<?php

declare(strict_types=1);

namespace BestTempMail;

/**
 * A message as it appears in a listing: headers only, no body.
 */
class MessageSummary
{
    public function __construct(
        public readonly string $id,
        public readonly string $from,
        public readonly string $subject,
        /**
         * The message's own Date header, not the time it was received. A
         * message whose Date cannot be parsed is excluded from a dated search
         * rather than assumed to be in range.
         */
        public readonly ?\DateTimeImmutable $date = null,
        public readonly bool $hasAttachments = false,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        return new static(
            id: is_string($data['id'] ?? null) ? $data['id'] : '',
            from: is_string($data['from'] ?? null) ? $data['from'] : '',
            subject: is_string($data['subject'] ?? null) ? $data['subject'] : '',
            date: Timestamps::parse($data['date'] ?? null),
            hasAttachments: (bool) ($data['has_attachments'] ?? false),
        );
    }

    /**
     * The bare email address of the sender, with any display name stripped.
     *
     * The raw From header is usually something like
     * '"Example Service" <no-reply@example.com>', which is awkward to assert
     * against in a test.
     */
    public function fromAddress(): string
    {
        if (preg_match('/<([^>]+)>/', $this->from, $matches) === 1) {
            return trim($matches[1]);
        }

        return trim($this->from);
    }
}
