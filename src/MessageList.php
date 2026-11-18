<?php

declare(strict_types=1);

namespace BestTempMail;

/**
 * A page of messages, plus everything needed to interpret it.
 *
 * @implements \IteratorAggregate<int, MessageSummary>
 */
final class MessageList implements \IteratorAggregate, \Countable
{
    public function __construct(
        public readonly string $address,
        /** @var list<MessageSummary> */
        public readonly array $messages,
        public readonly int $limit = 0,
        public readonly int $offset = 0,
        public readonly int $returned = 0,
        public readonly bool $hasMore = false,
        /** How many messages were examined to build this page. */
        public readonly int $scanned = 0,
        /**
         * True when the search stopped at its ceiling with the page still
         * unfilled.
         *
         * This is what makes an empty result interpretable. Empty with this
         * false means nothing in the inbox matches, definitively. Empty with it
         * true means the match may exist further back, so narrow the filter or
         * set a since bound rather than paging blindly.
         */
        public readonly bool $scanLimitReached = false,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $messages = [];
        foreach (is_array($data['messages'] ?? null) ? $data['messages'] : [] as $raw) {
            if (is_array($raw)) {
                $messages[] = MessageSummary::fromArray($raw);
            }
        }

        return new self(
            address: is_string($data['address'] ?? null) ? $data['address'] : '',
            messages: $messages,
            limit: (int) ($data['limit'] ?? 0),
            offset: (int) ($data['offset'] ?? 0),
            returned: (int) ($data['returned'] ?? count($messages)),
            hasMore: (bool) ($data['has_more'] ?? false),
            scanned: (int) ($data['scanned'] ?? 0),
            scanLimitReached: (bool) ($data['scan_limit_reached'] ?? false),
        );
    }

    public function first(): ?MessageSummary
    {
        return $this->messages[0] ?? null;
    }

    public function isEmpty(): bool
    {
        return $this->messages === [];
    }

    /**
     * True when an empty result is a definite answer rather than an exhausted
     * search.
     */
    public function isConclusivelyEmpty(): bool
    {
        return $this->isEmpty() && !$this->scanLimitReached;
    }

    public function count(): int
    {
        return count($this->messages);
    }

    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->messages);
    }
}
