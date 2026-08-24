<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional retention of the interaction behind an online score.
 *
 * The original table stores a number: enough to plot drift, not enough to turn
 * a production failure into a regression test — for that you need the question
 * that was asked and the answer that was expected. Those columns are nullable
 * and stay null unless `eval-harness.online.retention.enabled` is turned on,
 * because keeping production text is a different decision, with a different
 * legal basis, from keeping a score.
 *
 * `redactor` and `redacted_at` record what processed the row and when. A
 * boolean could not answer "which redactor version handled this?", which is
 * exactly the question asked during an audit or after a redactor bug.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('eval_harness_online_scores', function (Blueprint $table): void {
            $table->json('input')->nullable()->after('sample_id');
            $table->text('expected_output')->nullable()->after('input');
            $table->text('actual_output')->nullable()->after('expected_output');
            $table->string('redactor')->nullable()->after('details');
            $table->timestamp('redacted_at')->nullable()->after('redactor');
        });
    }

    public function down(): void
    {
        Schema::table('eval_harness_online_scores', function (Blueprint $table): void {
            $table->dropColumn(['input', 'expected_output', 'actual_output', 'redactor', 'redacted_at']);
        });
    }
};
