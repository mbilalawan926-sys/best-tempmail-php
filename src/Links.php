<?php

declare(strict_types=1);

namespace BestTempMail;

/**
 * Every link in a message, and the one it is asking the reader to click.
 *
 * @implements \IteratorAggregate<int, Link>
 */
final class Links implements \IteratorAggregate, \Countable
{
    public function __construct(
        public readonly string $messageId,
        /**
         * The call to action, or empty when nothing scored highly enough.
         *
         * Rarely the first link in the body: that is almost always the logo.
         */
        public readonly string $actionUrl,
        public readonly Confidence $confidence,
        /** @var list<Link> */
        public readonly array $links = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $links = [];
        foreach (is_array($data['links'] ?? null) ? $data['links'] : [] as $raw) {
            if (is_array($raw)) {
                $links[] = Link::fromArray($raw);
            }
        }

        return new self(
            messageId: is_string($data['message_id'] ?? null) ? $data['message_id'] : '',
            actionUrl: is_string($data['action_url'] ?? null) ? $data['action_url'] : '',
            confidence: Confidence::parse($data['confidence'] ?? null),
            links: $links,
        );
    }

    public function found(): bool
    {
        return $this->actionUrl !== '';
    }

    /**
     * Only the links typed as the call to action, in the order the server
     * scored them. Useful when a message carries more than one and you want to
     * choose rather than take $actionUrl.
     *
     * @return list<Link>
     */
    public function actionLinks(): array
    {
        return array_values(array_filter(
            $this->links,
            static fn (Link $link): bool => $link->type === LinkType::Action,
        ));
    }

    /**
     * Links of a given type, for the cases where the footer is what you are
     * testing: an unsubscribe link that must be present, for example.
     *
     * @return list<Link>
     */
    public function ofType(LinkType $type): array
    {
        return array_values(array_filter(
            $this->links,
            static fn (Link $link): bool => $link->type === $type,
        ));
    }

    public function count(): int
    {
        return count($this->links);
    }

    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->links);
    }
}
