<?php

declare(strict_types=1);

namespace BestTempMail;

/**
 * Verifies and decodes a webhook delivery.
 *
 * The signature check is not optional. Without it, anyone who learns your
 * webhook URL could post verification codes of their choosing into your
 * application, which on a signup flow is the whole ballgame.
 */
final class Webhook
{
    public const SIGNATURE_HEADER = 'X-BTM-Signature';

    public const TIMESTAMP_HEADER = 'X-BTM-Timestamp';

    /** How far a delivery's timestamp may be from now before it is a replay. */
    public const DEFAULT_TOLERANCE_SECONDS = 300;

    /**
     * Verify a delivery and decode its payload.
     *
     * $rawBody MUST be the bytes exactly as received. Re-serialising a parsed
     * array changes them, and the signature will then never match no matter how
     * correct everything else is. In practice that means reading the body
     * before any framework middleware gets near it:
     *
     *     $body = file_get_contents('php://input');
     *     $event = Webhook::parse($secret, getallheaders(), $body);
     *
     * In Laravel, use $request->getContent(), which is the raw body, and NOT
     * $request->all().
     *
     * @param  array<string, string|list<string>>  $headers  Header names in any
     *                                                       capitalisation.
     * @param  int  $toleranceSeconds  Zero or less disables the replay window,
     *                                 which is occasionally useful when
     *                                 replaying a captured delivery in a test
     *                                 and never right in production.
     *
     * @throws InvalidSignatureException
     */
    public static function parse(
        string $secret,
        array $headers,
        string $rawBody,
        int $toleranceSeconds = self::DEFAULT_TOLERANCE_SECONDS,
    ): WebhookEvent {
        if ($secret === '') {
            throw new \InvalidArgumentException(
                'best-tempmail: a signing secret is required to verify a webhook',
            );
        }

        $normalised = self::normaliseHeaders($headers);
        $timestamp = $normalised[strtolower(self::TIMESTAMP_HEADER)] ?? null;
        $signature = $normalised[strtolower(self::SIGNATURE_HEADER)] ?? null;

        if ($timestamp === null || $signature === null) {
            throw new InvalidSignatureException(sprintf(
                'best-tempmail: the delivery is missing %s or %s',
                self::TIMESTAMP_HEADER,
                self::SIGNATURE_HEADER,
            ));
        }

        // The header is "v1,<base64>". A bare base64 value is accepted too, so
        // a future version prefix, or its absence, does not break verification
        // outright.
        $provided = $signature;
        if (str_contains($signature, ',')) {
            [, $provided] = explode(',', $signature, 2);
        }

        $expected = base64_encode(
            hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret, true),
        );

        // Constant-time comparison, so the time taken to reject cannot reveal
        // how much of a guessed signature was right.
        if (!hash_equals($expected, $provided)) {
            throw new InvalidSignatureException(
                'best-tempmail: webhook signature does not match',
            );
        }

        if ($toleranceSeconds > 0) {
            if (!ctype_digit(ltrim($timestamp, '-'))) {
                throw new InvalidSignatureException(sprintf(
                    'best-tempmail: timestamp "%s" is not a unix time',
                    $timestamp,
                ));
            }
            $age = abs(time() - (int) $timestamp);
            if ($age > $toleranceSeconds) {
                throw new InvalidSignatureException(sprintf(
                    'best-tempmail: the delivery is %ds away from now, outside the %ds tolerance',
                    $age,
                    $toleranceSeconds,
                ));
            }
        }

        try {
            $decoded = json_decode($rawBody, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new UnexpectedResponseException(
                'best-tempmail: the webhook signature verified but the payload would not decode: '
                    . $e->getMessage(),
                previous: $e,
            );
        }

        if (!is_array($decoded)) {
            throw new UnexpectedResponseException(
                'best-tempmail: the webhook payload was not a JSON object',
            );
        }

        return WebhookEvent::fromArray($decoded);
    }

    /**
     * The same thing, reading headers out of $_SERVER for plain PHP with no
     * framework.
     *
     *     $event = Webhook::fromServer($secret, $_SERVER, file_get_contents('php://input'));
     *
     * @param  array<string, mixed>  $server
     *
     * @throws InvalidSignatureException
     */
    public static function fromServer(
        string $secret,
        array $server,
        string $rawBody,
        int $toleranceSeconds = self::DEFAULT_TOLERANCE_SECONDS,
    ): WebhookEvent {
        $headers = [];
        foreach ($server as $name => $value) {
            if (str_starts_with($name, 'HTTP_') && is_string($value)) {
                // HTTP_X_BTM_SIGNATURE becomes x-btm-signature.
                $headers[strtolower(str_replace('_', '-', substr($name, 5)))] = $value;
            }
        }

        return self::parse($secret, $headers, $rawBody, $toleranceSeconds);
    }

    /**
     * @param  array<string, string|list<string>>  $headers
     * @return array<string, string>
     */
    private static function normaliseHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $name => $value) {
            $out[strtolower((string) $name)] = is_array($value)
                ? (string) ($value[0] ?? '')
                : (string) $value;
        }

        return $out;
    }
}
