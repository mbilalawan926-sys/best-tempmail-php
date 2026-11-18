<?php

declare(strict_types=1);

namespace BestTempMail;

/**
 * The default transport: ext-curl, no dependencies.
 */
final class CurlTransport implements Transport
{
    /**
     * @param  array<int, mixed>  $extraOptions  Extra cURL options, for the
     *                                           occasional case of a corporate
     *                                           proxy or a pinned CA bundle.
     */
    public function __construct(private readonly array $extraOptions = [])
    {
    }

    public function send(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        float $timeout,
    ): Response {
        $handle = curl_init();
        if ($handle === false) {
            throw new TransportException('could not initialise cURL');
        }

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $responseHeaders = [];

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_FOLLOWLOCATION => false,
            // A long poll holds the connection open for up to 55 seconds, so
            // the overall timeout has to allow for that while the connect
            // timeout stays short: a server that is not answering at all
            // should fail quickly, not after a minute.
            CURLOPT_TIMEOUT_MS => (int) round($timeout * 1000),
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HEADERFUNCTION => static function ($_handle, string $line) use (&$responseHeaders): int {
                $length = strlen($line);
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return $length;
            },
        ];

        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }

        curl_setopt_array($handle, $options + $this->extraOptions);

        $result = curl_exec($handle);
        $errorNumber = curl_errno($handle);
        $errorMessage = curl_error($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        if ($result === false || $errorNumber !== 0) {
            throw new TransportException(sprintf(
                'could not reach %s: %s (cURL %d)',
                $url,
                $errorMessage !== '' ? $errorMessage : 'unknown error',
                $errorNumber,
            ));
        }

        return new Response($status, is_string($result) ? $result : '', $responseHeaders);
    }
}
