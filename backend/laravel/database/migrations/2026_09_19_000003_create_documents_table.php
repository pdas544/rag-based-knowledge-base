<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('filename', 255);
            $table->string('mime', 100);
            $table->unsignedInteger('size_bytes');
            $table->char('sha256', 64)->unique();
            $table->string('path', 500);
            $table->enum('status', ['pending', 'processing', 'awaiting_review', 'ready', 'rejected', 'failed'])->default('pending');
            $table->unsignedInteger('chunk_count')->default(0);
            $table->string('qdrant_collection', 100)->default('knowledge_base');
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index('sha256');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
