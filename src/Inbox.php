<?php

declare(strict_types=1);

namespace BestTempMail;

/**
 * A newly created inbox.
 */
final class Inbox
{
    public function __construct(
        public readonly string $address,
        public readonly ?\DateTimeImmutable $createdAt = null,
        public readonly ?\DateTimeImmutable $expiresAt = null,
        public readonly ?int $lifetimeMinutes = null,
        /**
         * Set when the server could not honour the request exactly, most often
         * because the requested lifetime exceeded the plan's maximum. The
         * inbox is created at the maximum rather than failing, so this is the
         * only warning that it will expire sooner than asked for.
         */
        public readonly ?string $note = null,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            address: is_string($data['address'] ?? null) ? $data['address'] : '',
            createdAt: Timestamps::parse($data['created_at'] ?? null),
            expiresAt: Timestamps::parse($data['expires_at'] ?? null),
            lifetimeMinutes: isset($data['lifetime_minutes']) ? (int) $data['lifetime_minutes'] : null,
            note: is_string($data['note'] ?? null) ? $data['note'] : null,
        );
    }

    /** The local part, before the @. */
    public function username(): string
    {
        $at = strpos($this->address, '@');

        return $at === false ? $this->address : substr($this->address, 0, $at);
    }

    public function domain(): string
    {
        $at = strpos($this->address, '@');

        return $at === false ? '' : substr($this->address, $at + 1);
    }

    /**
     * A plus-addressed variant of this inbox.
     *
     * Mail to user+anything@domain arrives at user@domain, so one inbox can
     * cover a test that registers with several services:
     *
     *     signUp($inbox->tagged('netflix'));
     *     $client->waitForMessage($inbox->address, new WaitOptions(from: 'netflix'));
     *
     * Note that you wait on the base address, not the tagged one.
     */
    public function tagged(string $tag): string
    {
        return $this->username() . '+' . $tag . '@' . $this->domain();
    }
}
