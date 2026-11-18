<?php

declare(strict_types=1);

namespace BestTempMail;

/**
 * One link found in a message.
 */
final class Link
{
    public function __construct(
        public readonly string $url,
        /**
         * The real destination when $url points at a click tracker, and null
         * otherwise. Prefer destination() over reading this directly.
         */
        public readonly ?string $unwrappedUrl,
        /** The visible anchor text. */
        public readonly string $text,
        public readonly LinkType $type,
        public readonly int $score,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            url: is_string($data['url'] ?? null) ? $data['url'] : '',
            unwrappedUrl: is_string($data['unwrapped_url'] ?? null) ? $data['unwrapped_url'] : null,
            text: is_string($data['text'] ?? null) ? $data['text'] : '',
            type: LinkType::parse($data['type'] ?? null),
            score: (int) ($data['score'] ?? 0),
        );
    }

    /**
     * The real target: the unwrapped URL where the link goes through a click
     * tracker, otherwise the URL itself.
     */
    public function destination(): string
    {
        return $this->unwrappedUrl ?? $this->url;
    }
}
