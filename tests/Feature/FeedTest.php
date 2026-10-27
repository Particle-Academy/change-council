<?php

declare(strict_types=1);

use ChangeCouncil\Feed\Feed;
use ChangeCouncil\Feed\HttpSender;
use ChangeCouncil\Feed\WebhookEmitter;
use ChangeCouncil\Records\RecordSigner;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
 * The Overwatch feed: a versioned pull feed and a signed push. civi's contract
 * is unknown, so the feed is generic and read-only, and every payload carries
 * `origin` so one Overwatch can merge several labs.
 */

final class RecordingSender implements HttpSender
{
    /** @var list<array{url: string, headers: array, body: string}> */
    public array $sent = [];

    /** @param list<int> $statuses */
    public function __construct(public array $statuses = [200]) {}

    public function post(string $url, array $headers, string $body): int
    {
        $this->sent[] = compact('url', 'headers', 'body');

        return array_shift($this->statuses) ?? 200;
    }
}

beforeEach(function (): void {
    councilApp(['council.feed.webhook_url' => 'https://overwatch.example.test/hook']);
    consumer('moic@moic-suite', ['particle-academy/prism']);
    $change = propose();
    vote($change, 'moic@moic-suite', 'conflict', ['alternative' => 'secret-ish free text']);
});

test('the pull feed is versioned, carries origin, pages by seq and snapshots the touched Changes', function (): void {
    $page = app(Feed::class)->page(0);

    expect($page['schema'])->toBe('change-council/feed.v1')
        ->and($page['origin'])->toBe('fancy')
        ->and($page['events'][0])->toHaveKeys(['seq', 'at', 'change', 'actor', 'type', 'data', 'hash'])
        ->and($page['cursor'])->toBe(end($page['events'])['seq'])
        ->and($page['changes'][0]['ref'])->toBe('CC-fancy-0001')
        ->and(app(Feed::class)->page($page['cursor'])['events'])->toBe([]);
});

test('the feed never carries reply free text', function (): void {
    expect(json_encode(app(Feed::class)->page(0)))->not->toContain('secret-ish free text')
        ->and(json_encode(app(Feed::class)->page(0)))->not->toContain('allow-on-throw');
});

test('the feed validates against the shipped feed.v1 schema shape', function (): void {
    $schema = json_decode(file_get_contents(dirname(__DIR__, 2).'/resources/schema/feed.v1.json'), true);
    $page = app(Feed::class)->page(0);

    expect(array_keys($page))->toBe($schema['required'])
        ->and(array_keys($page['events'][0]))->toBe($schema['$defs']['event']['required'])
        ->and(array_keys($page['changes'][0]))->toBe($schema['$defs']['change']['required'])
        ->and($schema['properties']['schema']['const'])->toBe('change-council/feed.v1');
});

test('the webhook push is signed over t.body and verifiable with the public key', function (): void {
    $sender = new RecordingSender;
    $emitter = new WebhookEmitter(app(Feed::class), app(RecordSigner::class), $sender, app('db')->connection(), 'https://overwatch.example.test/hook', 8, 200);

    $result = $emitter->push();
    $sent = $sender->sent[0];

    expect($result['status'])->toBe('delivered')
        ->and($sent['headers']['Council-Key-Id'])->toBe(app(RecordSigner::class)->keyId())
        ->and($sent['headers']['Content-Type'])->toBe('application/json')
        ->and(WebhookEmitter::verify($sent['headers']['Council-Signature'], $sent['body'], app(RecordSigner::class)->publicKey(), Carbon::now()->getTimestamp()))->toBeTrue()
        ->and(WebhookEmitter::verify($sent['headers']['Council-Signature'], $sent['body'].' ', app(RecordSigner::class)->publicKey(), Carbon::now()->getTimestamp()))->toBeFalse()
        ->and(WebhookEmitter::verify($sent['headers']['Council-Signature'], $sent['body'], app(RecordSigner::class)->publicKey(), Carbon::now()->getTimestamp() + 3600))->toBeFalse();
});

test('a delivered push advances the cursor; nothing new means nothing sent', function (): void {
    $sender = new RecordingSender;
    $emitter = new WebhookEmitter(app(Feed::class), app(RecordSigner::class), $sender, app('db')->connection(), 'https://overwatch.example.test/hook', 8, 200);

    $emitter->push();
    $second = $emitter->push();

    expect($second['status'])->toBe('nothing_new')
        ->and($sender->sent)->toHaveCount(1);
});

test('a failed push backs off, retries, and dead-letters after max attempts', function (): void {
    $sender = new RecordingSender([500, 500, 500]);
    $emitter = new WebhookEmitter(app(Feed::class), app(RecordSigner::class), $sender, app('db')->connection(), 'https://overwatch.example.test/hook', 3, 200);

    expect($emitter->push()['status'])->toBe('retrying')
        ->and($emitter->push()['status'])->toBe('backing_off');

    Carbon::setTestNow(Carbon::now()->addHours(2));
    $emitter->push();
    Carbon::setTestNow(Carbon::now()->addHours(2));
    $dead = $emitter->push();

    expect($dead['status'])->toBe('dead_letter')
        ->and(DB::table('council_webhooks')->value('dead_lettered_at'))->not->toBeNull()
        ->and($sender->sent)->toHaveCount(3);
});
