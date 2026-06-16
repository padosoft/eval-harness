<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eval_harness_online_scores', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('dataset');
            $table->string('sample_id');
            $table->string('metric');
            $table->decimal('score', 5, 4);
            $table->boolean('passed');
            $table->string('judge_model')->nullable();
            $table->json('details')->nullable();
            $table->timestamp('judged_at');
            $table->timestamps();
            // Single composite index covers every hot query
            // (forDataset()->orderByDesc('judged_at') and the grouped
            // trend aggregate); standalone dataset/judged_at indexes
            // would be redundant write amplification.
            $table->index(['dataset', 'judged_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eval_harness_online_scores');
    }
};
