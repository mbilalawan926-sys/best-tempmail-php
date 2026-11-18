<?php

declare(strict_types=1);

namespace BestTempMail;

/**
 * The best-tempmail API client.
 *
 * Creates throwaway inboxes that receive real email at real public addresses,
 * waits for a message, and pulls the verification code or the link to click out
 * of it. Built for a test that has to get through an email verification step.
 *
 *     $client = new Client(getenv('BTM_API_KEY') ?: null);
 *
 *     $code = $client->withInbox(function (Inbox $inbox) use ($client) {
 *         signUpWithEmail($inbox->address);
 *
 *         return $client->waitForCode($inbox->address, new WaitOptions(subject: 'verify'));
 *     });
 *
 * Safe to reuse: there is no per-request state on the instance.
 */
final class Client
{
    public const DEFAULT_BASE_URL = 'https://api.best-tempmail.com/v1';

    public const VERSION = '0.1.0';

    /**
     * The longest single long poll to ask for. The server caps a wait at 55
     * seconds, so staying under that asks for what it will actually give.
     */
    private const MAX_SERVER_WAIT = 50.0;

    /** The gap between polls when a filter rules out the long-poll endpoint. */
    private const FILTERED_POLL_INTERVAL = 2.0;

    private readonly string $baseUrl;

    private readonly Transport $transport;

    public function __construct(
        /**
         * Your API key, or null for the free tier.
         *
         * Without a key: 3 inboxes per day per IP, and the OTP and link
         * endpoints return 402. On shared infrastructure such as a CI runner
         * that per-IP quota is usually spent by somebody else before your job
         * starts, so read a key from the environment rather than relying on
         * the free tier there.
         */
        private readonly ?string $apiKey = null,
        string $baseUrl = self::DEFAULT_BASE_URL,
        ?Transport $transport = null,
        /**
         * Seconds allowed for an ordinary request. Long polls get their own
         * allowance on top, so this does not need to accommodate them.
         */
        private readonly float $requestTimeout = 30.0,
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->transport = $transport ?? new CurlTransport();
    }

    /* ------------------------------------------------------------- inboxes */

    public function createInbox(?CreateInboxOptions $options = null): Inbox
    {
        $body = $options?->toBody() ?? [];
        $data = $this->request('POST', '/inboxes', body: $body === [] ? null : $body);

        $inbox = Inbox::fromArray($data);
        if ($inbox->address === '') {
            throw new UnexpectedResponseException(
                'best-tempmail: an inbox was created but the response carried no address',
            );
        }

        return $inbox;
    }

    /**
     * Create up to BulkCreateOptions::MAX_COUNT inboxes in one call. Requires a
     * paid plan.
     *
     * Each inbox costs one request of quota, reported back as
     * BulkResult::$quotaCharged.
     *
     * Pass an int for the simple case: bulkCreateInboxes(25).
     */
    public function bulkCreateInboxes(BulkCreateOptions|int $options): BulkResult
    {
        if (is_int($options)) {
            $options = new BulkCreateOptions($options);
        }

        return BulkResult::fromArray(
            $this->request('POST', '/inboxes/bulk', body: $options->toBody()),
        );
    }

    public function inbox(string $address): InboxInfo
    {
        return InboxInfo::fromArray(
            $this->request('GET', '/inboxes/' . self::escapeAddress($address)),
        );
    }

    /**
     * Delete an inbox and its messages.
     *
     * Deleting one that has already expired or been deleted is not an error, so
     * this is safe in a finally block without guarding it. Anything that turns
     * a passing test red for tidying up after itself is a bad trade.
     */
    public function deleteInbox(string $address): void
    {
        try {
            $this->request('DELETE', '/inboxes/' . self::escapeAddress($address));
        } catch (ApiException $e) {
            if (!$e->isNotFound()) {
                throw $e;
            }
        }
    }

    /**
     * Create an inbox, hand it to the callback, and delete it afterwards
     * whatever happens.
     *
     * PHP has no defer, so this is the equivalent: the inbox is cleaned up on
     * the way out even if the callback throws, and the callback's return value
     * is passed through.
     *
     *     $code = $client->withInbox(function (Inbox $inbox) use ($client) {
     *         signUp($inbox->address);
     *
     *         return $client->waitForCode($inbox->address);
     *     });
     *
     * @template T
     * @param  callable(Inbox): T  $callback
     * @return T
     */
    public function withInbox(callable $callback, ?CreateInboxOptions $options = null): mixed
    {
        $inbox = $this->createInbox($options);

        try {
            return $callback($inbox);
        } finally {
            // Swallow cleanup failures: they must never mask the real result,
            // and the inbox expires on its own regardless.
            try {
                $this->deleteInbox($inbox->address);
            } catch (BestTempMailException) {
                // deliberately ignored
            }
        }
    }

    /**
     * The domains inboxes can be created on.
     *
     * Fetch this instead of hardcoding: the list changes, and fetching keeps
     * working when it does.
     *
     * @return list<string>
     */
    public function domains(): array
    {
        $data = $this->request('GET', '/domains');
        $domains = [];
        foreach (is_array($data['domains'] ?? null) ? $data['domains'] : [] as $domain) {
            if (is_string($domain)) {
                $domains[] = $domain;
            }
        }

        return $domains;
    }

    /* ------------------------------------------------------------ messages */

    /**
     * List the messages in an inbox as summaries, without bodies.
     */
    public function messages(string $address, ?ListOptions $options = null): MessageList
    {
        return MessageList::fromArray($this->request(
            'GET',
            '/inboxes/' . self::escapeAddress($address) . '/messages',
            query: $options?->toQuery() ?? [],
        ));
    }

    /**
     * Read one full message, body included.
     *
     * Scoped to the inbox deliberately: you need both the address and the
     * message id, so knowing an id alone grants nothing.
     */
    public function message(string $address, string $messageId): Message
    {
        $data = $this->request(
            'GET',
            '/inboxes/' . self::escapeAddress($address) . '/messages/' . rawurlencode($messageId),
        );

        $message = is_array($data['message'] ?? null) ? $data['message'] : $data;

        return Message::fromArray($message);
    }

    /* ---------------------------------------------------------- extraction */

    /**
     * Extract the verification code from a message, so you do not have to write
     * a parser for every sender's format. Requires a paid plan.
     *
     * Candidates are scored on the words around them, their length and their
     * position. When nothing scores highly enough the code comes back empty and
     * the confidence is None, rather than a guess being returned.
     */
    public function otp(string $address, string $messageId): Otp
    {
        return Otp::fromArray($this->request(
            'GET',
            '/inboxes/' . self::escapeAddress($address) . '/messages/' . rawurlencode($messageId) . '/otp',
        ));
    }

    /**
     * Extract every link in a message and identify the one it wants clicked.
     * Requires a paid plan.
     *
     * The counterpart to otp() for emails that want a click rather than a code.
     * Links are scored on the wording around them, where they sit in the
     * message, and whether the URL carries something that looks like a one-time
     * token. Each is typed, so unsubscribe, social, legal, tracking and
     * view-in-browser links are labelled instead of competing to be the answer.
     */
    public function links(string $address, string $messageId): Links
    {
        return Links::fromArray($this->request(
            'GET',
            '/inboxes/' . self::escapeAddress($address) . '/messages/' . rawurlencode($messageId) . '/links',
        ));
    }

    /**
     * Download an attachment's bytes by index. Requires a Pro plan; metadata is
     * visible on every plan.
     *
     * Prefer downloadAttachment() when you already have the metadata, since it
     * checks the plan first and says which file it was about.
     */
    public function attachment(string $address, string $messageId, int $index): AttachmentFile
    {
        if ($index < 0) {
            throw new \InvalidArgumentException(
                sprintf('best-tempmail: attachment index must not be negative, got %d', $index),
            );
        }

        $response = $this->send(
            'GET',
            '/inboxes/' . self::escapeAddress($address)
                . '/messages/' . rawurlencode($messageId)
                . '/attachments/' . $index,
        );

        $filename = '';
        $disposition = $response->header('content-disposition');
        if ($disposition !== null && preg_match('/filename\*?="?([^";]+)"?/i', $disposition, $m) === 1) {
            $filename = trim($m[1]);
        }

        return new AttachmentFile(
            contents: $response->body,
            filename: $filename,
            mime: $response->header('content-type') ?? 'application/octet-stream',
        );
    }

    /**
     * Download the bytes for an attachment you already have the metadata for,
     * using its index and checking its plan first.
     *
     * When the attachment is not downloadable on the current plan this fails
     * before making a request, naming the file and the plan required. A 402 from
     * the server says the same thing, but only after a round trip and without
     * saying which attachment it meant.
     */
    public function downloadAttachment(string $address, string $messageId, Attachment $attachment): AttachmentFile
    {
        if (!$attachment->downloadable) {
            throw new ApiException(
                402,
                sprintf(
                    '"%s" is not downloadable on this plan (needs %s)',
                    $attachment->filename,
                    $attachment->requiresPlan ?? 'a higher plan',
                ),
                upgradeUrl: 'https://best-tempmail.com/api/pricing',
            );
        }

        return $this->attachment($address, $messageId, $attachment->index);
    }

    /* ------------------------------------------------------------- account */

    /**
     * The caller's plan, remaining quota and subscription.
     *
     * Works without a key, which is the point of it: a keyless call reports the
     * free tier including how many of today's three inboxes this IP has used.
     *
     * Worth reading inside a long test run: check $usage->hourly->remaining and
     * slow down before hitting the limit rather than handling the 429 after.
     */
    public function usage(): Usage
    {
        return Usage::fromArray($this->request('GET', '/usage'));
    }

    public function stats(): Stats
    {
        return Stats::fromArray($this->request('GET', '/stats'));
    }

    public function health(): Health
    {
        return Health::fromArray($this->request('GET', '/health'));
    }

    /* ------------------------------------------------------------ webhooks */

    /**
     * Register a URL to receive mail as it arrives rather than asking for it.
     * Requires a paid plan.
     *
     * One URL per key: registering again replaces the previous one and issues a
     * new signing secret, which is how you rotate it. The URL must use https and
     * point at a public host, since otherwise this endpoint could be used to
     * make the API's own servers call their internal services.
     */
    public function registerWebhook(string $url): WebhookRegistration
    {
        return WebhookRegistration::fromArray(
            $this->request('POST', '/webhooks', body: ['url' => $url]),
        );
    }

    public function webhook(): WebhookStatus
    {
        return WebhookStatus::fromArray($this->request('GET', '/webhooks'));
    }

    /** Stop deliveries. Not an error when none is registered. */
    public function deleteWebhook(): void
    {
        try {
            $this->request('DELETE', '/webhooks');
        } catch (ApiException $e) {
            if (!$e->isNotFound()) {
                throw $e;
            }
        }
    }

    /* --------------------------------------------------------------- waits */

    /**
     * Wait for a message to be in the inbox and return its summary.
     *
     * It checks what is ALREADY in the inbox before waiting for anything new,
     * and that ordering is the whole point of this method.
     *
     * The long-poll endpoint signals mail ARRIVING. A message that landed before
     * the call started is invisible to it, and in automated testing that is the
     * common case rather than the edge case: you create the inbox, trigger the
     * signup, and the email often wins the race against your next line of code.
     * A wait that only long-polls then blocks for its whole timeout while the
     * message it wants is already sitting there.
     *
     * This is not hypothetical. The GitHub Action for this API shipped with 21
     * passing tests and failed on the first real email for exactly this reason,
     * because its mock answered the long poll whenever asked and so could not
     * express the difference.
     *
     * Two strategies, chosen by whether a filter is set:
     *
     *  - No filter: the long-poll endpoint, which returns as soon as mail lands
     *    and costs one request however long it waits.
     *  - With a filter: the message listing, polled, because the long poll
     *    cannot filter and would return on the first message to arrive whether
     *    or not it is the one wanted.
     *
     * @throws NoMessageException when the timeout expires with nothing matching
     */
    public function waitForMessage(string $address, ?WaitOptions $options = null): MessageSummary
    {
        $options ??= new WaitOptions();
        $listOptions = $options->toListOptions();
        $startedAt = microtime(true);
        $deadline = $startedAt + $options->timeout;

        // What is already there, before waiting for anything new.
        $existing = $this->messages($address, $listOptions);
        $first = $existing->first();
        if ($first !== null) {
            return $first;
        }

        $filtered = $listOptions->isFiltering();

        while (microtime(true) < $deadline) {
            if ($filtered) {
                $this->sleepUntil(min(self::FILTERED_POLL_INTERVAL, $deadline - microtime(true)));
                $found = $this->messages($address, $listOptions)->first();
                if ($found !== null) {
                    return $found;
                }
                continue;
            }

            $slice = min(self::MAX_SERVER_WAIT, $deadline - microtime(true));
            $seconds = max(1, (int) floor($slice));
            $message = $this->longPoll($address, $seconds);
            if ($message !== null) {
                return $message;
            }
        }

        throw new NoMessageException($address, microtime(true) - $startedAt);
    }

    /**
     * The long-poll endpoint on its own: holds one connection open until mail
     * arrives or the timeout expires, and counts as a single request however
     * long it waits.
     *
     * It does NOT look at mail that arrived before the call, and it cannot
     * filter. Prefer waitForMessage(), which handles both. Use this only when
     * you specifically want the raw endpoint's behaviour.
     *
     * The server caps a single wait at 55 seconds; anything longer is clamped.
     *
     * @throws NoMessageException on timeout
     */
    public function wait(string $address, float $timeout = 30.0): MessageSummary
    {
        $seconds = max(1, min(55, (int) round($timeout)));
        $message = $this->longPoll($address, $seconds);
        if ($message === null) {
            throw new NoMessageException($address, (float) $seconds);
        }

        return $message;
    }

    /**
     * Wait for a message and extract its verification code.
     *
     * Requires a paid plan for the extraction step. A message with no
     * recognisable code is not an error here: the message did arrive, and the
     * caller may want to look at the candidates.
     */
    public function waitForOtp(string $address, ?WaitOptions $options = null): Otp
    {
        return $this->otp($address, $this->waitForMessage($address, $options)->id);
    }

    /**
     * Wait for a message and return its verification code as a plain string,
     * which is the one-liner most tests want.
     *
     * Unlike waitForOtp() this treats a message with no recognisable code as an
     * error, because a caller asking for a bare string has nowhere to put "the
     * mail came but held no code" and would otherwise type an empty string into
     * the form under test.
     */
    public function waitForCode(string $address, ?WaitOptions $options = null): string
    {
        $otp = $this->waitForOtp($address, $options);
        if (!$otp->found()) {
            throw new UnexpectedResponseException(sprintf(
                'best-tempmail: message %s arrived but no verification code could be extracted from it (confidence %s)',
                $otp->messageId,
                $otp->confidence->value,
            ));
        }

        return $otp->code;
    }

    /**
     * Wait for a message and extract its links, including the one it wants
     * clicked. Requires a paid plan for the extraction step.
     */
    public function waitForLinks(string $address, ?WaitOptions $options = null): Links
    {
        return $this->links($address, $this->waitForMessage($address, $options)->id);
    }

    /**
     * Wait for a message and return the single link it wants clicked.
     *
     * An error when nothing scores highly enough, for the same reason as
     * waitForCode(): following the wrong link in a test fails in a way that is
     * very hard to trace, so no answer is safer than a guess.
     */
    public function waitForActionUrl(string $address, ?WaitOptions $options = null): string
    {
        $links = $this->waitForLinks($address, $options);
        if (!$links->found()) {
            throw new UnexpectedResponseException(sprintf(
                'best-tempmail: message %s arrived but no clear call-to-action link was found in it (confidence %s)',
                $links->messageId,
                $links->confidence->value,
            ));
        }

        return $links->actionUrl;
    }

    /* ------------------------------------------------------------ internals */

    /**
     * One long poll. Null means the server timed out, which is not a failure:
     * it answers 200 with timed_out true so the caller can simply go round
     * again.
     */
    private function longPoll(string $address, int $seconds): ?MessageSummary
    {
        $data = $this->request(
            'GET',
            '/inboxes/' . self::escapeAddress($address) . '/wait',
            query: ['timeout' => (string) $seconds],
            // Allow for the server holding the connection, plus headroom for
            // the round trip itself.
            timeout: $seconds + 15.0,
        );

        if (($data['timed_out'] ?? false) === true) {
            return null;
        }

        $message = $data['message'] ?? null;

        return is_array($message) && $message !== [] ? MessageSummary::fromArray($message) : null;
    }

    private function sleepUntil(float $seconds): void
    {
        if ($seconds > 0) {
            usleep((int) round($seconds * 1_000_000));
        }
    }

    /**
     * @param  array<string, string>  $query
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed>
     */
    private function request(
        string $method,
        string $path,
        array $query = [],
        ?array $body = null,
        ?float $timeout = null,
    ): array {
        return $this->send($method, $path, $query, $body, $timeout)->json();
    }

    /**
     * @param  array<string, string>  $query
     * @param  array<string, mixed>|null  $body
     */
    private function send(
        string $method,
        string $path,
        array $query = [],
        ?array $body = null,
        ?float $timeout = null,
    ): Response {
        $url = $this->baseUrl . $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        $headers = [
            'Accept' => 'application/json',
            'User-Agent' => 'best-tempmail-php/' . self::VERSION,
        ];
        if ($this->apiKey !== null && $this->apiKey !== '') {
            $headers['x-api-key'] = $this->apiKey;
        }

        $encoded = null;
        if ($body !== null) {
            $encoded = json_encode($body, JSON_THROW_ON_ERROR);
            $headers['Content-Type'] = 'application/json';
        }

        $response = $this->transport->send(
            $method,
            $url,
            $headers,
            $encoded,
            $timeout ?? $this->requestTimeout,
        );

        if (!$response->successful()) {
            throw self::toApiException($response);
        }

        return $response;
    }

    private static function toApiException(Response $response): ApiException
    {
        $data = $response->json();

        $detail = null;
        foreach (['error', 'message'] as $field) {
            if (is_string($data[$field] ?? null) && $data[$field] !== '') {
                $detail = $data[$field];
                break;
            }
        }
        if ($detail === null) {
            $detail = trim($response->body);
            if ($detail === '') {
                $detail = 'the response carried no explanation';
            } elseif (strlen($detail) > 200) {
                $detail = substr($detail, 0, 200) . '...';
            }
        }

        $retryAfter = null;
        $header = $response->header('retry-after');
        if ($header !== null && ctype_digit($header)) {
            $retryAfter = (int) $header;
        }

        return new ApiException(
            status: $response->status,
            detail: $detail,
            requestId: is_string($data['request_id'] ?? null)
                ? $data['request_id']
                : $response->header('x-request-id'),
            upgradeUrl: is_string($data['upgrade'] ?? null) ? $data['upgrade'] : null,
            retryAfterSeconds: $retryAfter,
        );
    }

    /**
     * Encode an address for a URL path.
     *
     * The @ is left literal, which is the form the published examples use and
     * which is known to work. Everything else is percent-encoded, so a plus in
     * a plus-addressed inbox cannot be mistaken for an encoded space.
     */
    private static function escapeAddress(string $address): string
    {
        return str_replace('%40', '@', rawurlencode($address));
    }
}
