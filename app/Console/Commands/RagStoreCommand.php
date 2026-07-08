<?php

namespace App\Console\Commands;

use App\Models\Entity;
use App\Models\KnowledgeEntry;
use App\Models\Project;
use App\Models\Relation;
use App\Models\Tag;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
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

    protected $description = 'Store a knowledge entry in the RAG knowledge base (pending approval).';

    public function handle(): int
    {
        $content = $this->resolveContent();
        if ($content === null) {
            $this->error('Provide either --content or --content-file.');

            return self::FAILURE;
        }

        $pid = $this->resolveProjectId();
        $title = (string) $this->argument('title');
        $category = (string) $this->option('category');
        $tags = $this->parseCsv((string) $this->option('tags'));
        $entities = $this->parseCsv((string) $this->option('entities'));
        $relations = $this->parseRelations((string) $this->option('relations'));

        $entry = DB::transaction(function () use ($pid, $title, $content, $category, $tags, $entities, $relations) {
            $entry = KnowledgeEntry::create([
                'project_id' => $pid,
                'title' => $title,
                'content' => $content,
                'category' => $category,
                'source' => 'cli',
                'status' => 'pending',
            ]);

            foreach ($tags as $tagName) {
                $tag = Tag::firstOrCreate(['project_id' => $pid, 'name' => $tagName]);
                $entry->tags()->attach($tag->id);
            }

            foreach ($entities as $entityName) {
                $entity = Entity::firstOrCreate(
                    ['project_id' => $pid, 'name' => $entityName],
                    ['type' => ''],
                );
                $entry->entities()->attach($entity->id);
            }

            foreach ($relations as $r) {
                $subject = Entity::firstOrCreate(['project_id' => $pid, 'name' => $r['subject']], ['type' => '']);
                $object = Entity::firstOrCreate(['project_id' => $pid, 'name' => $r['object']], ['type' => '']);
                Relation::create([
                    'project_id' => $pid,
                    'subject_id' => $subject->id,
                    'predicate' => $r['predicate'],
                    'object_id' => $object->id,
                    'entry_id' => $entry->id,
                ]);
            }

            return $entry;
        });

        $this->info('Knowledge entry stored (pending approval).');
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
     * @return array<string>
     */
    private function parseCsv(string $value): array
    {
        if ($value === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $value)), fn ($s) => $s !== ''));
    }

    /**
     * @return array<array{subject: string, predicate: string, object: string}>
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
