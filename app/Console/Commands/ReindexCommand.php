<?php

namespace App\Console\Commands;

use App\Jobs\IndexEntryJob;
use App\Models\KnowledgeEntry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReindexCommand extends Command
{
    protected $signature = 'rag:reindex {--project= : Only reindex entries in this project} {--force : Skip confirmation}';

    protected $description = 'Re-index all approved entries (regenerate chunks and embeddings)';

    public function handle(): int
    {
        if (! $this->option('force') && ! $this->confirm('This will delete and regenerate all chunks. Continue?')) {
            $this->info('Aborted.');

            return self::SUCCESS;
        }

        $query = KnowledgeEntry::where('status', 'approved');

        if ($projectId = $this->option('project')) {
            $query->where('project_id', $projectId);
        }

        $count = $query->count();

        if ($count === 0) {
            $this->info('No approved entries to index.');

            return self::SUCCESS;
        }

        $this->info("Re-indexing {$count} entries...");

        // Clear existing chunks
        $deleteQuery = DB::table('chunk_embeddings');
        if ($projectId) {
            $deleteQuery->where('project_id', $projectId);
        }
        $deleteQuery->delete();

        $bar = $this->output->createProgressBar($count);
        $bar->start();

        $query->chunkById(100, function ($entries) use ($bar) {
            foreach ($entries as $entry) {
                IndexEntryJob::dispatch($entry->id);
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info("Dispatched {$count} indexing jobs. Run 'php artisan queue:work --queue=indexing' to process them.");

        return self::SUCCESS;
    }
}
