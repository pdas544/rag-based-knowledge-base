<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eval_runs', function (Blueprint $table) {
            $table->id();
            $table->string('run_id', 64)->unique();
            $table->string('mode', 20);
            $table->string('status', 20)->default('completed');
            $table->string('dataset_path');
            $table->string('dataset_hash', 64);
            $table->string('git_sha', 64)->nullable();
            $table->string('chat_model')->nullable();
            $table->string('embedding_model')->nullable();
            $table->unsignedInteger('embedding_dim')->nullable();
            $table->string('collection', 100)->nullable();
            $table->string('prompt_version', 64)->nullable();
            $table->string('judge_model')->nullable();
            $table->unsignedInteger('top_k')->default(5);
            $table->decimal('threshold', 4, 3)->default(0.5);
            $table->unsignedInteger('question_count')->default(0);
            $table->json('metrics')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['mode', 'created_at']);
        });

        Schema::create('eval_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('eval_run_id')->constrained('eval_runs')->cascadeOnDelete();
            $table->string('question_id', 64);
            $table->text('question');
            $table->boolean('answerable')->default(true);
            $table->longText('answer')->nullable();
            $table->longText('expected_answer')->nullable();
            $table->json('retrieved')->nullable();
            $table->json('relevant_chunk_ids')->nullable();
            $table->json('scores')->nullable();
            $table->json('judge_raw')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->unsignedInteger('tokens')->nullable();
            $table->decimal('cost_usd', 12, 6)->default(0);
            $table->timestamps();

            $table->index(['eval_run_id', 'question_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eval_results');
        Schema::dropIfExists('eval_runs');
    }
};
