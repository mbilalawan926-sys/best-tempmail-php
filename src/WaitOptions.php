<?php

declare(strict_types=1);

namespace BestTempMail;

/**
 * Configures the wait helpers.
 *
 *     new WaitOptions(subject: 'verify', timeout: 45.0)
 */
final class WaitOptions
{
    public const DEFAULT_TIMEOUT = 60.0;

    public function __construct(
        /**
         * Narrow which message counts as the one being waited for, matching
         * the same fields on ListOptions.
         *
         * Setting one of these matters more than it looks. Without a filter the
         * first message to arrive wins, and in a real signup a welcome email
         * often beats the verification email by a second, so an unfiltered wait
         * hands back the wrong message and the test then fails a long way from
         * the cause.
         */
        public readonly ?string $from = null,
        public readonly ?string $subject = null,
        public readonly ?string $q = null,
        /** Total seconds to wait, across however many requests that takes. */
        public readonly float $timeout = self::DEFAULT_TIMEOUT,
    ) {
        if ($timeout <= 0) {
            throw new \InvalidArgumentException(
                sprintf('best-tempmail: timeout must be positive, got %.1f', $timeout),
            );
        }
    }

    public function toListOptions(): ListOptions
    {
        return new ListOptions(
            limit: 1,
            from: $this->from,
            subject: $this->subject,
            q: $this->q,
        );
    }
}
