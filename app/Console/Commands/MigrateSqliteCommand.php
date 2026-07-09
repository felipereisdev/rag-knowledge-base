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
                'project_type' => $r['project_type'] ?? '',
                'language' => $r['language'] ?? 'en',
                'created_at' => $this->epochToDatetime($r['created_at']),
                'updated_at' => $this->epochToDatetime($r['updated_at']),
            ]);
            $count++;
        }

        $this->info("  projects: {$count} migrated");
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

        foreach ($rows as $r) {
            $existing = DB::table('knowledge_entries')->where('id', $r['id'])->exists();
            if ($existing) {
                continue;
            }

            $status = $r['status'] === 'indexed' ? 'approved' : $r['status'];

            DB::table('knowledge_entries')->insert([
                'id' => $r['id'],
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
            $count++;
        }

        $this->info("  knowledge_entries: {$count} migrated (status 'indexed' → 'approved')");
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

        foreach ($rows as $r) {
            $newTagId = $tagMap[$r['tag_id']] ?? null;
            if ($newTagId === null) {
                continue;
            }

            $existing = DB::table('entry_tags')
                ->where('entry_id', $r['entry_id'])
                ->where('tag_id', $newTagId)
                ->exists();
            if ($existing) {
                continue;
            }

            DB::table('entry_tags')->insert([
                'entry_id' => $r['entry_id'],
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

            DB::table('relations')->insert([
                'project_id' => $r['project_id'],
                'subject_id' => $newSubjectId,
                'predicate' => $r['predicate'],
                'object_id' => $newObjectId,
                'entry_id' => $r['entry_id'] ?: null,
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

        foreach ($rows as $r) {
            $newEntityId = $entityMap[$r['entity_id']] ?? null;
            if ($newEntityId === null) {
                continue;
            }

            $existing = DB::table('entry_entities')
                ->where('entry_id', $r['entry_id'])
                ->where('entity_id', $newEntityId)
                ->exists();
            if ($existing) {
                continue;
            }

            DB::table('entry_entities')->insert([
                'entry_id' => $r['entry_id'],
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

        foreach ($rows as $r) {
            $existing = DB::table('entry_links')
                ->where('from_entry', $r['from_entry'])
                ->where('to_entry', $r['to_entry'])
                ->where('relation', $r['relation'])
                ->exists();
            if ($existing) {
                continue;
            }

            DB::table('entry_links')->insert([
                'from_entry' => $r['from_entry'],
                'to_entry' => $r['to_entry'],
                'relation' => $r['relation'],
            ]);
            $count++;
        }

        $this->info("  entry_links: {$count} migrated");
    }

    /** @var array<string, array<int, int>> */
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
