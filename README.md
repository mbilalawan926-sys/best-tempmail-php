# best-tempmail for PHP

Disposable email inboxes for automated tests. Create a throwaway address, wait for the email,
and get the verification code or the link to click.

**No dependencies.** Not Guzzle, not even the PSR-18 interface packages. `composer require`
pulls in nothing.

```bash
composer require best-tempmail/best-tempmail
```

```php
use BestTempMail\Client;
use BestTempMail\Inbox;
use BestTempMail\WaitOptions;

$client = new Client(getenv('BTM_API_KEY') ?: null);

$code = $client->withInbox(function (Inbox $inbox) use ($client) {
    signUpWithEmail($inbox->address);

    return $client->waitForCode($inbox->address, new WaitOptions(subject: 'verify'));
});

submitCode($code);
```

`withInbox` is the PHP answer to Go's `defer`: the inbox is deleted on the way out whether the
callback returns or throws, and the return value passes through.

## These are real addresses on real domains

Most email-testing services are SMTP sandboxes: you point your own application's outgoing mail
at a fake server and read it back. That cannot test a signup on a site you do not control.

best-tempmail receives genuine internet mail at genuine public addresses, so a test can
register with a third-party service and complete the email verification step. If what you need
is to inspect your own app's outbound mail, a sandbox is the better tool. If you need to get
through somebody else's verification email, this is.

## Waiting, and the bug worth knowing about

`waitForMessage` looks at what is already in the inbox before it waits for anything new, and
that ordering is the entire point.

The API's long-poll endpoint signals mail **arriving**. A message that landed before the call
started is invisible to it. In automated testing that is the normal case, not an edge case: you
create the inbox, trigger the signup, and the email often wins the race against your next line
of code. A wait that only long-polls then blocks for its whole timeout while the message it is
waiting for sits there already.

This is not hypothetical. The GitHub Action for this same API shipped with 21 passing tests and
failed on the first real email for exactly this reason, because its mock answered the long poll
whenever asked and so could not express the difference. Two tests here use a fake that **refuses
to answer the long poll at all**, and they have been checked to fail when the fix is removed.

`waitForMessage` also picks its strategy based on whether you filtered:

- **No filter:** the long-poll endpoint. One request covers the whole wait.
- **With a filter:** the message listing, polled, because the long poll cannot filter and would
  return the first message to arrive whether or not it is the one you want.

That second case matters more than it looks. In a real signup a welcome email frequently beats
the verification email by a second, so an unfiltered wait hands you the wrong message and the
test fails a long way from the cause. Pass `subject:` or `from:`.

## The wait helpers

| Call | Returns | Use when |
|---|---|---|
| `waitForCode` | `string` | You want the code and nothing else. Throws if none is found |
| `waitForOtp` | `Otp` | You want the confidence and the scored candidates too |
| `waitForActionUrl` | `string` | You want the link to click. Throws if none scores highly enough |
| `waitForLinks` | `Links` | You want every link, typed and scored |
| `waitForMessage` | `MessageSummary` | You only need to know the mail arrived |
| `wait` | `MessageSummary` | You specifically want the raw long poll, with no existing-mail check |

`waitForCode` and `waitForActionUrl` throw `UnexpectedResponseException` when the message
arrives but holds no code or no clear link, because a caller asking for a bare string has
nowhere to put that outcome and would otherwise type an empty string into the form under test.
The structured versions return it as data, so you can inspect `$otp->candidates` yourself.

## Everything else

```php
// inboxes
$client->createInbox(new CreateInboxOptions(username: 'x', domain: 'y', lifetimeMinutes: 1440));
$client->bulkCreateInboxes(25);                        // paid, up to 50
$client->withInbox(fn (Inbox $inbox) => /* ... */);    // create, use, always delete
$client->inbox($address);
$client->deleteInbox($address);
$client->domains();

// messages
$client->messages($address, new ListOptions(subject: 'verify'));
$client->message($address, $messageId);
$client->downloadAttachment($address, $messageId, $attachment);   // Pro, checks the plan first
$client->attachment($address, $messageId, 0);                     // Pro, by raw index

// extraction, paid plans
$client->otp($address, $messageId);
$client->links($address, $messageId);

// account
$client->usage();                                      // works without a key
$client->stats();
$client->health();

// webhooks, paid plans
$client->registerWebhook('https://you.example/hook');
$client->webhook();
$client->deleteWebhook();
Webhook::parse($secret, $headers, $rawBody);
```

## Searching a busy inbox

Every `ListOptions` argument is optional and they combine. Filtering happens on the server, so a
full inbox never has to be downloaded and sifted locally.

```php
$list = $client->messages($address, new ListOptions(
    q: 'ORD-48812',
    since: new DateTimeImmutable('-1 day'),
    limit: 20,
));
```

| Argument | Notes |
|---|---|
| `limit` | Page size, up to 200. Null means the server default of 100 |
| `offset` | Messages to skip, up to 5000 |
| `from` | Sender, case-insensitive substring |
| `subject` | Subject, case-insensitive substring |
| `q` | Full text across sender, subject and decoded body. Script and style blocks ignored |
| `since`, `until` | Bound the message `Date` header |
| `hasAttachments` | A nullable bool, so null means do not filter and `false` means filter for none |

Two fields make an empty result interpretable, and `isConclusivelyEmpty()` wraps them:

```php
if ($list->isEmpty()) {
    if ($list->isConclusivelyEmpty()) {
        // nothing in the inbox matches, definitively
    } else {
        // the search hit its ceiling: narrow the filter or set `since`
    }
}
```

## Attachments

Metadata comes back on every plan, so you can see what a message carries without paying for it.
`$attachment->downloadable` says whether your plan may fetch the bytes:

```php
$message = $client->message($address, $messageId);

foreach ($message->attachments as $attachment) {
    if (!$attachment->downloadable) {
        echo "{$attachment->filename} ({$attachment->mime}, {$attachment->size} bytes)"
           . " needs the {$attachment->requiresPlan} plan\n";
        continue;
    }

    $client->downloadAttachment($address, $message->id, $attachment)->saveTo('/tmp/' . $attachment->filename);
}
```

Those field names were verified against a real message rather than taken from the docs, which
only ever show an empty list. An earlier guess had `content_type` where the API sends `mime`,
and missed `index`, `downloadable` and `requires_plan` entirely.

## Errors

Everything thrown extends `BestTempMailException`, so one catch block covers the lot. Beneath
that:

| Exception | Means |
|---|---|
| `ApiException` | The API answered outside 2xx. Ask it `isNotFound()`, `isRateLimited()`, `isPaymentRequired()`, `isUnauthorized()`, `isTooLarge()` |
| `TransportException` | The request could not be made at all: DNS, TLS, a dropped connection |
| `NoMessageException` | A wait finished with nothing matching |
| `UnexpectedResponseException` | A 2xx whose content cannot be used |
| `InvalidSignatureException` | A webhook failed verification |

`TransportException` is deliberately separate from `ApiException`. "We never reached the API"
and "the API refused us" need different responses, and collapsing them sends people looking in
the wrong place.

```php
try {
    $inbox = $client->createInbox();
} catch (ApiException $e) {
    if ($e->isRateLimited()) {
        sleep($e->retryAfterSeconds ?? 60);
    }
}
```

`isNotFound()` covering "belongs to another key" is deliberate on the server's part. An inbox
created with a key is private to that key, and anyone else gets 404 rather than 403, because a
403 would confirm the address exists and let someone probe for live addresses.

`deleteInbox` and `deleteWebhook` treat 404 as success, so cleanup in a `finally` cannot turn a
passing test red for tidying up after itself.

## Plus-addressing

Mail to `user+anything@domain` arrives at `user@domain`, so one inbox covers a test that
registers with several services:

```php
signUp('netflix', $inbox->tagged('netflix'));   // someone+netflix@alagen.site

$message = $client->waitForMessage($inbox->address, new WaitOptions(from: 'netflix'));
```

Wait on the base address, not the tagged one.

## Webhooks

The signature check is not optional. Without it, anyone who learns your webhook URL could post
verification codes of their choosing into your application.

```php
// plain PHP
$event = Webhook::fromServer($secret, $_SERVER, file_get_contents('php://input'));

// Laravel
$event = Webhook::parse($secret, $request->headers->all(), $request->getContent());
```

**Use the raw body.** Re-serialising a parsed array changes the bytes and the signature will
then never match no matter how correct everything else is. In Laravel that means
`$request->getContent()`, not `$request->all()`.

## Using your own HTTP client

The default transport is cURL. To route requests through a client you already configure, such
as Guzzle with your own middleware, implement `Transport`:

```php
use BestTempMail\{Response, Transport};
use GuzzleHttp\Client as Guzzle;

final class GuzzleTransport implements Transport
{
    public function __construct(private Guzzle $guzzle) {}

    public function send(string $method, string $url, array $headers, ?string $body, float $timeout): Response
    {
        $res = $this->guzzle->request($method, $url, [
            'headers' => $headers,
            'body' => $body,
            'timeout' => $timeout,
            'http_errors' => false,          // the client maps statuses itself
        ]);

        $flat = [];
        foreach ($res->getHeaders() as $name => $values) {
            $flat[strtolower($name)] = $values[0] ?? '';
        }

        return new Response($res->getStatusCode(), (string) $res->getBody(), $flat);
    }
}

$client = new Client($key, transport: new GuzzleTransport($guzzle));
```

`http_errors => false` matters: the client turns statuses into exceptions itself, so that the
mapping lives in one place.

## Timeouts

Ordinary requests get 30 seconds. A long poll gets its own allowance on top, because the server
holds the connection for up to 55 and an HTTP timeout below that would cut the wait off
mid-flight and look like a network fault.

## Timestamps

The API uses two formats: RFC 3339 for message dates, and `2026-11-17 14:03:38` with a space and
no timezone for inbox timestamps. Both come back as `DateTimeImmutable` in UTC. The second is
read as UTC rather than as local time, which was verified rather than assumed: an inbox created
at 19:03:38 in Pakistan, UTC+05:00, reported 14:03:38. Reading it in the process timezone would
put every expiry five hours out, and on a two-hour inbox that means an expiry in the past.

## Tests

```bash
php tests/run.php
composer test
```

53 tests, no install and no network needed. There is no dev dependency either: a package with no
dependencies should not need `composer install` to run its own suite.

There is also a live suite against production, which runs when a key is present:

```bash
BTM_API_KEY=btm_sk_live_... php tests/run.php
```

CI runs it on every push to `main`. It costs about a dozen requests and four inboxes of quota,
and fails loudly rather than skipping if the key is missing, because a "live" job that silently
skips is the same failure as a test that passes because no email arrived.

**What the live suite cannot cover,** stated here rather than left to be discovered: nothing in
it receives an email. A CI runner cannot send SMTP to the inbox, so OTP extraction, link
extraction and the already-arrived branch are not exercised against real mail there. Those
response shapes were captured from the live API by hand and are asserted as verbatim fixtures in
the mocked suite. Real end-to-end delivery is tested from the mail server itself.

## Requirements

PHP 8.1 or newer, with `ext-curl` and `ext-json`.

## Licence

MIT.
