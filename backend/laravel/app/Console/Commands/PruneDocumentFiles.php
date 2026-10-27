<?php

namespace App\Console\Commands;

use App\Models\Document;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PruneDocumentFiles extends Command
{
    protected $signature = 'documents:prune-files {--days=30} {--dry-run}';

    protected $description = 'Delete stored files for rejected/failed documents and orphaned uploads';

    public function handle(): int
    {
        $days = max(0, (int) $this->option('days'));
        $dryRun = (bool) $this->option('dry-run');
        $cutoff = now()->subDays($days);
        $disk = Storage::disk('local');

        $pruned = 0;
        $stale = Document::query()
            ->whereIn('status', ['rejected', 'failed'])
            ->where('created_at', '<', $cutoff)
            ->get();

        foreach ($stale as $document) {
            if (! $document->path || ! $disk->exists($document->path)) {
                continue;
            }

            if (! $dryRun) {
                $disk->delete($document->path);
                $document->update([
                    'error' => trim(($document->error ?? '').' [file pruned '.now()->toDateString().']'),
                ]);
            }
            $pruned++;
        }

        // Orphans: files on disk with no matching documents.path row.
        $known = Document::query()->whereNotNull('path')->pluck('path')->flip();
        $orphans = 0;
        foreach ($disk->allFiles('documents') as $file) {
            if ($known->has($file)) {
                continue;
            }
            if (! $dryRun) {
                $disk->delete($file);
            }
            $orphans++;
        }

        $suffix = $dryRun ? ' (dry-run)' : '';
        $this->info("Pruned {$pruned} rejected/failed files and {$orphans} orphan(s){$suffix}.");

        return self::SUCCESS;
    }
}
