<?php

declare(strict_types=1);

namespace BestTempMail;

/**
 * The caller's plan, remaining quota and subscription.
 *
 * This endpoint works without a key, which is the point of it. A keyless call
 * reports the free tier including $inboxesCreatedToday against the 3-per-day
 * cap, so you can see where you stand before deciding to pay for anything.
 */
final class Usage
{
    public function __construct(
        public readonly string $plan,
        public readonly bool $paid,
        public readonly Hourly $hourly,
        /** Set for keyed callers only. */
        public readonly int $lifetimeRequests = 0,
        /** Null for a keyless caller. */
        public readonly ?KeyInfo $key = null,
        /** Null for a keyless caller. */
        public readonly ?Subscription $subscription = null,
        /**
         * Set for keyless callers only, counting against the free tier's 3 per
         * day per IP. Null on a paid plan, which has no daily cap.
         *
         * Null and zero mean different things here: null is "no daily cap
         * applies", zero is "the cap applies and none have been used".
         */
        public readonly ?int $inboxesCreatedToday = null,
        /** A link to pricing, present for keyless callers. */
        public readonly ?string $upgradeUrl = null,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $hourly = is_array($data['hourly'] ?? null) ? $data['hourly'] : [];
        $key = is_array($data['key'] ?? null) ? $data['key'] : null;
        $subscription = is_array($data['subscription'] ?? null) ? $data['subscription'] : null;

        return new self(
            plan: is_string($data['plan'] ?? null) ? $data['plan'] : 'free',
            paid: (bool) ($data['paid'] ?? false),
            hourly: Hourly::fromArray($hourly),
            lifetimeRequests: (int) ($data['lifetime_requests'] ?? 0),
            key: $key === null ? null : KeyInfo::fromArray($key),
            subscription: $subscription === null ? null : Subscription::fromArray($subscription),
            inboxesCreatedToday: isset($data['inboxes_created_today'])
                ? (int) $data['inboxes_created_today']
                : null,
            upgradeUrl: is_string($data['upgrade'] ?? null) ? $data['upgrade'] : null,
        );
    }
}
