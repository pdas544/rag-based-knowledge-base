<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Indexes for admin aggregates, per-user lookups and retention scans.
     * Plain CREATE INDEX — works on both MySQL and SQLite test runs.
     */
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->index('user_id', 'messages_user_id_index');
            $table->index('model', 'messages_model_index');
            $table->index(['role', 'created_at'], 'messages_role_created_index');
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->index(['status', 'created_at'], 'documents_status_created_index');
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->index(['user_id', 'deleted_at'], 'conversations_user_deleted_index');
        });

        Schema::table('user_quotas', function (Blueprint $table) {
            $table->index('date', 'user_quotas_date_index');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex('messages_user_id_index');
            $table->dropIndex('messages_model_index');
            $table->dropIndex('messages_role_created_index');
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->dropIndex('documents_status_created_index');
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex('conversations_user_deleted_index');
        });

        Schema::table('user_quotas', function (Blueprint $table) {
            $table->dropIndex('user_quotas_date_index');
        });
    }
};
