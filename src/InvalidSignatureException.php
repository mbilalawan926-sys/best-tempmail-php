<?php

declare(strict_types=1);

namespace BestTempMail;

/**
 * A webhook delivery failed verification.
 *
 * Treat this as hostile input rather than as a bug. Without the signature
 * check, anyone who learned your webhook URL could post verification codes of
 * their choosing into your application.
 */
final class InvalidSignatureException extends BestTempMailException
{
}
