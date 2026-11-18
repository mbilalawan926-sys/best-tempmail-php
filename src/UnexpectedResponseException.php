<?php

declare(strict_types=1);

namespace BestTempMail;

/**
 * The API answered successfully but the answer cannot be used: an inbox with no
 * address, a message that arrived carrying no extractable code.
 *
 * Separate from ApiException, which means a status outside 2xx. Reporting "a
 * message arrived but held no code" as an HTTP error would be a lie about where
 * the problem is.
 */
final class UnexpectedResponseException extends BestTempMailException
{
}
