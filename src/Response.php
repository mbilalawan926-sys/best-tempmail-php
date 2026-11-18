<?php

declare(strict_types=1);

namespace BestTempMail;

/**
 * A raw HTTP response, before the Client decides what it means.
 */
final class Response
{
    /**
     * @param  array<string, string>  $headers  Header names lowercased, so
     *                                          lookups do not depend on how the
     *                                          server happened to capitalise
     *                                          them.
     */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers = [],
    ) {
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function successful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /**
     * Decode the body as a JSON object.
     *
     * @return array<string, mixed>
     */
    public function json(): array
    {
        if ($this->body === '') {
            return [];
        }

        $decoded = json_decode($this->body, true);

        return is_array($decoded) ? $decoded : [];
    }
}
