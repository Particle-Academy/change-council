<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * 0.9.1: replay protection keys on ACCEPTED messages only.
 *
 * `genie_message_id` was unique on votes and census replies, so a REFUSED
 * ingest (a sender mismatch, a malformed reply) consumed the message id, and
 * the same genuine message re-ingested with the right sender was rejected as a
 * duplicate. Now:
 *
 * - `accepted_message_id` (nullable, unique) holds the message id of an
 *   accepted vote (counted, late, or a counted vote since superseded) or an
 *   applied census reply, and is null on a refusal. NULLs never collide, so the
 *   database still refuses a second accepted copy of one message.
 * - `genie_message_id` keeps every row's id, refusals included, under a plain
 *   index. No row is deleted or rewritten; existing accepted rows are
 *   backfilled.
 */
return new class extends Migration
{
    private const TABLES = [
        'council_votes' => ['counted', 'late', 'superseded'],
        'council_census_replies' => ['applied'],
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table => $accepted) {
            Schema::table($table, function (Blueprint $t): void {
                $t->string('accepted_message_id')->nullable()->unique();
            });
            DB::table($table)->whereIn('status', $accepted)->update(['accepted_message_id' => DB::raw('genie_message_id')]);
            Schema::table($table, function (Blueprint $t): void {
                $t->dropUnique(['genie_message_id']);
                $t->index('genie_message_id');
            });
        }
    }

    /** Fails if a message id now has more than one row (a refusal plus its re-ingest). */
    public function down(): void
    {
        foreach (array_keys(self::TABLES) as $table) {
            Schema::table($table, function (Blueprint $t): void {
                $t->dropIndex(['genie_message_id']);
                $t->unique('genie_message_id');
            });
            Schema::table($table, function (Blueprint $t): void {
                $t->dropUnique(['accepted_message_id']);
                $t->dropColumn('accepted_message_id');
            });
        }
    }
};
