<?php

declare(strict_types=1);

namespace BestTempMail;

/**
 * What a link in a message is for, so the footer furniture cannot be mistaken
 * for the call to action.
 */
enum LinkType: string
{
    case Action = 'action';
    case Unsubscribe = 'unsubscribe';
    case Social = 'social';
    case Legal = 'legal';
    case Tracking = 'tracking';
    case ViewInBrowser = 'view_in_browser';
    case Other = 'other';

    /**
     * Read a value from the API, treating anything unrecognised as Other.
     *
     * A link type added server-side should not throw in an existing test
     * suite, and Other is what an unknown classification actually means.
     */
    public static function parse(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom($value) ?? self::Other) : self::Other;
    }
}
