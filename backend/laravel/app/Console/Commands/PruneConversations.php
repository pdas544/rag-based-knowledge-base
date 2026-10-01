<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use Illuminate\Console\Command;

class PruneConversations extends Command
{
    protected $signature = 'conversations:prune {--days=90 : Delete conversations older than N days} {--dry-run : List only}';

    protected $description = 'Soft-deleted cleanup + prune conversations older than retention window';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $cutoff = now()->subDays($days);
        $query = Conversation::where('updated_at', '<', $cutoff);
        $count = $query->count();

        if ($this->option('dry-run')) {
            $this->info("Would prune {$count} conversation(s) older than {$days} days.");

            return self::SUCCESS;
        }

        $query->delete();
        // Hard-delete soft-deleted rows past retention
        Conversation::onlyTrashed()->where('deleted_at', '<', $cutoff)->forceDelete();

        $this->info("Pruned {$count} conversation(s).");

        return self::SUCCESS;
    }
}
