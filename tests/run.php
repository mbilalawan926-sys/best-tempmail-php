<?php

declare(strict_types=1);

/*
 * The test suite. Run it with:
 *
 *     php tests/run.php
 *     composer test
 *
 * No install and no network needed, which is deliberate: this package has no
 * dependencies, and a test suite that needs one would be the only reason to
 * run composer install.
 */

namespace BestTempMail\Tests;

use BestTempMail\ApiException;
use BestTempMail\Attachment;
use BestTempMail\BulkCreateOptions;
use BestTempMail\Client;
use BestTempMail\Confidence;
use BestTempMail\CreateInboxOptions;
use BestTempMail\Inbox;
use BestTempMail\InvalidSignatureException;
use BestTempMail\LinkType;
use BestTempMail\ListOptions;
use BestTempMail\NoMessageException;
use BestTempMail\Response;
use BestTempMail\Timestamps;
use BestTempMail\UnexpectedResponseException;
use BestTempMail\WaitOptions;
use BestTempMail\Webhook;

$root = dirname(__DIR__);

spl_autoload_register(static function (string $class) use ($root): void {
    foreach (['BestTempMail\\Tests\\' => $root . '/tests/', 'BestTempMail\\' => $root . '/src/'] as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $path = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($path)) {
                require $path;

                return;
            }
        }
    }
});

/* ===========================================================================
 *  A very small harness
 * ========================================================================= */

final class Runner
{
    public static int $passed = 0;

    /** @var list<string> */
    public static array $failures = [];

    public static string $current = '';
}

function test(string $name, callable $body): void
{
    Runner::$current = $name;
    try {
        $body();
        Runner::$passed++;
        echo "  \033[32m✓\033[0m {$name}\n";
    } catch (\Throwable $e) {
        Runner::$failures[] = $name . "\n      " . $e->getMessage();
        echo "  \033[31m✗\033[0m {$name}\n      " . $e->getMessage() . "\n";
    }
}

function fail(string $why): never
{
    throw new \RuntimeException($why);
}

function assertSame(mixed $expected, mixed $actual, string $what = 'value'): void
{
    if ($expected !== $actual) {
        fail(sprintf(
            '%s: expected %s, got %s',
            $what,
            var_export($expected, true),
            var_export($actual, true),
        ));
    }
}

function assertTrue(bool $condition, string $what): void
{
    if (!$condition) {
        fail('expected true: ' . $what);
    }
}

function assertFalse(bool $condition, string $what): void
{
    if ($condition) {
        fail('expected false: ' . $what);
    }
}

function assertContains(string $needle, string $haystack, string $what = 'string'): void
{
    if (!str_contains($haystack, $needle)) {
        fail(sprintf('%s: expected to contain %s, got %s', $what, var_export($needle, true), var_export($haystack, true)));
    }
}

/**
 * @param  class-string<\Throwable>  $class
 * @return \Throwable the exception, so the caller can inspect it
 */
function assertThrows(string $class, callable $body, string $what = ''): \Throwable
{
    try {
        $body();
    } catch (\Throwable $e) {
        if (!$e instanceof $class) {
            fail(sprintf('expected %s but got %s: %s', $class, get_class($e), $e->getMessage()));
        }

        return $e;
    }

    fail('expected ' . $class . ' to be thrown' . ($what !== '' ? ': ' . $what : ''));
}

function section(string $title): void
{
    echo "\n\033[1m{$title}\033[0m\n";
}

/** A client wired to a fake transport. */
function clientWith(callable $handler, ?FakeTransport &$transport = null): Client
{
    $transport = new FakeTransport($handler);

    return new Client(
        apiKey: 'btm_sk_test_abcd',
        baseUrl: 'https://api.example.test/v1',
        transport: $transport,
    );
}

/* ===========================================================================
 *  Timestamps: two formats, one of them with no timezone
 * ========================================================================= */

section('Timestamps');

test('RFC 3339 with milliseconds parses', function (): void {
    $parsed = Timestamps::parse('2026-07-04T20:01:00.000Z');
    assertSame('2026-07-04T20:01:00Z', $parsed?->format('Y-m-d\TH:i:s\Z'));
});

test('an offset is converted to UTC', function (): void {
    $parsed = Timestamps::parse('2026-07-04T20:01:00+05:00');
    assertSame('2026-07-04T15:01:00Z', $parsed?->format('Y-m-d\TH:i:s\Z'));
});

test('the space-separated form is read as UTC, not as local time', function (): void {
    // Verified against the live API: an inbox created at 19:03:38 Pakistan
    // time, UTC+05:00, reported 14:03:38. Reading this in the process timezone
    // would put every expiry five hours out, and on a two-hour inbox that
    // means an expiry in the past.
    $previous = date_default_timezone_get();
    date_default_timezone_set('Asia/Karachi');
    try {
        $parsed = Timestamps::parse('2026-11-17 14:03:38');
        assertSame('2026-11-17T14:03:38Z', $parsed?->format('Y-m-d\TH:i:s\Z'));
        assertSame('UTC', $parsed?->getTimezone()->getName());
    } finally {
        date_default_timezone_set($previous);
    }
});

test('null, empty and garbage give null rather than throwing', function (): void {
    assertSame(null, Timestamps::parse(null));
    assertSame(null, Timestamps::parse(''));
    assertSame(null, Timestamps::parse('   '));
    assertSame(null, Timestamps::parse('not a date at all'));
    assertSame(null, Timestamps::parse(12345));
});

/* ===========================================================================
 *  Inboxes
 * ========================================================================= */

section('Inboxes');

test('createInbox sends no body when nothing was asked for', function (): void {
    $client = clientWith(
        fn () => FakeTransport::json([
            'success' => true,
            'address' => 'myprefix@alagen.site',
            'created_at' => '2026-11-17 14:03:38',
            'expires_at' => '2026-11-17 16:03:38',
            'lifetime_minutes' => 120,
        ]),
        $transport,
    );

    $inbox = $client->createInbox();
    assertSame('myprefix@alagen.site', $inbox->address);
    assertSame(120, $inbox->lifetimeMinutes);
    assertSame('2026-11-17T16:03:38Z', $inbox->expiresAt?->format('Y-m-d\TH:i:s\Z'));
    assertSame(null, $transport->requests[0]['body'], 'request body');
});

test('createInbox sends only the fields that were set', function (): void {
    $client = clientWith(fn () => FakeTransport::json(['address' => 'chosen@alagen.site']), $transport);

    $client->createInbox(new CreateInboxOptions(username: 'chosen'));

    $body = (string) $transport->requests[0]['body'];
    assertContains('"username":"chosen"', $body);
    assertFalse(str_contains($body, 'domain'), 'unset domain was sent');
    assertFalse(str_contains($body, 'lifetime_minutes'), 'unset lifetime was sent');
});

test('createInbox rejects a success response with no address', function (): void {
    $client = clientWith(fn () => FakeTransport::json(['success' => true]));
    assertThrows(UnexpectedResponseException::class, fn () => $client->createInbox());
});

test('an API key header is sent, and omitted entirely when there is no key', function (): void {
    $withKey = clientWith(fn () => FakeTransport::json(['address' => 'a@b.site']), $keyed);
    $withKey->createInbox();
    assertSame('btm_sk_test_abcd', $keyed->requests[0]['headers']['x-api-key'] ?? null, 'x-api-key');

    $anon = new Client(null, 'https://api.example.test/v1', $plain = new FakeTransport(
        fn () => FakeTransport::json(['address' => 'a@b.site']),
    ));
    $anon->createInbox();
    assertFalse(
        array_key_exists('x-api-key', $plain->requests[0]['headers']),
        'a keyless client sent an x-api-key header',
    );
});

test('deleteInbox treats an already-absent inbox as success', function (): void {
    // Cleanup runs on the way out, often after the thing it is cleaning up has
    // expired. Turning a passing test red for tidying up would be a bad trade.
    $client = clientWith(fn () => FakeTransport::json(['error' => 'Inbox not found'], 404));
    $client->deleteInbox('gone@alagen.site');
});

test('withInbox deletes the inbox even when the callback throws', function (): void {
    $client = clientWith(function (string $method, string $url) {
        if ($method === 'DELETE') {
            return FakeTransport::json(['success' => true, 'deleted' => true]);
        }

        return FakeTransport::json(['address' => 'temp@alagen.site']);
    }, $transport);

    assertThrows(\LogicException::class, function () use ($client): void {
        $client->withInbox(function (Inbox $inbox): never {
            throw new \LogicException('the test failed, as tests do');
        });
    });

    assertTrue($transport->called('DELETE /v1/inboxes/temp@alagen.site'), 'the inbox was not cleaned up');
});

test('withInbox passes the callback return value through', function (): void {
    $client = clientWith(fn () => FakeTransport::json(['address' => 'temp@alagen.site']));
    assertSame('done', $client->withInbox(fn (Inbox $inbox) => 'done'));
});

test('bulk create rejects an impossible count without calling the API', function (): void {
    $client = clientWith(function (): Response {
        fail('the API must not be called for a count that cannot be valid');
    }, $transport);

    foreach ([0, -1, BulkCreateOptions::MAX_COUNT + 1] as $count) {
        assertThrows(\InvalidArgumentException::class, fn () => $client->bulkCreateInboxes($count));
    }
    assertSame(0, count($transport->requests), 'requests made');
});

test('bulk create reports the quota it charged', function (): void {
    $client = clientWith(fn () => FakeTransport::json([
        'success' => true,
        'count' => 2,
        'quota_charged' => 2,
        'lifetime_minutes' => 120,
        'inboxes' => [
            ['address' => 'fosilu8914@simtim.site', 'created_at' => '2026-11-17 14:03:38'],
            ['address' => 'dexihi2407@linsal.site', 'created_at' => '2026-11-17 14:03:38'],
        ],
    ]), $transport);

    $batch = $client->bulkCreateInboxes(2);
    assertSame(2, $batch->quotaCharged);
    assertSame(['fosilu8914@simtim.site', 'dexihi2407@linsal.site'], $batch->addresses());
    assertSame(2, count($batch));
    assertContains('"count":2', (string) $transport->requests[0]['body']);
});

test('a plus-addressed inbox survives the URL path', function (): void {
    // Mail to user+tag@domain arrives at user@domain. The plus must reach the
    // server as a plus and not as an encoded space.
    $client = clientWith(fn () => FakeTransport::json(['success' => true, 'messages' => []]), $transport);
    $client->messages('user+netflix@alagen.site');

    $url = $transport->requests[0]['url'];
    assertContains('user%2Bnetflix@alagen.site', $url, 'encoded address');
    assertSame(
        'user+netflix@alagen.site',
        rawurldecode((string) parse_url($url, PHP_URL_PATH)) === '/v1/inboxes/user+netflix@alagen.site/messages'
            ? 'user+netflix@alagen.site'
            : 'MANGLED',
        'the address does not decode back to itself',
    );
});

test('Inbox::tagged builds a plus address', function (): void {
    $inbox = new Inbox('someone@alagen.site');
    assertSame('someone+netflix@alagen.site', $inbox->tagged('netflix'));
    assertSame('someone', $inbox->username());
    assertSame('alagen.site', $inbox->domain());
});

/* ===========================================================================
 *  Listing, filtering, paging
 * ========================================================================= */

section('Message listing');

test('every query parameter is built', function (): void {
    $client = clientWith(fn () => FakeTransport::json(['success' => true, 'messages' => []]), $transport);

    $client->messages('a@b.site', new ListOptions(
        limit: 20,
        offset: 40,
        from: 'service.com',
        subject: 'verify',
        q: 'ORD-48812',
        since: new \DateTimeImmutable('2026-07-04 00:00:00', new \DateTimeZone('UTC')),
        until: new \DateTimeImmutable('2026-07-05 00:00:00', new \DateTimeZone('UTC')),
        hasAttachments: true,
    ));

    $query = (string) parse_url($transport->requests[0]['url'], PHP_URL_QUERY);
    parse_str($query, $parsed);

    assertSame('20', $parsed['limit'] ?? null, 'limit');
    assertSame('40', $parsed['offset'] ?? null, 'offset');
    assertSame('service.com', $parsed['from'] ?? null, 'from');
    assertSame('verify', $parsed['subject'] ?? null, 'subject');
    assertSame('ORD-48812', $parsed['q'] ?? null, 'q');
    assertSame('2026-07-04T00:00:00Z', $parsed['since'] ?? null, 'since');
    assertSame('2026-07-05T00:00:00Z', $parsed['until'] ?? null, 'until');
    assertSame('true', $parsed['has_attachments'] ?? null, 'has_attachments');
});

test('no options means no query string at all', function (): void {
    $client = clientWith(fn () => FakeTransport::json(['success' => true, 'messages' => []]), $transport);
    $client->messages('a@b.site');
    assertSame(null, parse_url($transport->requests[0]['url'], PHP_URL_QUERY), 'query string');
});

test('hasAttachments false is sent, and is not the same as unset', function (): void {
    $client = clientWith(fn () => FakeTransport::json(['success' => true, 'messages' => []]), $transport);
    $client->messages('a@b.site', new ListOptions(hasAttachments: false));
    assertContains('has_attachments=false', (string) parse_url($transport->requests[0]['url'], PHP_URL_QUERY));
});

test('out-of-range paging is refused before any request', function (): void {
    $cases = [
        fn () => new ListOptions(limit: ListOptions::MAX_LIMIT + 1),
        fn () => new ListOptions(limit: 0),
        fn () => new ListOptions(offset: ListOptions::MAX_OFFSET + 1),
        fn () => new ListOptions(offset: -1),
    ];
    foreach ($cases as $make) {
        assertThrows(\InvalidArgumentException::class, $make);
    }
});

test('paging and scan fields parse', function (): void {
    $client = clientWith(fn () => FakeTransport::json([
        'success' => true,
        'address' => 'a@b.site',
        'messages' => [[
            'id' => 'm1',
            'from' => '"Example" <x@y.com>',
            'subject' => 'hi',
            'date' => '2026-07-04T20:01:00.000Z',
            'has_attachments' => false,
        ]],
        'limit' => 100,
        'offset' => 0,
        'returned' => 1,
        'has_more' => true,
        'scanned' => 250,
        'scan_limit_reached' => true,
    ]));

    $list = $client->messages('a@b.site');
    assertTrue($list->hasMore, 'has_more');
    assertSame(250, $list->scanned);
    assertTrue($list->scanLimitReached, 'scan_limit_reached');
    assertSame(1, count($list));
    assertSame('x@y.com', $list->first()?->fromAddress(), 'sender address stripped of its display name');
});

test('an empty result distinguishes "nothing matches" from "search exhausted"', function (): void {
    $conclusive = clientWith(fn () => FakeTransport::json([
        'success' => true, 'messages' => [], 'scan_limit_reached' => false,
    ]))->messages('a@b.site');
    assertTrue($conclusive->isConclusivelyEmpty(), 'should be a definite empty');

    $exhausted = clientWith(fn () => FakeTransport::json([
        'success' => true, 'messages' => [], 'scan_limit_reached' => true,
    ]))->messages('a@b.site');
    assertFalse($exhausted->isConclusivelyEmpty(), 'an exhausted search is not a definite empty');
});

test('a full message reads the nested object and its attachments', function (): void {
    // This attachment JSON is verbatim from a real message on 18 November 2026.
    // The field names were guessed wrong first time: content_type instead of
    // mime, with index, downloadable and requires_plan missed entirely.
    $client = clientWith(fn () => FakeTransport::json([
        'success' => true,
        'message' => [
            'id' => 'fdf1ed83d063fb5e',
            'from' => '"Billing" <billing@example.com>',
            'to' => 'wexugo9519@alagen.site',
            'subject' => 'Your invoice is attached',
            'date' => '2026-11-18T12:27:32.000Z',
            'text' => "Your invoice is attached.\n\nVerification code: 558219\n",
            'attachments' => [
                ['index' => 0, 'filename' => 'invoice.pdf', 'mime' => 'application/pdf', 'size' => 69, 'downloadable' => false, 'requires_plan' => 'pro'],
                ['index' => 1, 'filename' => 'items.csv', 'mime' => 'text/csv', 'size' => 22, 'downloadable' => false, 'requires_plan' => 'pro'],
            ],
        ],
    ]));

    $message = $client->message('wexugo9519@alagen.site', 'fdf1ed83d063fb5e');
    assertSame('Your invoice is attached', $message->subject);
    assertSame('wexugo9519@alagen.site', $message->to);
    assertContains('558219', $message->text);
    assertSame(2, count($message->attachments));

    $pdf = $message->attachment('invoice.pdf');
    assertSame(0, $pdf?->index);
    assertSame('application/pdf', $pdf?->mime);
    assertSame(69, $pdf?->size);
    assertFalse((bool) $pdf?->downloadable, 'downloadable on a non-Pro plan');
    assertSame('pro', $pdf?->requiresPlan);
    assertSame(null, $message->attachment('nope.txt'), 'a missing attachment');
});

/* ===========================================================================
 *  Extraction
 * ========================================================================= */

section('Extraction');

test('a code keeps its leading zero', function (): void {
    $client = clientWith(fn () => FakeTransport::json([
        'success' => true,
        'message_id' => 'm1',
        'code' => '004007',
        'confidence' => 'high',
        'candidates' => [['code' => '004007', 'score' => 6], ['code' => '293679', 'score' => 2]],
    ]));

    $otp = $client->otp('a@b.site', 'm1');
    assertSame('004007', $otp->code, 'a leading zero is why this is a string and not an int');
    assertSame(Confidence::High, $otp->confidence);
    assertTrue($otp->confidence->isConfident(), 'high should count as confident');
    assertSame(2, count($otp->candidates));
    assertTrue($otp->found(), 'found');
});

test('a null code becomes an empty string with no confidence', function (): void {
    $client = clientWith(fn () => FakeTransport::json([
        'success' => true, 'message_id' => 'm1', 'code' => null, 'confidence' => 'none',
    ]));

    $otp = $client->otp('a@b.site', 'm1');
    assertSame('', $otp->code);
    assertFalse($otp->found(), 'found');
    assertSame(Confidence::None, $otp->confidence);
});

test('an unrecognised confidence or link type does not throw', function (): void {
    // A new value appearing server-side must not break an existing test suite.
    assertSame(Confidence::None, Confidence::parse('extremely-sure'));
    assertSame(LinkType::Other, LinkType::parse('something_new'));
    assertSame(LinkType::Other, LinkType::parse(null));
});

test('links are typed, and a tracker is unwrapped', function (): void {
    $client = clientWith(fn () => FakeTransport::json([
        'success' => true,
        'message_id' => 'm1',
        'action_url' => 'https://example.com/verify?token=abc',
        'confidence' => 'high',
        'links' => [
            ['url' => 'https://click.tracker/xyz', 'unwrapped_url' => 'https://example.com/verify?token=abc', 'text' => 'Confirm', 'type' => 'action', 'score' => 9],
            ['url' => 'https://example.com/unsubscribe', 'unwrapped_url' => null, 'text' => 'Unsubscribe', 'type' => 'unsubscribe', 'score' => 0],
        ],
    ]));

    $links = $client->links('a@b.site', 'm1');
    assertSame('https://example.com/verify?token=abc', $links->actionUrl);
    assertSame('https://example.com/verify?token=abc', $links->links[0]->destination(), 'destination prefers the unwrapped URL');
    assertSame('https://example.com/unsubscribe', $links->links[1]->destination(), 'destination falls back to the URL');
    assertSame(1, count($links->actionLinks()));
    assertSame(1, count($links->ofType(LinkType::Unsubscribe)));
    assertSame(2, count($links));
});

test('downloadAttachment refuses before making a request when the plan forbids it', function (): void {
    $client = clientWith(function (): Response {
        fail('should not request an attachment the plan cannot fetch');
    }, $transport);

    $attachment = new Attachment(0, 'invoice.pdf', 'application/pdf', 69, downloadable: false, requiresPlan: 'pro');
    $e = assertThrows(
        ApiException::class,
        fn () => $client->downloadAttachment('a@b.site', 'm1', $attachment),
    );

    assertTrue($e instanceof ApiException && $e->isPaymentRequired(), 'should look like a 402');
    assertContains('invoice.pdf', $e->getMessage());
    assertContains('pro', $e->getMessage());
    assertSame(0, count($transport->requests), 'requests made');
});

test('downloadAttachment uses the index the server gave, not the array position', function (): void {
    $client = clientWith(fn () => new Response(200, 'name,amount', [
        'content-type' => 'text/csv',
        'content-disposition' => 'attachment; filename="items.csv"',
    ]), $transport);

    $attachment = new Attachment(3, 'items.csv', 'text/csv', 22, downloadable: true);
    $file = $client->downloadAttachment('a@b.site', 'm1', $attachment);

    assertTrue($transport->called('/attachments/3'), 'wrong index: ' . implode(', ', $transport->calls()));
    assertSame('items.csv', $file->filename);
    assertSame('text/csv', $file->mime);
    assertSame(11, $file->size());
});

test('a negative attachment index is refused locally', function (): void {
    $client = clientWith(function (): Response {
        fail('should not request a negative index');
    });
    assertThrows(\InvalidArgumentException::class, fn () => $client->attachment('a@b.site', 'm1', -1));
});

/* ===========================================================================
 *  THE REGRESSION TESTS
 *
 *  These mocks REFUSE to answer the long poll. If the client ever stops
 *  checking the inbox first, they fail.
 * ========================================================================= */

section('Waiting: mail that had already arrived');

test('waitForMessage finds a message that was already in the inbox', function (): void {
    $client = clientWith(function (string $method, string $url): Response {
        if (str_contains($url, '/wait')) {
            fail('the client long-polled instead of looking at what was already in the inbox');
        }

        return FakeTransport::json([
            'success' => true,
            'messages' => [['id' => 'already-here', 'subject' => 'Please confirm']],
            'returned' => 1,
        ]);
    }, $transport);

    $message = $client->waitForMessage('a@b.site');
    assertSame('already-here', $message->id);
    assertFalse($transport->called('/wait'), 'the long poll was called');
});

test('waitForCode finds a code in mail that had already arrived', function (): void {
    $client = clientWith(function (string $method, string $url): Response {
        if (str_contains($url, '/wait')) {
            fail('the client long-polled instead of looking at what was already in the inbox');
        }
        if (str_contains($url, '/otp')) {
            return FakeTransport::json(['success' => true, 'message_id' => 'already-here', 'code' => '004007', 'confidence' => 'high']);
        }

        return FakeTransport::json(['success' => true, 'messages' => [['id' => 'already-here']]]);
    }, $transport);

    assertSame('004007', $client->waitForCode('a@b.site'));
    assertFalse($transport->called('/wait'), 'the long poll was called');
});

section('Waiting: the rest');

test('waitForMessage long-polls when the inbox is empty, and goes round after a timeout', function (): void {
    $waits = 0;
    $client = clientWith(function (string $method, string $url) use (&$waits): Response {
        if (!str_contains($url, '/wait')) {
            return FakeTransport::json(['success' => true, 'messages' => []]);
        }
        $waits++;

        // A server-side timeout is a 200 with timed_out true, not a failure.
        // The client must go round rather than giving up.
        if ($waits === 1) {
            return FakeTransport::json(['success' => true, 'message' => null, 'timed_out' => true]);
        }

        return FakeTransport::json(['success' => true, 'message' => ['id' => 'arrived', 'subject' => 'Verify']]);
    }, $transport);

    $message = $client->waitForMessage('a@b.site', new WaitOptions(timeout: 10.0));
    assertSame('arrived', $message->id);
    assertSame(2, $transport->countCalls('/wait'), 'long polls');
});

test('the long poll asks for a timeout the server will actually honour', function (): void {
    $client = clientWith(fn () => FakeTransport::json(['success' => true, 'message' => ['id' => 'm1']]), $transport);
    $client->wait('a@b.site', 600.0);
    assertTrue($transport->called('timeout=55'), 'should clamp to 55s: ' . implode(', ', $transport->calls()));
});

test('waitForMessage never long-polls when a filter is set', function (): void {
    // The long poll cannot filter. It would return the first message to
    // arrive, which in a real signup is often the welcome email rather than
    // the verification email.
    $polls = 0;
    $client = clientWith(function (string $method, string $url) use (&$polls): Response {
        if (str_contains($url, '/wait')) {
            fail('the long poll cannot filter and must not be used when a filter is set');
        }
        assertContains('subject=verify', (string) parse_url($url, PHP_URL_QUERY), 'the filter was dropped');
        $polls++;

        return $polls < 2
            ? FakeTransport::json(['success' => true, 'messages' => []])
            : FakeTransport::json(['success' => true, 'messages' => [['id' => 'filtered', 'subject' => 'verify your email']]]);
    }, $transport);

    $message = $client->waitForMessage('a@b.site', new WaitOptions(subject: 'verify', timeout: 8.0));
    assertSame('filtered', $message->id);
    assertFalse($transport->called('/wait'), 'the long poll was called');
});

test('a wait that finds nothing throws, and names the inbox', function (): void {
    $client = clientWith(function (string $method, string $url): Response {
        if (str_contains($url, '/wait')) {
            return FakeTransport::json(['success' => true, 'message' => null, 'timed_out' => true]);
        }

        return FakeTransport::json(['success' => true, 'messages' => []]);
    });

    $e = assertThrows(
        NoMessageException::class,
        fn () => $client->waitForMessage('watched@alagen.site', new WaitOptions(timeout: 0.3)),
    );
    // A test failing on a timeout should say which inbox it was watching.
    assertContains('watched@alagen.site', $e->getMessage());
});

test('waitForCode treats a message with no code as an error', function (): void {
    // A caller asking for a bare string has nowhere to put "the mail came but
    // held no code", and would otherwise type an empty string into the form
    // under test.
    $client = clientWith(function (string $method, string $url): Response {
        if (str_contains($url, '/otp')) {
            return FakeTransport::json(['success' => true, 'message_id' => 'm1', 'code' => null, 'confidence' => 'none']);
        }

        return FakeTransport::json(['success' => true, 'messages' => [['id' => 'm1', 'subject' => 'Newsletter']]]);
    });

    $e = assertThrows(UnexpectedResponseException::class, fn () => $client->waitForCode('a@b.site'));
    assertContains('no verification code', $e->getMessage());
});

test('waitForOtp does not throw when there was no code, because the mail did arrive', function (): void {
    $client = clientWith(function (string $method, string $url): Response {
        if (str_contains($url, '/otp')) {
            return FakeTransport::json(['success' => true, 'message_id' => 'm1', 'code' => null, 'confidence' => 'none', 'candidates' => []]);
        }

        return FakeTransport::json(['success' => true, 'messages' => [['id' => 'm1']]]);
    });

    $otp = $client->waitForOtp('a@b.site');
    assertSame('', $otp->code);
    assertSame(Confidence::None, $otp->confidence);
});

test('waitForActionUrl errors rather than guessing a link', function (): void {
    $client = clientWith(function (string $method, string $url): Response {
        if (str_contains($url, '/links')) {
            return FakeTransport::json(['success' => true, 'message_id' => 'm1', 'action_url' => null, 'confidence' => 'none', 'links' => []]);
        }

        return FakeTransport::json(['success' => true, 'messages' => [['id' => 'm1']]]);
    });

    assertThrows(UnexpectedResponseException::class, fn () => $client->waitForActionUrl('a@b.site'));
});

test('the long poll gets a request timeout above the wait itself', function (): void {
    // An HTTP timeout below the long poll would cut the wait off mid-flight,
    // and the failure would look like a network fault.
    $client = clientWith(fn () => FakeTransport::json(['success' => true, 'message' => ['id' => 'm1']]), $transport);
    $client->wait('a@b.site', 55.0);
    $timeout = $transport->requests[0]['timeout'];
    assertTrue($timeout > 55.0, sprintf('request timeout %.1f should exceed the 55s wait', $timeout));
});

/* ===========================================================================
 *  Errors
 * ========================================================================= */

section('Errors');

test('each status is classified by its own helper', function (): void {
    $cases = [
        401 => 'isUnauthorized',
        402 => 'isPaymentRequired',
        404 => 'isNotFound',
        413 => 'isTooLarge',
        429 => 'isRateLimited',
    ];

    foreach ($cases as $status => $method) {
        $client = clientWith(fn () => FakeTransport::json(['error' => 'nope', 'request_id' => 'req-123'], $status));
        $e = assertThrows(ApiException::class, fn () => $client->inbox('a@b.site'));
        assertTrue($e instanceof ApiException && $e->status === $status, "status for {$status}");
        assertTrue($e instanceof ApiException && $e->{$method}(), "{$method}() for {$status}");
        // The request id has to be in the message: it is the only thing a
        // support email can usefully quote.
        assertContains('req-123', $e->getMessage());
    }
});

test('retry-after and the upgrade link are carried through', function (): void {
    $client = clientWith(fn () => FakeTransport::json(
        ['error' => 'rate limited', 'upgrade' => 'https://best-tempmail.com/api/pricing'],
        429,
        ['retry-after' => '42'],
    ));

    $e = assertThrows(ApiException::class, fn () => $client->inbox('a@b.site'));
    assertSame(42, $e instanceof ApiException ? $e->retryAfterSeconds : null);
    assertContains('pricing', $e->getMessage());
});

test('a non-JSON error body still produces a useful message', function (): void {
    // A misbehaving proxy returns HTML, and "502" on its own tells the reader
    // nothing about where it came from.
    $client = clientWith(fn () => new Response(502, '<html><body>Bad Gateway</body></html>'));
    $e = assertThrows(ApiException::class, fn () => $client->inbox('a@b.site'));
    assertContains('502', $e->getMessage());
    assertContains('Bad Gateway', $e->getMessage());
});

test('an empty error body does not produce a message ending in a colon', function (): void {
    $client = clientWith(fn () => new Response(500, ''));
    $e = assertThrows(ApiException::class, fn () => $client->inbox('a@b.site'));
    assertContains('no explanation', $e->getMessage());
});

/* ===========================================================================
 *  Usage
 * ========================================================================= */

section('Usage');

test('a keyed call carries the key and subscription blocks', function (): void {
    $client = clientWith(fn () => FakeTransport::json([
        'success' => true, 'plan' => 'founders', 'paid' => true,
        'hourly' => ['limit' => 2000, 'used' => 15, 'remaining' => 1985, 'resets_at' => '2026-11-17T14:19:05.000Z', 'resets_in_seconds' => 927],
        'lifetime_requests' => 9766,
        'key' => ['id' => 3, 'last_four' => '4cf2', 'status' => 'active', 'created_at' => '2026-08-17T07:35:58.000Z'],
        'subscription' => ['status' => 'active', 'expires_at' => '2027-08-17T07:25:26.000Z', 'days_remaining' => 273, 'in_grace_period' => false],
    ]));

    $usage = $client->usage();
    assertTrue($usage->paid, 'paid');
    assertSame(1985, $usage->hourly->remaining);
    assertFalse($usage->hourly->nearlyExhausted(), 'nearly exhausted with 1985 left');
    assertSame('4cf2', $usage->key?->lastFour);
    assertTrue((bool) $usage->key?->isActive(), 'key active');
    assertSame(273, $usage->subscription?->daysRemaining);
    assertFalse((bool) $usage->subscription?->expiringWithinDays(14), 'not expiring soon');
    assertSame(null, $usage->inboxesCreatedToday, 'a paid plan has no daily cap');
});

test('a keyless call reports the free tier, and zero is not the same as null', function (): void {
    $client = clientWith(fn () => FakeTransport::json([
        'success' => true, 'plan' => 'free', 'paid' => false,
        'hourly' => ['limit' => 150, 'used' => 1, 'remaining' => 149, 'resets_in_seconds' => 3600],
        'inboxes_created_today' => 0,
        'upgrade' => 'https://best-tempmail.com/api/pricing',
    ]));

    $usage = $client->usage();
    assertFalse($usage->paid, 'paid');
    assertSame('free', $usage->plan);
    // Null means "no daily cap applies"; zero means "the cap applies and none
    // have been used".
    assertSame(0, $usage->inboxesCreatedToday);
    assertSame(null, $usage->key, 'a keyless caller has no key block');
    assertSame(null, $usage->subscription, 'a keyless caller has no subscription block');
    assertContains('pricing', (string) $usage->upgradeUrl);
});

/* ===========================================================================
 *  Webhooks
 * ========================================================================= */

section('Webhooks');

/** @return array<string, string> */
function signedHeaders(string $secret, string $timestamp, string $body): array
{
    return [
        Webhook::TIMESTAMP_HEADER => $timestamp,
        Webhook::SIGNATURE_HEADER => 'v1,' . base64_encode(hash_hmac('sha256', $timestamp . '.' . $body, $secret, true)),
    ];
}

test('a genuine delivery verifies and decodes', function (): void {
    $secret = 'whsec_test';
    $body = '{"event":"message.received","timestamp":1788770115,"address":"a@b.site","message":{"id":"m1","subject":"Your code"}}';
    $now = (string) time();

    $event = Webhook::parse($secret, signedHeaders($secret, $now, $body), $body);
    assertSame('message.received', $event->event);
    assertSame('m1', $event->message->id);
    assertSame('a@b.site', $event->address);
});

test('a tampered body is rejected', function (): void {
    // This is the attack the signature exists to stop: without it, anyone who
    // learns the URL could post a verification code of their choosing.
    $secret = 'whsec_test';
    $original = '{"event":"message.received","address":"a@b.site"}';
    $now = (string) time();
    $headers = signedHeaders($secret, $now, $original);

    $tampered = '{"event":"message.received","address":"attacker@evil.site"}';
    assertThrows(InvalidSignatureException::class, fn () => Webhook::parse($secret, $headers, $tampered));
});

test('the wrong secret, missing headers and an empty secret are all refused', function (): void {
    $body = '{"event":"message.received"}';
    $now = (string) time();

    assertThrows(
        InvalidSignatureException::class,
        fn () => Webhook::parse('whsec_other', signedHeaders('whsec_test', $now, $body), $body),
    );
    assertThrows(InvalidSignatureException::class, fn () => Webhook::parse('whsec_test', [], $body));
    assertThrows(
        \InvalidArgumentException::class,
        fn () => Webhook::parse('', signedHeaders('whsec_test', $now, $body), $body),
    );
});

test('a replayed delivery is rejected, and the window can be switched off', function (): void {
    $secret = 'whsec_test';
    $body = '{"event":"message.received"}';
    $old = (string) (time() - 7200);
    $headers = signedHeaders($secret, $old, $body);

    assertThrows(InvalidSignatureException::class, fn () => Webhook::parse($secret, $headers, $body));

    // With the window off the same delivery verifies, which proves the
    // rejection was the timestamp and not the signature.
    $event = Webhook::parse($secret, $headers, $body, toleranceSeconds: 0);
    assertSame('message.received', $event->event);
});

test('header capitalisation does not matter, and a bare signature still verifies', function (): void {
    $secret = 'whsec_test';
    $body = '{"event":"message.received"}';
    $now = (string) time();
    $full = signedHeaders($secret, $now, $body)[Webhook::SIGNATURE_HEADER];

    $headers = [
        'x-btm-timestamp' => $now,
        'X-BTM-SIGNATURE' => str_replace('v1,', '', $full),
    ];
    $event = Webhook::parse($secret, $headers, $body);
    assertSame('message.received', $event->event);
});

test('fromServer reads the HTTP_ prefixed variables', function (): void {
    $secret = 'whsec_test';
    $body = '{"event":"message.received","address":"a@b.site"}';
    $now = (string) time();
    $signed = signedHeaders($secret, $now, $body);

    $server = [
        'REQUEST_METHOD' => 'POST',
        'HTTP_X_BTM_TIMESTAMP' => $now,
        'HTTP_X_BTM_SIGNATURE' => $signed[Webhook::SIGNATURE_HEADER],
    ];

    $event = Webhook::fromServer($secret, $server, $body);
    assertSame('a@b.site', $event->address);
});

test('a verified signature over a non-JSON body is reported as a payload problem', function (): void {
    // Distinct from a signature failure: the sender is who they say they are,
    // but what they sent cannot be read.
    $secret = 'whsec_test';
    $body = 'this is not json';
    $now = (string) time();

    $e = assertThrows(
        UnexpectedResponseException::class,
        fn () => Webhook::parse($secret, signedHeaders($secret, $now, $body), $body),
    );
    assertContains('would not decode', $e->getMessage());
});

/* ===========================================================================
 *  A whole flow
 * ========================================================================= */

section('End to end against the fake');

test('the shape a real test would use', function (): void {
    $signedUp = false;

    $client = clientWith(function (string $method, string $url) use (&$signedUp): Response {
        if ($method === 'POST' && str_ends_with((string) parse_url($url, PHP_URL_PATH), '/inboxes')) {
            return FakeTransport::json(['success' => true, 'address' => 'flow@alagen.site', 'expires_at' => '2026-11-17 16:03:38']);
        }
        if ($method === 'DELETE') {
            return FakeTransport::json(['success' => true, 'deleted' => true]);
        }
        if (str_contains($url, '/otp')) {
            return FakeTransport::json(['success' => true, 'message_id' => 'm9', 'code' => '471902', 'confidence' => 'high']);
        }
        if (str_contains($url, '/links')) {
            return FakeTransport::json([
                'success' => true, 'message_id' => 'm9',
                'action_url' => 'https://example.com/verify?token=Xk39',
                'confidence' => 'high',
                'links' => [['url' => 'https://example.com/verify?token=Xk39', 'type' => 'action', 'score' => 9]],
            ]);
        }
        if (!$signedUp) {
            return FakeTransport::json(['success' => true, 'messages' => [], 'scan_limit_reached' => false]);
        }

        return FakeTransport::json(['success' => true, 'messages' => [[
            'id' => 'm9',
            'from' => '"Example" <no-reply@example.com>',
            'subject' => 'Please confirm your email address',
        ]]]);
    }, $transport);

    [$code, $link] = $client->withInbox(function (Inbox $inbox) use ($client, &$signedUp): array {
        assertSame('flow@alagen.site', $inbox->address);

        // the application under test receives the address and sends mail
        $signedUp = true;

        return [
            $client->waitForCode($inbox->address, new WaitOptions(subject: 'confirm', timeout: 5.0)),
            $client->waitForActionUrl($inbox->address, new WaitOptions(timeout: 5.0)),
        ];
    });

    assertSame('471902', $code);
    assertContains('example.com/verify', $link);
    assertTrue($transport->called('DELETE /v1/inboxes/flow@alagen.site'), 'the inbox was not cleaned up');
});

/* ===========================================================================
 *  The live suite.
 *
 *  Everything above runs against a fake I wrote, which means it agrees with
 *  whatever I assumed. That is not a hypothetical weakness: the GitHub Action
 *  for this API passed 21 mocked tests and then failed on the first real
 *  email, and the attachment field names in these very SDKs were guessed wrong
 *  until a real message was inspected.
 *
 *  Set BTM_API_KEY to run these as well:
 *
 *      BTM_API_KEY=btm_sk_live_... php tests/run.php
 *
 *  WHAT THIS CANNOT COVER: nothing here receives an email. A CI runner cannot
 *  send SMTP to the inbox, so OTP extraction, link extraction and the
 *  already-arrived branch are not exercised against real mail. Those response
 *  shapes were captured from the live API by hand and are asserted as verbatim
 *  fixtures above. Real end-to-end delivery is tested from the mail server.
 *
 *  Cost: about a dozen requests and four inboxes of quota.
 * ========================================================================= */

$liveKey = getenv('BTM_API_KEY');

if (!is_string($liveKey) || $liveKey === '') {
    echo "\n\033[2mBTM_API_KEY not set, skipping the live suite\033[0m\n";
} else {
    section('Live API');

    $live = new Client($liveKey);

    test('the service is reachable and healthy', function () use ($live): void {
        assertTrue($live->health()->ok(), 'health');
    });

    test('the key is accepted and the quota parses', function () use ($live): void {
        $usage = $live->usage();
        assertTrue($usage->paid, 'a paid plan was expected, got ' . $usage->plan);
        assertTrue($usage->hourly->limit > 0, 'hourly limit came back as zero');
        assertTrue($usage->key !== null, 'a keyed call should carry a key block');
        // Never print the key. Four characters is enough to tell keys apart,
        // which is exactly why the API returns only that.
        echo sprintf(
            "      plan %s, key ...%s, %d of %d requests left this hour\n",
            $usage->plan,
            (string) $usage->key?->lastFour,
            $usage->hourly->remaining,
            $usage->hourly->limit,
        );
        assertTrue($usage->hourly->remaining > 100, 'too little quota left to run the rest safely');
    });

    test('domains are listed', function () use ($live): void {
        $domains = $live->domains();
        assertTrue($domains !== [], 'no domains came back, so no inbox could be created');
        echo sprintf("      %d domains, first is %s\n", count($domains), $domains[0]);
    });

    test('an inbox can be created, inspected, waited on and deleted', function () use ($live): void {
        $live->withInbox(function (Inbox $inbox) use ($live): void {
            assertTrue($inbox->address !== '', 'no address');

            // The space-separated timestamp with no timezone is the one worth
            // checking against production, since reading it in local time
            // would put the expiry in the past.
            assertTrue($inbox->expiresAt !== null, 'expires_at did not parse');
            assertTrue(
                $inbox->expiresAt > new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
                'expires_at is not in the future, so it was parsed in the wrong zone',
            );
            echo sprintf(
                "      created %s, expires %s\n",
                $inbox->address,
                (string) $inbox->expiresAt?->format('Y-m-d\TH:i:s\Z'),
            );

            assertSame($inbox->address, $live->inbox($inbox->address)->address, 'metadata address');

            $list = $live->messages($inbox->address);
            assertSame(0, count($list), 'a brand new inbox held messages');
            // These fields were missing from the published docs entirely, so
            // confirm production really sends them.
            assertTrue($list->limit > 0, 'limit was absent from the listing response');
            assertFalse($list->scanLimitReached, 'scan_limit_reached true on an empty inbox');

            $filtered = $live->messages($inbox->address, new ListOptions(subject: 'verify', limit: 5));
            assertSame(5, $filtered->limit, 'limit=5 was not honoured');

            $startedAt = microtime(true);
            assertThrows(
                NoMessageException::class,
                fn () => $live->waitForMessage($inbox->address, new WaitOptions(timeout: 8.0)),
            );
            $elapsed = microtime(true) - $startedAt;
            assertTrue($elapsed > 5.0, sprintf('gave up after %.1fs, so the long poll is not holding', $elapsed));
            echo sprintf("      waited %.1fs then reported no message, as expected\n", $elapsed);
        });
    });

    test('deleting twice is not an error against production', function () use ($live): void {
        $inbox = $live->createInbox();
        $live->deleteInbox($inbox->address);
        // This is what makes cleanup in a finally block safe, so it has to
        // hold against production and not just against the fake.
        $live->deleteInbox($inbox->address);
    });

    test('bulk charges one request per inbox', function () use ($live): void {
        $batch = $live->bulkCreateInboxes(2);
        try {
            assertSame(2, $batch->count, 'count');
            assertSame(2, count($batch), 'inboxes returned');
            assertSame(2, $batch->quotaCharged, 'quota charged');
            echo '      bulk created ' . implode(', ', $batch->addresses()) . "\n";
        } finally {
            foreach ($batch->addresses() as $address) {
                $live->deleteInbox($address);
            }
        }
    });

    test('a missing inbox is classified as not found', function () use ($live): void {
        $e = assertThrows(
            ApiException::class,
            fn () => $live->inbox('definitely-not-a-real-inbox-99999@alagen.site'),
        );
        assertTrue($e instanceof ApiException && $e->isNotFound(), 'should be a 404: ' . $e->getMessage());
    });

    test('a nonsense key is refused by production', function (): void {
        $bad = new Client('btm_sk_live_0000000000000000000000000000000');
        $e = assertThrows(
            ApiException::class,
            fn () => $bad->bulkCreateInboxes(1),
            'a nonsense key should not be accepted',
        );
        // Separate "the API refused us" from "we never reached the API": an
        // outage reported as a broken error mapping sends the reader to the
        // wrong file.
        assertTrue($e instanceof ApiException, 'never reached the API: ' . $e->getMessage());
        assertTrue(
            $e instanceof ApiException && ($e->isUnauthorized() || $e->isPaymentRequired() || $e->isRateLimited()),
            'unexpected status for a bad key: ' . $e->getMessage(),
        );
    });
}

/* ===========================================================================
 *  Summary
 * ========================================================================= */

$failed = count(Runner::$failures);
echo "\n";
if ($failed === 0) {
    echo "\033[32m" . Runner::$passed . " passed\033[0m\n";
    exit(0);
}

echo "\033[31m{$failed} failed\033[0m, " . Runner::$passed . " passed\n\n";
foreach (Runner::$failures as $failure) {
    echo "  - {$failure}\n";
}
exit(1);
