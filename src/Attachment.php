<?php

declare(strict_types=1);

namespace BestTempMail;

/**
 * An attachment's metadata, as it appears on a Message.
 *
 * Metadata is visible on every plan. Downloading the bytes needs Pro, and
 * $downloadable says whether the current key may, so a caller can check rather
 * than discovering the limit from a 402.
 *
 * These field names were verified against a real message rather than taken
 * from the docs, which only ever show an empty list. An earlier guess had
 * "content_type" where the API actually sends "mime", and missed index,
 * downloadable and requires_plan entirely.
 */
final class Attachment
{
    public function __construct(
        /**
         * What Client::attachment() takes. Prefer it over the position in the
         * array: the two agree today, but this number is the server's.
         */
        public readonly int $index,
        public readonly string $filename,
        /** The content type, such as "application/pdf". */
        public readonly string $mime,
        /** Size in bytes. */
        public readonly int $size,
        /** Whether the current plan may fetch the bytes. */
        public readonly bool $downloadable = false,
        /** The plan needed when $downloadable is false, such as "pro". */
        public readonly ?string $requiresPlan = null,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            index: (int) ($data['index'] ?? 0),
            filename: is_string($data['filename'] ?? null) ? $data['filename'] : '',
            mime: is_string($data['mime'] ?? null) ? $data['mime'] : 'application/octet-stream',
            size: (int) ($data['size'] ?? 0),
            downloadable: (bool) ($data['downloadable'] ?? false),
            requiresPlan: is_string($data['requires_plan'] ?? null) ? $data['requires_plan'] : null,
        );
    }
}
