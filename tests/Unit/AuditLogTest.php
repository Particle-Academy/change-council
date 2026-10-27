<?php

declare(strict_types=1);

use ChangeCouncil\Audit\AuditLog;
use ChangeCouncil\Models\AuditEvent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * The audit log is append-only and hash-chained. The model refuses updates and
 * deletes, a SQLite trigger refuses them underneath, and an edit made around
 * both (someone with the database file) is caught by verify().
 */

beforeEach(function (): void {
    councilApp();
    consumer('moic@moic-suite', ['particle-academy/prism']);
    $change = propose();
    vote($change, 'moic@moic-suite');
    ledger()->tally();
});

test('an untouched chain verifies, and the head is the last hash', function (): void {
    $result = app(AuditLog::class)->verify();

    expect($result['ok'])->toBeTrue()
        ->and($result['checked'])->toBe(AuditEvent::query()->count())
        ->and($result['head'])->toBe(AuditEvent::query()->orderByDesc('seq')->value('hash'))
        ->and(AuditEvent::query()->where('seq', 1)->value('prev_hash'))->toBe(str_repeat('0', 64));
});

test('the model refuses to update or delete an event', function (): void {
    $event = AuditEvent::query()->first();

    expect(fn () => $event->update(['actor' => 'someone-else']))->toThrow(LogicException::class, 'append-only')
        ->and(fn () => $event->delete())->toThrow(LogicException::class, 'append-only');
});

test('the database trigger refuses a raw UPDATE and DELETE', function (): void {
    expect(fn () => DB::table('council_events')->where('seq', 1)->update(['actor' => 'x']))->toThrow(QueryException::class, 'append-only')
        ->and(fn () => DB::table('council_events')->where('seq', 1)->delete())->toThrow(QueryException::class, 'append-only');
});

test('tamper outside the app is detected: an edited row breaks the chain at that row', function (): void {
    DB::statement('DROP TRIGGER council_events_no_update');
    DB::table('council_events')->where('seq', 3)->update(['data' => json_encode(['verdict' => 'approve', 'forged' => true])]);

    $result = app(AuditLog::class)->verify();

    expect($result['ok'])->toBeFalse()
        ->and($result['seq'])->toBe(3)
        ->and($result['problem'])->toBe('hash_mismatch');
});

test('tamper outside the app is detected: a deleted row is a gap', function (): void {
    DB::statement('DROP TRIGGER council_events_no_delete');
    DB::table('council_events')->where('seq', 2)->delete();

    $result = app(AuditLog::class)->verify();

    expect($result['ok'])->toBeFalse()
        ->and($result['seq'])->toBe(3)
        ->and($result['problem'])->toBe('sequence_gap');
});

test('tamper outside the app is detected: a re-hashed row still breaks the NEXT link', function (): void {
    DB::statement('DROP TRIGGER council_events_no_update');
    $row = DB::table('council_events')->where('seq', 2)->first();
    $data = ['forged' => true];
    $hash = AuditLog::hashOf($row->prev_hash, (int) $row->seq, $row->change_id, $row->at, $row->actor, $row->type, $data);
    DB::table('council_events')->where('seq', 2)->update(['data' => json_encode($data), 'hash' => $hash]);

    $result = app(AuditLog::class)->verify();

    expect($result['ok'])->toBeFalse()
        ->and($result['seq'])->toBe(3)
        ->and($result['problem'])->toBe('broken_link');
});

test('council:verify-log exits non-zero on a tampered chain', function (): void {
    expect(runCommandWithCode('council:verify-log')[0])->toBe(0);

    DB::statement('DROP TRIGGER council_events_no_update');
    DB::table('council_events')->where('seq', 1)->update(['actor' => 'owner:mallory']);

    [$code, $output] = runCommandWithCode('council:verify-log');
    expect($code)->toBe(1)
        ->and($output)->toContain('hash_mismatch');
});
