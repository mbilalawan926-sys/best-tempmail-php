<?php

declare(strict_types=1);

namespace BestTempMail;

/**
 * The request could not be made at all: DNS, TLS, a dropped connection, a
 * timeout.
 *
 * Deliberately separate from ApiException. "We never reached the API" and "the
 * API refused us" call for different responses, and collapsing them sends
 * people looking in the wrong place.
 */
final class TransportException extends BestTempMailException
{
}
