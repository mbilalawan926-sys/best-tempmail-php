<?php

declare(strict_types=1);

namespace BestTempMail;

/**
 * The current registration plus recent delivery attempts.
 */
final class WebhookStatus
{
    public function __construct(
        public readonly ?WebhookInfo $webhook,
        /** @var list<WebhookDelivery> */
        public readonly array $recentDeliveries = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $webhook = is_array($data['webhook'] ?? null) ? $data['webhook'] : null;

        $deliveries = [];
        foreach (is_array($data['recent_deliveries'] ?? null) ? $data['recent_deliveries'] : [] as $raw) {
            if (is_array($raw)) {
                $deliveries[] = WebhookDelivery::fromArray($raw);
            }
        }

        return new self(
            webhook: $webhook === null ? null : WebhookInfo::fromArray($webhook),
            recentDeliveries: $deliveries,
        );
    }

    public function isRegistered(): bool
    {
        return $this->webhook !== null && $this->webhook->url !== '';
    }
}
