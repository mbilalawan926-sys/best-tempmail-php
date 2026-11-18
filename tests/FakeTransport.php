<?php

declare(strict_types=1);

namespace BestTempMail\Tests;

use BestTempMail\Response;
use BestTempMail\Transport;

/**
 * A transport that answers from a closure and records everything it was asked.
 *
 * Several of the tests give it a handler that deliberately REFUSES to answer an
 * endpoint the client must not call. A mock that answers everything proves very
 * little: the GitHub Action for this API passed 21 tests against a mock that
 * always answered the long poll, and then failed on the first real email.
 */
final class FakeTransport implements Transport
{
    /** @var list<array{method: string, url: string, body: ?string, timeout: float}> */
    public array $requests = [];

    /** @var callable(string, string, ?string, self): Response */
    private $handler;

    /**
     * @param  callable(string, string, ?string, self): Response  $handler
     *         Receives the method, the full URL, the request body and this
     *         transport, so a handler can vary its answer by how many times it
     *         has been called.
     */
    public function __construct(callable $handler)
    {
        $this->handler = $handler;
    }

    public function send(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        float $timeout,
    ): Response {
        $this->requests[] = [
            'method' => $method,
            'url' => $url,
            'body' => $body,
            'timeout' => $timeout,
            'headers' => $headers,
        ];

        return ($this->handler)($method, $url, $body, $this);
    }

    /** @return list<string> "METHOD /path?query" for each call made. */
    public function calls(): array
    {
        return array_map(static function (array $request): string {
            $path = parse_url($request['url'], PHP_URL_PATH) ?? '';
            $query = parse_url($request['url'], PHP_URL_QUERY);

            return $request['method'] . ' ' . $path . ($query !== null ? '?' . $query : '');
        }, $this->requests);
    }

    public function called(string $needle): bool
    {
        return $this->countCalls($needle) > 0;
    }

    public function countCalls(string $needle): int
    {
        $n = 0;
        foreach ($this->calls() as $call) {
            if (str_contains($call, $needle)) {
                $n++;
            }
        }

        return $n;
    }

    /** How many times a path containing $needle was requested, by any method. */
    public function bodyFor(string $needle): ?string
    {
        foreach ($this->requests as $request) {
            if (str_contains($request['url'], $needle)) {
                return $request['body'];
            }
        }

        return null;
    }

    /** @param array<string, string> $headers */
    public static function json(mixed $body, int $status = 200, array $headers = []): Response
    {
        return new Response(
            $status,
            is_string($body) ? $body : (string) json_encode($body),
            ['content-type' => 'application/json'] + $headers,
        );
    }
}
