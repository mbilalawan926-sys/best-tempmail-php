<?php

declare(strict_types=1);

namespace BestTempMail;

/**
 * Asks for several inboxes at once, for a test suite that runs in parallel and
 * wants an address per worker.
 */
final class BulkCreateOptions
{
    public const MAX_COUNT = 50;

    public function __construct(
        /** Required, from 1 to MAX_COUNT. */
        public readonly int $count,
        /**
         * Put the whole batch on one domain. Leaving it null spreads them
         * across the available domains, which is usually preferable: fifty
         * signups from a single domain inside a minute looks like one bulk
         * sender to whatever is being signed up to.
         */
        public readonly ?string $domain = null,
        /** Applies to every inbox in the batch, capped by the plan. */
        public readonly ?int $lifetimeMinutes = null,
    ) {
        if ($count < 1 || $count > self::MAX_COUNT) {
            throw new \InvalidArgumentException(sprintf(
                'best-tempmail: count must be between 1 and %d, got %d',
                self::MAX_COUNT,
                $count,
            ));
        }
    }

    /** @return array<string, mixed> */
    public function toBody(): array
    {
        // A username cannot be set here: a batch cannot share one prefix.
        $body = ['count' => $this->count];
        if ($this->domain !== null && $this->domain !== '') {
            $body['domain'] = $this->domain;
        }
        if ($this->lifetimeMinutes !== null) {
            $body['lifetime_minutes'] = $this->lifetimeMinutes;
        }

        return $body;
    }
}
