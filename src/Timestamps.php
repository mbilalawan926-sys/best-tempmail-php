<?php

declare(strict_types=1);

namespace BestTempMail;

/**
 * Parses the two date formats this API uses.
 *
 * Message dates arrive as RFC 3339 ("2026-07-04T20:01:00.000Z"). Inbox
 * created_at and expires_at arrive as "2026-11-17 14:03:38", with a space and
 * no timezone at all.
 *
 * That second form is UTC, which is verified rather than assumed: an inbox
 * created at 19:03:38 Pakistan time, UTC+05:00, reported 14:03:38. Reading it
 * in the server's local timezone instead would put every expiry five hours out,
 * and on a two-hour inbox that means an expiry in the past.
 *
 * @internal
 */
final class Timestamps
{
    /**
     * @param  mixed  $value  Whatever the API sent, including null or absent.
     */
    public static function parse(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);
        $utc = new \DateTimeZone('UTC');

        // The space-separated form, pinned to UTC rather than left to the
        // process timezone.
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value) === 1) {
            $parsed = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value, $utc);

            return $parsed === false ? null : $parsed;
        }

        try {
            return (new \DateTimeImmutable($value))->setTimezone($utc);
        } catch (\Exception) {
            // An unparseable date is not worth throwing over: the caller
            // wanted the message, not the timestamp.
            return null;
        }
    }

    /**
     * Format a time for a query parameter. The API expects ISO 8601.
     */
    public static function toQuery(\DateTimeInterface $when): string
    {
        return (new \DateTimeImmutable('@' . $when->getTimestamp()))
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');
    }
}
