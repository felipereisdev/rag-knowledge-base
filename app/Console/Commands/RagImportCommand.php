<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Services\Importing\DocumentImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class RagImportCommand extends Command
{
    protected $signature = 'rag:import
        {path : Path to the .md or .txt file}
        {--project= : Project ID (defaults to slugified cwd)}
        {--category=insight : Category for imported entries}
        {--tags= : Comma-separated tags}';

    protected $description = 'Import a .md or .txt file into the knowledge base.';

    public function handle(DocumentImporter $importer): int
    {
        $path = (string) $this->argument('path');
        $pid = $this->resolveProjectId();
        $category = (string) $this->option('category');
        $tags = $this->option('tags') !== null
            ? array_filter(array_map('trim', explode(',', (string) $this->option('tags'))), fn ($s) => $s !== '')
            : null;

        try {
            $entryIds = $importer->import($pid, $path, $category, $tags);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Imported '.count($entryIds).' entries (pending approval).');
        $this->line("  Project: {$pid}");
        $this->line("  File: {$path}");
        foreach ($entryIds as $id) {
            $this->line("  - {$id}");
        }

        return self::SUCCESS;
    }

    private function resolveProjectId(): string
    {
        $pid = $this->option('project');
        if ($pid !== null && $pid !== '') {
            return (string) $pid;
        }

        $cwd = (string) getcwd();
        $slug = Str::slug(basename($cwd)) ?: 'project';

        if (! Project::where('id', $slug)->exists()) {
            Project::create([
                'id' => $slug,
                'name' => basename($cwd) ?: $slug,
                'root_path' => $cwd,
            ]);
        }

        return $slug;
    }
}
