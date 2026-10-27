<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * change-council's ledger. SQLite-friendly: a lab runs on SQLite.
 *
 * council_events is append-only. The model refuses update and delete; on SQLite
 * the triggers below refuse them underneath; and the hash chain lets
 * `council:verify-log` detect an edit made around both.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('council_consumers', function (Blueprint $table): void {
            $table->id();
            $table->string('address')->unique();           // Genie agent address, e.g. moic@moic-suite
            $table->string('workspace')->nullable();
            $table->json('packages');                      // [{ecosystem, name, constraint, apis?}]
            $table->json('watches');                       // [{ecosystem, name}] informed, never required
            $table->string('silence_policy')->default('escalate'); // escalate | proceed_and_notify
            $table->boolean('whitelisted')->default(false);
            $table->boolean('internal')->default(false);   // our own apps: never petitioned (dogfood rule)
            $table->string('source')->default('inferred'); // declared | inferred | lockfile
            $table->timestamp('census_at')->nullable();
            $table->boolean('reachable')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('council_changes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('ref')->unique();               // CC-<origin>-0007
            $table->string('origin');
            $table->string('title');
            $table->text('summary');
            $table->text('approach');                      // the CURRENT proposed approach (rounds keep each frozen copy)
            $table->json('classes');
            $table->json('packages');                      // [{ecosystem, name, from?, to?}]
            $table->json('evidence');
            $table->json('links');
            $table->string('initiation_source');           // agent_finding | agent_request | github_issue
            $table->string('initiator');
            $table->boolean('whitelisted');
            $table->text('pros')->nullable();
            $table->text('cons')->nullable();
            $table->string('state');
            $table->unsignedTinyInteger('current_round')->nullable();
            $table->json('silence_overrides');             // {address: policy}
            $table->string('decided_by')->nullable();      // tally | owner:<id>
            $table->text('decision_reason')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->boolean('approved_unopposed')->default(false);
            $table->boolean('approved_with_notes')->default(false);
            $table->boolean('late_conflict')->default(false);
            $table->timestamps();
            $table->index('state');
        });

        Schema::create('council_rounds', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('change_id')->constrained('council_changes');
            $table->unsignedTinyInteger('number');
            $table->text('approach');
            $table->string('approach_digest');
            $table->timestamp('opened_at');
            $table->timestamp('closes_at');
            $table->timestamp('extended_until')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->json('tally')->nullable();
            $table->string('outcome')->nullable();
            $table->timestamps();
            $table->unique(['change_id', 'number']);
        });

        Schema::create('council_petitions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('round_id')->constrained('council_rounds');
            $table->foreignUlid('change_id')->constrained('council_changes');
            $table->foreignId('consumer_id')->nullable()->constrained('council_consumers');
            $table->string('agent_address');
            $table->string('tier');                        // required | informed
            $table->string('why');
            $table->string('nonce')->unique();
            $table->timestamp('queued_at');
            $table->timestamp('sent_at')->nullable();
            $table->string('delivery')->default('queued'); // queued | sent | undelivered | unreachable
            $table->text('delivery_detail')->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->string('outcome')->nullable();         // approve | conflict | abstain | silent | unreachable | undelivered | invalid
            $table->boolean('catch_up')->default(false);   // proceed_and_notify: still owed a late vote
            $table->timestamps();
        });

        Schema::create('council_votes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('petition_id')->nullable()->constrained('council_petitions');
            $table->string('genie_message_id')->unique();  // replay protection
            $table->string('from_address');
            $table->string('verdict')->nullable();         // approve | conflict | abstain
            $table->json('impact');                        // [{app, paths[], severity, note}]
            $table->text('alternative')->nullable();       // DATA: never applied
            $table->text('reason')->nullable();
            $table->longText('raw_text');                  // exactly as received
            $table->json('flags');                         // injection heuristics
            $table->timestamp('received_at');
            $table->string('status');                      // counted | invalid | unsolicited | superseded | late
            $table->string('problem')->nullable();
            $table->timestamps();
        });

        Schema::create('council_decisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('change_id')->constrained('council_changes');
            $table->string('outcome');                     // approved | rejected | revoked
            $table->string('decided_by');
            $table->text('reason')->nullable();
            $table->json('acknowledged');                  // vote ids an owner acknowledged when approving over conflict
            $table->string('ledger_head');
            $table->timestamp('decided_at');
            $table->timestamps();
        });

        Schema::create('council_events', function (Blueprint $table): void {
            $table->unsignedBigInteger('seq')->primary();
            $table->string('change_id')->nullable()->index();
            $table->string('at');
            $table->string('actor');
            $table->string('type');
            $table->json('data');
            $table->char('prev_hash', 64);
            $table->char('hash', 64);
        });

        Schema::create('council_webhooks', function (Blueprint $table): void {
            $table->id();
            $table->string('url')->unique();
            $table->unsignedBigInteger('last_seq')->default(0);
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('dead_lettered_at')->nullable();
            $table->timestamps();
        });

        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement("CREATE TRIGGER council_events_no_update BEFORE UPDATE ON council_events BEGIN SELECT RAISE(ABORT, 'council_events is append-only'); END");
            DB::statement("CREATE TRIGGER council_events_no_delete BEFORE DELETE ON council_events BEGIN SELECT RAISE(ABORT, 'council_events is append-only'); END");
        }
    }

    public function down(): void
    {
        foreach (['council_webhooks', 'council_events', 'council_decisions', 'council_votes', 'council_petitions', 'council_rounds', 'council_changes', 'council_consumers'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
