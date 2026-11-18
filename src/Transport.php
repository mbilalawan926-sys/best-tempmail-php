<?php

declare(strict_types=1);

namespace BestTempMail;

/**
 * How the client makes HTTP requests.
 *
 * This interface exists so the package needs no dependencies. CurlTransport is
 * the default and works on any PHP with ext-curl, which is every PHP worth
 * worrying about. Requiring Guzzle, or even the PSR-18 interface packages,
 * would mean a test-only helper dragging production dependencies into
 * somebody's composer.lock, and it buys nothing this interface does not.
 *
 * To route requests through a client you already configure elsewhere, such as
 * Guzzle with your own middleware, implement this and pass it to the Client.
 * The README has a ten-line Guzzle example.
 */
interface Transport
{
    /**
     * Perform a request and return the response without throwing on a 4xx or
     * 5xx status: the Client turns those into exceptions, so that the mapping
     * lives in one place.
     *
     * Implementations SHOULD throw TransportException when the request could
     * not be made at all, which is a different thing from a request that was
     * made and refused.
     *
     * @param  'GET'|'POST'|'DELETE'  $method
     * @param  array<string, string>  $headers
     */
    public function send(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        float $timeout,
    ): Response;
}
