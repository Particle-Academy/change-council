<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * 0.5.1: removing a member from the council. Additive only: every existing row
 * is kept, and a removal never deletes one either.
 *
 * - council_consumers.removed_at: the owner removed this member. The row stays,
 *   so its voting record and audit history stay readable; a removed member is
 *   never petitioned, never counted in the census check, and never re-added by
 *   the seeder.
 * - council_petitions.withdrawn_at: the petition was withdrawn because its
 *   member was removed while the round was open. The tally skips it: it is
 *   neither a vote nor a silence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('council_consumers', function (Blueprint $table): void {
            $table->timestamp('removed_at')->nullable();
        });

        Schema::table('council_petitions', function (Blueprint $table): void {
            $table->timestamp('withdrawn_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('council_petitions', function (Blueprint $table): void {
            $table->dropColumn('withdrawn_at');
        });
        Schema::table('council_consumers', function (Blueprint $table): void {
            $table->dropColumn('removed_at');
        });
    }
};
