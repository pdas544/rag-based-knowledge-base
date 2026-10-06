<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // FULLTEXT is MySQL-only (see 000002 messages pattern); SQLite tests use LIKE.
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            Schema::table('conversations', function (Blueprint $table) {
                $table->fullText('title');
            });
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            Schema::table('conversations', function (Blueprint $table) {
                $table->dropFullText(['title']);
            });
        }
    }
};
