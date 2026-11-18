<?php

declare(strict_types=1);

namespace BestTempMail;

/**
 * Service-wide statistics and the current domain list.
 */
final class Stats
{
    public function __construct(
        public readonly int $activeInboxes,
        public readonly int $totalInboxesCreated,
        /** @var list<string> */
        public readonly array $domains,
        public readonly string $apiVersion = '',
        public readonly string $status = '',
        public readonly ?\DateTimeImmutable $time = null,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $domains = [];
        foreach (is_array($data['domains'] ?? null) ? $data['domains'] : [] as $domain) {
            if (is_string($domain)) {
                $domains[] = $domain;
            }
        }

        return new self(
            activeInboxes: (int) ($data['active_inboxes'] ?? 0),
            totalInboxesCreated: (int) ($data['total_inboxes_created'] ?? 0),
            domains: $domains,
            apiVersion: is_string($data['api_version'] ?? null) ? $data['api_version'] : '',
            status: is_string($data['status'] ?? null) ? $data['status'] : '',
            time: Timestamps::parse($data['time'] ?? null),
        );
    }
}
