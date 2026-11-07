<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * 0.5.0: council members. Additive only: every existing row is kept.
 *
 * - council_consumers.weight: a member's vote weight, non-negative (the ledger
 *   refuses anything else). Default 1, so every member starts equal and, with
 *   the default threshold of 1, one blocking conflict blocks exactly as before.
 * - project_name / project_description / project_url: the member's project, set
 *   by the owner. The URL is http or https only (the ledger refuses the rest).
 * - council_changes.blocking_below_threshold: a round had blocking conflicts
 *   whose combined weight did not reach the threshold, so they did not block.
 * - council_settings: council-level owner settings (the blocking threshold).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('council_consumers', function (Blueprint $table): void {
            $table->unsignedInteger('weight')->default(1);
            $table->string('project_name')->nullable();
            $table->text('project_description')->nullable();
            $table->string('project_url', 2048)->nullable();
        });

        Schema::table('council_changes', function (Blueprint $table): void {
            $table->boolean('blocking_below_threshold')->default(false);
        });

        Schema::create('council_settings', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->json('value');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('council_settings');
        Schema::table('council_changes', function (Blueprint $table): void {
            $table->dropColumn('blocking_below_threshold');
        });
        Schema::table('council_consumers', function (Blueprint $table): void {
            $table->dropColumn(['weight', 'project_name', 'project_description', 'project_url']);
        });
    }
};
