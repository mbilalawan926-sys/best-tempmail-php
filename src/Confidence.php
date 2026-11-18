<?php

declare(strict_types=1);

namespace BestTempMail;

/**
 * How sure the server is about an extracted code or link.
 *
 * When nothing scores highly enough the server returns no code and no action
 * URL rather than guessing, because a wrong code fails a test in a way that is
 * very hard to trace back to its cause.
 */
enum Confidence: string
{
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';
    case None = 'none';

    /**
     * Read a value from the API, treating anything unrecognised as None.
     *
     * A new confidence level appearing server-side should not throw inside
     * somebody's test suite, and None is the safe reading of "we do not
     * understand how sure the server is".
     */
    public static function parse(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom($value) ?? self::None) : self::None;
    }

    /** True for high or medium, which is the usual bar for acting on a result. */
    public function isConfident(): bool
    {
        return $this === self::High || $this === self::Medium;
    }
}
