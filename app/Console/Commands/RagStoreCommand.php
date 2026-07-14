<?php

namespace App\Console\Commands;

use App\Enums\KnowledgeSource;
use App\Enums\KnowledgeStatus;
use App\Models\Project;
use App\Services\Knowledge\KnowledgeWriter;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class RagStoreCommand extends Command
{
    protected $signature = 'rag:store
        {title : The entry title}
        {--project= : Project ID (defaults to slugified cwd)}
        {--content= : The entry content as a string}
        {--content-file= : Path to a file containing the content}
        {--category=insight : Category}
        {--tags= : Comma-separated tags}
        {--entities= : Comma-separated entity names}
        {--relations= : Comma-separated subject:predicate:object triples}';

    protected $description = 'Store a knowledge entry in the RAG knowledge base (pending approval or classification).';

    public function handle(KnowledgeWriter $writer): int
    {
        $content = $this->resolveContent();
        if ($content === null) {
            $this->error('Provide either --content or --content-file.');

            return self::FAILURE;
        }

        $pid = $this->resolveProjectId();
        $title = (string) $this->argument('title');
        $category = (string) $this->option('category');

        // The writer owns the write: the transaction, the tag/entity/relation
        // graph, the initial status, and whether this entry gets classified.
        // The command only parses options.
        $entry = $writer->store(
            projectId: $pid,
            title: $title,
            content: $content,
            category: $category,
            source: KnowledgeSource::Cli,
            tags: $this->parseCsv((string) $this->option('tags')),
            entities: array_map(
                static fn (string $name): array => ['name' => $name],
                $this->parseCsv((string) $this->option('entities')),
            ),
            relations: $this->parseRelations((string) $this->option('relations')),
        );

        $this->info($entry->status === KnowledgeStatus::Classifying->value
            ? 'Knowledge entry stored (classifying importance).'
            : 'Knowledge entry stored (pending approval).');
        $this->line("  ID: {$entry->id}");
        $this->line("  Title: {$title}");
        $this->line("  Project: {$pid}");
        $this->line("  Category: {$category}");
        $this->line('  Approve at: '.config('app.url', 'http://127.0.0.1:8000').'/martis/resources/knowledge-entries');

        return self::SUCCESS;
    }

    private function resolveContent(): ?string
    {
        $content = $this->option('content');
        $file = $this->option('content-file');

        if ($content !== null && $content !== '') {
            return (string) $content;
        }

        if ($file !== null && $file !== '') {
            if (! file_exists($file)) {
                $this->error("File not found: {$file}");

                return null;
            }

            return (string) file_get_contents($file);
        }

        return null;
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

    /**
     * @return list<string>
     */
    private function parseCsv(string $value): array
    {
        if ($value === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $value)), fn ($s) => $s !== ''));
    }

    /**
     * @return list<array{subject: string, predicate: string, object: string}>
     */
    private function parseRelations(string $value): array
    {
        if ($value === '') {
            return [];
        }

        $relations = [];
        foreach (explode(',', $value) as $triple) {
            $parts = explode(':', trim($triple), 3);
            if (count($parts) === 3) {
                $relations[] = [
                    'subject' => $parts[0],
                    'predicate' => $parts[1],
                    'object' => $parts[2],
                ];
            }
        }

        return $relations;
    }
}
