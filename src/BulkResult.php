<?php

declare(strict_types=1);

namespace BestTempMail;

/**
 * The outcome of creating several inboxes in one call.
 */
final class BulkResult implements \Countable
{
    public function __construct(
        public readonly int $count,
        /**
         * How many requests of quota the batch cost. Each inbox counts as one,
         * so a batch of 25 charges 25. Reported by the server so you do not
         * have to work it out.
         */
        public readonly int $quotaCharged,
        /** @var list<Inbox> */
        public readonly array $inboxes,
        public readonly ?int $lifetimeMinutes = null,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $inboxes = [];
        foreach (is_array($data['inboxes'] ?? null) ? $data['inboxes'] : [] as $raw) {
            if (is_array($raw)) {
                $inboxes[] = Inbox::fromArray($raw);
            }
        }

        return new self(
            count: (int) ($data['count'] ?? count($inboxes)),
            quotaCharged: (int) ($data['quota_charged'] ?? count($inboxes)),
            inboxes: $inboxes,
            lifetimeMinutes: isset($data['lifetime_minutes']) ? (int) $data['lifetime_minutes'] : null,
        );
    }

    /**
     * Just the addresses, which is usually what a test fixture wants.
     *
     * @return list<string>
     */
    public function addresses(): array
    {
        return array_map(static fn (Inbox $inbox): string => $inbox->address, $this->inboxes);
    }

    public function count(): int
    {
        return count($this->inboxes);
    }
}
