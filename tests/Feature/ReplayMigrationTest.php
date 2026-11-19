<?php

declare(strict_types=1);

use ChangeCouncil\Models\Vote;
use Illuminate\Support\Facades\DB;

/*
 * 0.9.1's migration on a ledger that already holds votes: accepted rows are
 * backfilled into `accepted_message_id`, refusals are not, nothing is rewritten,
 * and afterwards a refused message id can be ingested again.
 */

beforeEach(function (): void {
    councilApp();
    consumer('moic@moic-suite', ['particle-academy/prism']);
    $this->migration = require dirname(__DIR__, 2).'/database/migrations/2026_11_18_000000_council_replay_keys_on_accepted_messages.php';
});

test('upgrading backfills accepted votes only, and then a refused id can be re-ingested', function (): void {
    $change = propose();
    $petition = petitionFor($change, 'moic@moic-suite');
    $this->migration->down();

    $row = fn (string $id, string $status, string $from) => DB::table('council_votes')->insert([
        'petition_id' => $petition->id, 'genie_message_id' => $id, 'from_address' => $from, 'impact' => '[]', 'raw_text' => 'x',
        'flags' => '[]', 'received_at' => '2026-10-27 12:00:00', 'status' => $status, 'created_at' => '2026-10-27 12:00:00', 'updated_at' => '2026-10-27 12:00:00',
    ]);
    $row('m-counted', 'counted', 'moic@moic-suite');
    $row('m-superseded', 'superseded', 'moic@moic-suite');
    $row('m-refused', 'unsolicited', 'agent_81bb07b176c871d7e70d4c45afed1cf1');

    $this->migration->up();

    expect(Vote::query()->orderBy('id')->pluck('accepted_message_id', 'genie_message_id')->all())
        ->toBe(['m-counted' => 'm-counted', 'm-superseded' => 'm-superseded', 'm-refused' => null])
        ->and(ingest()->receive('moic@moic-suite', replyText($petition), 'm-refused')->status)->toBe('counted')
        ->and(ingest()->receive('moic@moic-suite', replyText($petition), 'm-counted')->status)->toBe('duplicate');
});

test('the database itself refuses a second accepted copy of one message', function (): void {
    $insert = fn () => DB::table('council_votes')->insert([
        'genie_message_id' => 'm-1', 'accepted_message_id' => 'm-1', 'from_address' => 'moic@moic-suite', 'impact' => '[]', 'raw_text' => 'x',
        'flags' => '[]', 'received_at' => '2026-10-27 12:00:00', 'status' => 'counted', 'created_at' => '2026-10-27 12:00:00', 'updated_at' => '2026-10-27 12:00:00',
    ]);
    $insert();

    expect($insert)->toThrow(Illuminate\Database\QueryException::class);
});
