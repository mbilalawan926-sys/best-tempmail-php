<?php

declare(strict_types=1);

namespace BestTempMail;

/**
 * One scored candidate code.
 */
final class OtpCandidate
{
    public function __construct(
        public readonly string $code,
        public readonly int $score,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            code: is_string($data['code'] ?? null) ? $data['code'] : '',
            score: (int) ($data['score'] ?? 0),
        );
    }
}
