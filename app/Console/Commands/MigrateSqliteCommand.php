<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use SQLite3;

class MigrateSqliteCommand extends Command
{
    protected $signature = 'rag:migrate-sqlite {path? : Path to the old SQLite database (default: ~/.rag/knowledge.db)}';

    protected $description = 'Migrate data from the old SQLite RAG database into the current Postgres database';

    public function handle(): int
    {
        $path = $this->argument('path') ?: (posix_getpwuid(posix_getuid())['dir'].'/.rag/knowledge.db');

        if (! file_exists($path)) {
            $this->error("SQLite database not found at: {$path}");

            return Command::FAILURE;
        }

        if (! class_exists('SQLite3')) {
            $this->error('PHP SQLite3 extension is required.');

            return Command::FAILURE;
        }

        $sqlite = new SQLite3($path);
        $sqlite->enableExceptions(true);

        $this->info("Migrating from: {$path}");

        DB::transaction(function () use ($sqlite) {
            $this->migrateProjects($sqlite);
            $this->migrateProjectPaths($sqlite);
            $this->migrateEntries($sqlite);
            $this->migrateTags($sqlite);
            $this->migrateEntryTags($sqlite);
            $this->migrateEntities($sqlite);
            $this->migrateRelations($sqlite);
            $this->migrateEntryEntities($sqlite);
            $this->migrateEntryLinks($sqlite);
        });

        $this->info('Migration complete. Run `php artisan rag:reindex` to regenerate embeddings.');

        return Command::SUCCESS;
    }

    private function epochToDatetime(float $epoch): string
    {
        return gmdate('Y-m-d H:i:s', (int) $epoch);
    }

    private function migrateProjects(SQLite3 $sqlite): void
    {
        $rows = $this->fetchAll($sqlite, 'SELECT * FROM projects');
        $count = 0;

        foreach ($rows as $r) {
            $existing = DB::table('projects')->where('id', $r['id'])->exists();
            if ($existing) {
                continue;
            }

            DB::table('projects')->insert([
                'id' => $r['id'],
                'name' => $r['name'],
                'root_path' => $r['root_path'],
                'description' => $r['description'] ?? '',
                'project_type' => $this->normalizeProjectType($r['project_type'] ?? ''),
                'language' => $r['language'] ?? 'en',
                'created_at' => $this->epochToDatetime($r['created_at']),
                'updated_at' => $this->epochToDatetime($r['updated_at']),
            ]);
            $count++;
        }

        $this->info("  projects: {$count} migrated");
    }

    /**
     * The legacy SQLite schema stored project_type as a single string.
     * The MultiSelect field now expects a JSON array, so wrap scalar
     * values and pass through anything already encoded as a JSON array.
     */
    private function normalizeProjectType(mixed $value): string
    {
        if (is_string($value) && str_starts_with(trim($value), '[')) {
            return $value;
        }

        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? '[]' : json_encode([$value]);
    }

    private function migrateProjectPaths(SQLite3 $sqlite): void
    {
        $rows = $this->fetchAll($sqlite, 'SELECT * FROM project_paths');
        $count = 0;

        foreach ($rows as $r) {
            $existing = DB::table('project_paths')
                ->where('project_id', $r['project_id'])
                ->where('path', $r['path'])
                ->exists();
            if ($existing) {
                continue;
            }

            DB::table('project_paths')->insert([
                'project_id' => $r['project_id'],
                'path' => $r['path'],
                'created_at' => $this->epochToDatetime($r['created_at'] ?? time()),
            ]);
            $count++;
        }

        $this->info("  project_paths: {$count} migrated");
    }

    private function migrateEntries(SQLite3 $sqlite): void
    {
        $rows = $this->fetchAll($sqlite, 'SELECT * FROM knowledge_entries');
        $count = 0;
        $idMap = [];

        foreach ($rows as $r) {
            // Entry ids are now auto-increment bigints, so the legacy SQLite
            // UUID id cannot be preserved. Dedup on (project_id, title) and map
            // the old uuid → new bigint id for the dependent tables below.
            $existing = DB::table('knowledge_entries')
                ->where('project_id', $r['project_id'])
                ->where('title', $r['title'])
                ->first();

            if ($existing) {
                $idMap[$r['id']] = $existing->id;

                continue;
            }

            $status = $r['status'] === 'indexed' ? 'approved' : $r['status'];

            $newId = DB::table('knowledge_entries')->insertGetId([
                'project_id' => $r['project_id'],
                'title' => $r['title'],
                'content' => $r['content'],
                'category' => $r['category'],
                'source' => $r['source'] ?? 'manual',
                'author' => $r['author'] ?? '',
                'status' => $status,
                'metadata' => $r['metadata'] ?? '{}',
                'created_at' => $this->epochToDatetime($r['created_at']),
                'updated_at' => $this->epochToDatetime($r['updated_at']),
            ]);
            $idMap[$r['id']] = $newId;
            $count++;
        }

        $this->info("  knowledge_entries: {$count} migrated (status 'indexed' → 'approved')");
        $this->idMaps['entries'] = $idMap;
    }

    private function migrateTags(SQLite3 $sqlite): void
    {
        $rows = $this->fetchAll($sqlite, 'SELECT * FROM tags');
        $count = 0;
        $idMap = [];

        foreach ($rows as $r) {
            $existing = DB::table('tags')
                ->where('project_id', $r['project_id'])
                ->where('name', $r['name'])
                ->first();

            if ($existing) {
                $idMap[$r['id']] = $existing->id;

                continue;
            }

            $newId = DB::table('tags')->insertGetId([
                'project_id' => $r['project_id'],
                'name' => $r['name'],
            ]);
            $idMap[$r['id']] = $newId;
            $count++;
        }

        $this->info("  tags: {$count} migrated");
        $this->idMaps['tags'] = $idMap;
    }

    private function migrateEntryTags(SQLite3 $sqlite): void
    {
        $rows = $this->fetchAll($sqlite, 'SELECT * FROM entry_tags');
        $count = 0;
        $tagMap = $this->idMaps['tags'] ?? [];
        $entryMap = $this->idMaps['entries'] ?? [];

        foreach ($rows as $r) {
            $newTagId = $tagMap[$r['tag_id']] ?? null;
            $newEntryId = $entryMap[$r['entry_id']] ?? null;
            if ($newTagId === null || $newEntryId === null) {
                continue;
            }

            $existing = DB::table('entry_tags')
                ->where('entry_id', $newEntryId)
                ->where('tag_id', $newTagId)
                ->exists();
            if ($existing) {
                continue;
            }

            DB::table('entry_tags')->insert([
                'entry_id' => $newEntryId,
                'tag_id' => $newTagId,
            ]);
            $count++;
        }

        $this->info("  entry_tags: {$count} migrated");
    }

    private function migrateEntities(SQLite3 $sqlite): void
    {
        $rows = $this->fetchAll($sqlite, 'SELECT * FROM entities');
        $count = 0;
        $idMap = [];

        foreach ($rows as $r) {
            $existing = DB::table('entities')
                ->where('project_id', $r['project_id'])
                ->where('name', $r['name'])
                ->first();

            if ($existing) {
                $idMap[$r['id']] = $existing->id;

                continue;
            }

            $newId = DB::table('entities')->insertGetId([
                'project_id' => $r['project_id'],
                'name' => $r['name'],
                'type' => $r['type'] ?? '',
            ]);
            $idMap[$r['id']] = $newId;
            $count++;
        }

        $this->info("  entities: {$count} migrated");
        $this->idMaps['entities'] = $idMap;
    }

    private function migrateRelations(SQLite3 $sqlite): void
    {
        $rows = $this->fetchAll($sqlite, 'SELECT * FROM relations');
        $count = 0;
        $entityMap = $this->idMaps['entities'] ?? [];
        $entryMap = $this->idMaps['entries'] ?? [];

        foreach ($rows as $r) {
            $newSubjectId = $entityMap[$r['subject_id']] ?? null;
            $newObjectId = $entityMap[$r['object_id']] ?? null;
            if ($newSubjectId === null || $newObjectId === null) {
                continue;
            }

            $existing = DB::table('relations')
                ->where('project_id', $r['project_id'])
                ->where('subject_id', $newSubjectId)
                ->where('predicate', $r['predicate'])
                ->where('object_id', $newObjectId)
                ->exists();
            if ($existing) {
                continue;
            }

            $newEntryId = ($r['entry_id'] ?? null) ? ($entryMap[$r['entry_id']] ?? null) : null;

            DB::table('relations')->insert([
                'project_id' => $r['project_id'],
                'subject_id' => $newSubjectId,
                'predicate' => $r['predicate'],
                'object_id' => $newObjectId,
                'entry_id' => $newEntryId,
                'created_at' => $this->epochToDatetime($r['created_at'] ?? time()),
            ]);
            $count++;
        }

        $this->info("  relations: {$count} migrated");
    }

    private function migrateEntryEntities(SQLite3 $sqlite): void
    {
        $rows = $this->fetchAll($sqlite, 'SELECT * FROM entry_entities');
        $count = 0;
        $entityMap = $this->idMaps['entities'] ?? [];
        $entryMap = $this->idMaps['entries'] ?? [];

        foreach ($rows as $r) {
            $newEntityId = $entityMap[$r['entity_id']] ?? null;
            $newEntryId = $entryMap[$r['entry_id']] ?? null;
            if ($newEntityId === null || $newEntryId === null) {
                continue;
            }

            $existing = DB::table('entry_entities')
                ->where('entry_id', $newEntryId)
                ->where('entity_id', $newEntityId)
                ->exists();
            if ($existing) {
                continue;
            }

            DB::table('entry_entities')->insert([
                'entry_id' => $newEntryId,
                'entity_id' => $newEntityId,
            ]);
            $count++;
        }

        $this->info("  entry_entities: {$count} migrated");
    }

    private function migrateEntryLinks(SQLite3 $sqlite): void
    {
        $rows = $this->fetchAll($sqlite, 'SELECT * FROM entry_links');
        $count = 0;
        $entryMap = $this->idMaps['entries'] ?? [];

        foreach ($rows as $r) {
            $newFrom = $entryMap[$r['from_entry']] ?? null;
            $newTo = $entryMap[$r['to_entry']] ?? null;
            if ($newFrom === null || $newTo === null) {
                continue;
            }

            $existing = DB::table('entry_links')
                ->where('from_entry', $newFrom)
                ->where('to_entry', $newTo)
                ->where('relation', $r['relation'])
                ->exists();
            if ($existing) {
                continue;
            }

            DB::table('entry_links')->insert([
                'from_entry' => $newFrom,
                'to_entry' => $newTo,
                'relation' => $r['relation'],
            ]);
            $count++;
        }

        $this->info("  entry_links: {$count} migrated");
    }

    /** @var array<string, array<int|string, int>> */
    private array $idMaps = [];

    /** @return array<int, array<string, mixed>> */
    private function fetchAll(SQLite3 $sqlite, string $query): array
    {
        $result = $sqlite->query($query);
        $rows = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $rows[] = $row;
        }

        return $rows;
    }
}
