<?php

declare(strict_types=1);

namespace BestTempMail;

/**
 * An extracted verification code.
 */
final class Otp
{
    public function __construct(
        public readonly string $messageId,
        /**
         * Empty when nothing scored highly enough. Check $confidence and
         * $candidates rather than trusting a low-confidence code.
         *
         * A string, not an int, because codes have leading zeros: "004007" is
         * a real one from this API, and 4007 would fail the form.
         */
        public readonly string $code,
        public readonly Confidence $confidence,
        /**
         * The other numbers considered, with their scores. Useful when
         * confidence is low and you want to see what the extractor weighed up.
         *
         * @var list<OtpCandidate>
         */
        public readonly array $candidates = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $candidates = [];
        foreach (is_array($data['candidates'] ?? null) ? $data['candidates'] : [] as $raw) {
            if (is_array($raw)) {
                $candidates[] = OtpCandidate::fromArray($raw);
            }
        }

        return new self(
            messageId: is_string($data['message_id'] ?? null) ? $data['message_id'] : '',
            // The API sends null rather than a guess when nothing scores well
            // enough, so coerce that to an empty string rather than letting a
            // null escape into a typed property.
            code: is_string($data['code'] ?? null) ? $data['code'] : '',
            confidence: Confidence::parse($data['confidence'] ?? null),
            candidates: $candidates,
        );
    }

    public function found(): bool
    {
        return $this->code !== '';
    }
}
