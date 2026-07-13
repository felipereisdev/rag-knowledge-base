<?php

use App\Models\KnowledgeEntry;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

it('upgrades and backfills existing vectors while preserving one trigger of each kind', function () {
    Queue::fake();

    $migrationPath = database_path('migrations/2026_07_13_000001_make_search_vectors_language_aware.php');

    expect(is_file($migrationPath))->toBeTrue();

    $migration = require $migrationPath;
    $migration->down();

    $project = Project::create([
        'id' => 'migration-upgrade',
        'name' => 'Migration upgrade',
        'root_path' => '/migration-upgrade',
        'language' => 'pt',
    ]);
    $entry = KnowledgeEntry::create([
        'project_id' => $project->id,
        'title' => 'Rotas portuguesas',
        'content' => 'As migrações ficam na pasta database.',
        'status' => 'approved',
    ]);

    $beforeUpgrade = DB::selectOne(
        "SELECT search_vector @@ plainto_tsquery('portuguese', 'ficar') AS matches
         FROM knowledge_entries WHERE id = ?",
        [$entry->id],
    );

    expect($beforeUpgrade->matches)->toBeFalse();

    $migration->up();

    $afterUpgrade = DB::selectOne(
        "SELECT search_vector @@ plainto_tsquery('portuguese', 'ficar') AS matches
         FROM knowledge_entries WHERE id = ?",
        [$entry->id],
    );
    $triggerCounts = DB::selectOne(<<<'SQL'
        SELECT
            count(*) FILTER (WHERE tgname = 'knowledge_entries_search_vector_trigger') AS entry_triggers,
            count(*) FILTER (WHERE tgname = 'projects_language_search_vector_trigger') AS project_triggers
        FROM pg_trigger
        WHERE NOT tgisinternal
        SQL);

    expect($afterUpgrade->matches)->toBeTrue()
        ->and((int) $triggerCounts->entry_triggers)->toBe(1)
        ->and((int) $triggerCounts->project_triggers)->toBe(1);
});
