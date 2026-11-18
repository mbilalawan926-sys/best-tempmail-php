<?php

declare(strict_types=1);

namespace BestTempMail;

/**
 * Customises a new inbox. Everything is optional: with no options you get a
 * random address on a random domain with the plan's default lifetime.
 */
final class CreateInboxOptions
{
    public function __construct(
        /**
         * The local part, before the @. Null for a random one.
         *
         * A guessable username is worth a thought. An inbox created with a key
         * is private to that key, so it is safe, but a keyless inbox called
         * something like signup-test is readable by anyone who guesses it.
         */
        public readonly ?string $username = null,
        /**
         * Restrict the inbox to one domain. Fetch the current list with
         * Client::domains() rather than hardcoding, since the list changes.
         */
        public readonly ?string $domain = null,
        /**
         * How long the inbox should live, capped by the plan: 2 hours on Free,
         * 7 days on Founders and Developer, 30 days on Pro.
         *
         * Asking for longer than the plan allows does not fail. The inbox is
         * created at the maximum and Inbox::$note explains that it was clamped.
         */
        public readonly ?int $lifetimeMinutes = null,
    ) {
        if ($lifetimeMinutes !== null && $lifetimeMinutes < 1) {
            throw new \InvalidArgumentException(sprintf(
                'best-tempmail: lifetimeMinutes must be at least 1, got %d',
                $lifetimeMinutes,
            ));
        }
    }

    /** @return array<string, mixed> */
    public function toBody(): array
    {
        $body = [];
        if ($this->username !== null && $this->username !== '') {
            $body['username'] = $this->username;
        }
        if ($this->domain !== null && $this->domain !== '') {
            $body['domain'] = $this->domain;
        }
        if ($this->lifetimeMinutes !== null) {
            $body['lifetime_minutes'] = $this->lifetimeMinutes;
        }

        return $body;
    }
}
