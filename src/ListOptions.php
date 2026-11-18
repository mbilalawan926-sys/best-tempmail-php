<?php

declare(strict_types=1);

namespace BestTempMail;

/**
 * Filters and pages a message listing.
 *
 * Every argument is optional and they combine, so you can ask for the second
 * page of messages from one sender that arrived today and mention a particular
 * word in the body. The filtering happens on the server: a busy inbox never has
 * to be downloaded and sifted locally.
 *
 *     new ListOptions(subject: 'verify', limit: 1)
 *     new ListOptions(q: 'ORD-48812', since: new DateTimeImmutable('-1 day'))
 */
final class ListOptions
{
    public const MAX_LIMIT = 200;
    public const MAX_OFFSET = 5000;

    public function __construct(
        /** Page size, up to MAX_LIMIT. Null means the server default of 100. */
        public readonly ?int $limit = null,
        /** Messages to skip, up to MAX_OFFSET. */
        public readonly ?int $offset = null,
        /** Match the sender, as a case-insensitive substring. */
        public readonly ?string $from = null,
        /** Match the subject, as a case-insensitive substring. */
        public readonly ?string $subject = null,
        /**
         * Full-text search across the sender, the subject and the decoded
         * body. Text inside script and style blocks is ignored, so a tracking
         * script cannot produce a match.
         */
        public readonly ?string $q = null,
        /**
         * Bound the message Date header. A message whose Date cannot be parsed
         * is excluded from a dated search rather than assumed to be in range.
         */
        public readonly ?\DateTimeInterface $since = null,
        public readonly ?\DateTimeInterface $until = null,
        /**
         * Filter on whether a message has attachments. Null means do not
         * filter, which is a different thing from false.
         */
        public readonly ?bool $hasAttachments = null,
    ) {
        if ($limit !== null && ($limit < 1 || $limit > self::MAX_LIMIT)) {
            throw new \InvalidArgumentException(sprintf(
                'best-tempmail: limit must be between 1 and %d, got %d',
                self::MAX_LIMIT,
                $limit,
            ));
        }
        if ($offset !== null && ($offset < 0 || $offset > self::MAX_OFFSET)) {
            throw new \InvalidArgumentException(sprintf(
                'best-tempmail: offset must be between 0 and %d, got %d',
                self::MAX_OFFSET,
                $offset,
            ));
        }
    }

    /**
     * @return array<string, string>
     */
    public function toQuery(): array
    {
        $query = [];
        if ($this->limit !== null) {
            $query['limit'] = (string) $this->limit;
        }
        if ($this->offset !== null) {
            $query['offset'] = (string) $this->offset;
        }
        if ($this->from !== null && $this->from !== '') {
            $query['from'] = $this->from;
        }
        if ($this->subject !== null && $this->subject !== '') {
            $query['subject'] = $this->subject;
        }
        if ($this->q !== null && $this->q !== '') {
            $query['q'] = $this->q;
        }
        if ($this->since !== null) {
            $query['since'] = Timestamps::toQuery($this->since);
        }
        if ($this->until !== null) {
            $query['until'] = Timestamps::toQuery($this->until);
        }
        if ($this->hasAttachments !== null) {
            $query['has_attachments'] = $this->hasAttachments ? 'true' : 'false';
        }

        return $query;
    }

    /**
     * Whether these options narrow the result at all, as opposed to only
     * paging it.
     *
     * The wait helpers need to know, because the long-poll endpoint cannot
     * filter.
     */
    public function isFiltering(): bool
    {
        return ($this->from !== null && $this->from !== '')
            || ($this->subject !== null && $this->subject !== '')
            || ($this->q !== null && $this->q !== '')
            || $this->since !== null
            || $this->until !== null
            || $this->hasAttachments !== null;
    }
}
