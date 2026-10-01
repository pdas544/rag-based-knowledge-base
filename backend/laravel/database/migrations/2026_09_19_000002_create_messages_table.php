<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('role', ['user', 'assistant', 'system']);
            $table->mediumText('content');
            $table->unsignedInteger('tokens')->nullable();
            $table->string('model', 100)->nullable();
            $table->json('sources')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'created_at']);
        });

        // FULLTEXT for MySQL only (skip on sqlite testing)
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            Schema::table('messages', function (Blueprint $table) {
                $table->fullText('content');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
