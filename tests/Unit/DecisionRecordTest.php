<?php

declare(strict_types=1);

use ChangeCouncil\Ledger\Refused;
use ChangeCouncil\Records\DecisionRecords;
use ChangeCouncil\Records\RecordSigner;
use ChangeCouncil\Support\CanonicalJson;

/*
 * Signed decision records: Ed25519 through PHP's built-in sodium, written to
 * `.council/decisions/<ref>.json` in a target repo. Phase 2 writes and
 * verifies; the release gate that reads them is Phase 3.
 */

beforeEach(function (): void {
    councilApp();
    consumer('moic@moic-suite', ['particle-academy/prism']);
    $this->change = propose();
    vote($this->change, 'moic@moic-suite');
    ledger()->tally();
    $this->records = app(DecisionRecords::class);
    $this->dir = sys_get_temp_dir().'/council-repo-'.bin2hex(random_bytes(4));
    mkdir($this->dir);
});

afterEach(function (): void {
    array_map('unlink', glob($this->dir.'/.council/decisions/*') ?: []);
    foreach ([$this->dir.'/.council/decisions', $this->dir.'/.council', $this->dir] as $dir) {
        if (is_dir($dir)) {
            rmdir($dir);
        }
    }
});

test('an approved Change exports a signed record that verifies', function (): void {
    $path = $this->records->export($this->change->fresh(), $this->dir);
    $record = json_decode(file_get_contents($path), true);

    expect($path)->toBe($this->dir.'/.council/decisions/CC-fancy-0001.json')
        ->and($record)->toMatchArray([
            'schema' => 'change-council/decision.v1',
            'ref' => 'CC-fancy-0001',
            'origin' => 'fancy',
            'state' => 'approved',
            'decided_by' => 'tally',
        ])
        ->and($record['covers']['fingerprints'])->toBe([str_repeat('a', 64)])
        ->and($record['packages'])->toBe([['ecosystem' => 'composer', 'name' => 'particle-academy/prism']])
        ->and($record['rounds'][0]['outcomes']['approve'])->toBe(1)
        ->and($record['key_id'])->toBe(app(RecordSigner::class)->keyId())
        ->and($this->records->verify($record)['ok'])->toBeTrue();
});

test('the record carries no free text from any reply', function (): void {
    $record = $this->records->build($this->change->fresh());

    expect(json_encode($record))->not->toContain('approve|conflict')
        ->and(array_keys($record))->toBe(['schema', 'ref', 'origin', 'state', 'decided_by', 'decided_at', 'approved_unopposed', 'packages', 'covers', 'waivers', 'rounds', 'ledger_head', 'key_id', 'signature']);
});

test('a flipped byte anywhere in the signed content fails verification', function (string $field): void {
    $record = json_decode(file_get_contents($this->records->export($this->change->fresh(), $this->dir)), true);
    data_set($record, $field, data_get($record, $field) === 'approved' ? 'approvee' : (is_string(data_get($record, $field)) ? data_get($record, $field).'x' : 'x'));

    expect($this->records->verify($record))->toMatchArray(['ok' => false, 'reason' => 'bad_signature']);
})->with(['state', 'ref', 'covers.fingerprints.0', 'ledger_head', 'decided_at']);

test('a record signed by an unknown key, or with a mangled signature, does not verify', function (): void {
    $record = $this->records->build($this->change->fresh());

    $unknown = [...$record, 'key_id' => 'ffffffffffffffff'];
    $mangled = [...$record, 'signature' => base64_encode(str_repeat("\0", 64))];
    $garbage = [...$record, 'signature' => '!!!'];

    expect($this->records->verify($unknown)['reason'])->toBe('unknown_key')
        ->and($this->records->verify($mangled)['reason'])->toBe('bad_signature')
        ->and($this->records->verify($garbage)['reason'])->toBe('bad_signature');
});

test('only an approved Change can be exported', function (): void {
    $live = propose(['title' => 'still open']);

    expect(fn () => $this->records->export($live, $this->dir))->toThrow(Refused::class, 'not_approved');
});

test('the record pins the audit chain head at export time', function (): void {
    $record = $this->records->build($this->change->fresh());

    expect($record['ledger_head'])->toBe(app(\ChangeCouncil\Audit\AuditLog::class)->head());
});

test('canonical JSON sorts keys, keeps list order and refuses floats', function (): void {
    expect(CanonicalJson::encode(['b' => 1, 'a' => ['y' => 'é/', 'x' => [3, 1]]]))->toBe('{"a":{"x":[3,1],"y":"é/"},"b":1}')
        ->and(fn () => CanonicalJson::encode(['n' => 1.5]))->toThrow(InvalidArgumentException::class);
});

test('the shared cross-language fixture verifies in PHP, and a flipped byte does not', function (): void {
    $fixture = json_decode(file_get_contents(dirname(__DIR__).'/fixtures/decision-record.json'), true);
    $keys = json_decode(file_get_contents(dirname(__DIR__).'/fixtures/decision-keys.json'), true);
    $signer = new RecordSigner(null, $keys);

    expect($signer->verify($fixture)['ok'])->toBeTrue()
        ->and($signer->verify([...$fixture, 'state' => 'approvee'])['ok'])->toBeFalse();
});
