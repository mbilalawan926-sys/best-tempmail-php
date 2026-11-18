<?php

declare(strict_types=1);

namespace BestTempMail;

/**
 * The API answered with a status outside the 2xx range.
 */
final class ApiException extends BestTempMailException
{
    public function __construct(
        public readonly int $status,
        string $detail,
        public readonly ?string $requestId = null,
        public readonly ?string $upgradeUrl = null,
        public readonly ?int $retryAfterSeconds = null,
    ) {
        $message = sprintf('best-tempmail: HTTP %d: %s', $status, $detail);
        if ($retryAfterSeconds !== null) {
            $message .= sprintf(' (retry after %ds)', $retryAfterSeconds);
        }
        if ($upgradeUrl !== null) {
            $message .= sprintf(' (see %s)', $upgradeUrl);
        }
        if ($requestId !== null) {
            // The request id belongs in the message: it is the only thing a
            // support email can usefully quote.
            $message .= sprintf(' [request %s]', $requestId);
        }

        parent::__construct($message, $status);
    }

    /** The API key was rejected as invalid or inactive. */
    public function isUnauthorized(): bool
    {
        return $this->status === 401;
    }

    /**
     * The plan does not include this capability. Retrying will not help, and
     * $upgradeUrl links to pricing.
     */
    public function isPaymentRequired(): bool
    {
        return $this->status === 402;
    }

    /**
     * The inbox or message does not exist, has expired, or belongs to a
     * different API key.
     *
     * That last case is deliberate on the server's part: an inbox created with
     * a key is private to that key, and anyone else gets 404 rather than 403,
     * because a 403 would confirm the address exists and let someone probe for
     * live addresses.
     */
    public function isNotFound(): bool
    {
        return $this->status === 404;
    }

    /** An attachment is larger than the API will serve. */
    public function isTooLarge(): bool
    {
        return $this->status === 413;
    }

    /**
     * The hourly quota, the burst limit or the concurrent-wait limit was
     * exceeded. Check $retryAfterSeconds before trying again.
     */
    public function isRateLimited(): bool
    {
        return $this->status === 429;
    }
}
