<?php

declare(strict_types=1);

namespace BestTempMail;

/**
 * A wait finished with no matching message.
 *
 * An exception rather than a null return, on purpose. In a test, no email
 * arriving is a failure, and a null invites the caller to carry on and
 * dereference it two lines later.
 */
final class NoMessageException extends BestTempMailException
{
    public function __construct(
        public readonly string $address,
        public readonly float $waitedSeconds,
    ) {
        parent::__construct(sprintf(
            'best-tempmail: no matching message arrived at %s within %.1fs',
            $address,
            $waitedSeconds,
        ));
    }
}
