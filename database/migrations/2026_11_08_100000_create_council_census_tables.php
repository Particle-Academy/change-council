<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * 0.6.0: the census. Additive only: two new tables, no existing row changed.
 *
 * - council_census_requests: one COUNCIL-CENSUS-REQUEST v1 per member, bound to
 *   it by a random nonce, queued for the clerk exactly like a petition.
 * - council_census_replies: every COUNCIL-CENSUS v1 reply, stored with its raw
 *   text whatever its fate (applied, invalid or unsolicited). The applied ones
 *   replaced the member's `packages` and set `council_consumers.census_at`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('council_census_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('consumer_id')->constrained('council_consumers');
            $table->string('agent_address')->index();
            $table->string('nonce')->unique();
            $table->timestamp('queued_at');
            $table->timestamp('sent_at')->nullable();
            $table->string('delivery')->default('queued'); // queued | sent | undelivered | unreachable
            $table->text('delivery_detail')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->unsignedBigInteger('reply_id')->nullable();
            $table->timestamps();
        });

        Schema::create('council_census_replies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('request_id')->nullable()->constrained('council_census_requests');
            $table->string('genie_message_id')->unique();  // replay protection
            $table->string('from_address');
            $table->json('apps');                          // [{name, lockfiles[]}] DATA, display only
            $table->json('packages');                      // the accepted first-party rows
            $table->json('dropped');                       // [{ecosystem, name, reason}]
            $table->longText('raw_text');                  // exactly as received (capped)
            $table->json('flags');                         // injection heuristics
            $table->timestamp('received_at');
            $table->string('status');                      // applied | invalid | unsolicited
            $table->string('problem')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('council_census_replies');
        Schema::dropIfExists('council_census_requests');
    }
};
