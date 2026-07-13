<?php

use App\Enums\ImportanceAssessmentStatus;
use App\Enums\ImportanceVerdict;
use App\Models\ImportanceAssessment;
use App\Models\KnowledgeEntry;
use App\Models\Project;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

it('seeds a singleton classifier setting with conservative defaults', function () {
    expect(Schema::hasTable('importance_classifier_settings'))->toBeTrue();

    $setting = DB::table('importance_classifier_settings')->where('id', 1)->first();

    expect($setting)->not->toBeNull()
        ->and($setting->mode)->toBe('shadow')
        ->and((int) $setting->threshold)->toBe(70)
        ->and(DB::table('importance_classifier_settings')->count())->toBe(1);

    expect(fn () => DB::table('importance_classifier_settings')->insert(['id' => 2]))
        ->toThrow(QueryException::class);
});

it('persists assessment cache identity and accepts a classifying entry state', function () {
    expect(Schema::hasTable('importance_assessments'))->toBeTrue()
        ->and(Schema::hasColumns('importance_assessments', [
            'id',
            'project_id',
            'candidate_hash',
            'normalized_candidate',
            'model',
            'prompt_version',
            'rules_version',
            'status',
            'durability_score',
            'actionability_score',
            'specificity_score',
            'non_obviousness_score',
            'future_value_score',
            'semantic_score',
            'final_score',
            'verdict',
            'reasons',
            'rules',
            'duration_ms',
            'error_code',
            'error_message',
            'created_at',
            'updated_at',
        ]))->toBeTrue()
        ->and(Schema::hasColumn('knowledge_entries', 'importance_assessment_id'))->toBeTrue();

    $cacheIdentity = DB::selectOne(<<<'SQL'
        SELECT indexdef
        FROM pg_indexes
        WHERE schemaname = current_schema()
          AND indexname = 'importance_assessments_cache_identity_unique'
        SQL);
    $assessmentForeignKey = DB::selectOne(<<<'SQL'
        SELECT pg_get_constraintdef(oid) AS definition
        FROM pg_constraint
        WHERE conname = 'knowledge_entries_importance_assessment_id_foreign'
        SQL);
    $statusConstraint = DB::selectOne(<<<'SQL'
        SELECT pg_get_constraintdef(oid) AS definition
        FROM pg_constraint
        WHERE conname = 'chk_status'
          AND conrelid = 'knowledge_entries'::regclass
        SQL);

    expect($cacheIdentity)->not->toBeNull()
        ->and($cacheIdentity->indexdef)->toContain('project_id')
        ->and($cacheIdentity->indexdef)->toContain('candidate_hash')
        ->and($cacheIdentity->indexdef)->toContain('model')
        ->and($cacheIdentity->indexdef)->toContain('prompt_version')
        ->and($cacheIdentity->indexdef)->toContain('rules_version')
        ->and($assessmentForeignKey)->not->toBeNull()
        ->and($assessmentForeignKey->definition)->toContain('ON DELETE SET NULL')
        ->and($statusConstraint)->not->toBeNull()
        ->and($statusConstraint->definition)->toContain("'classifying'");

    $project = Project::create([
        'id' => 'importance-schema',
        'name' => 'Importance schema',
        'root_path' => '/importance-schema',
    ]);

    $entryId = DB::table('knowledge_entries')->insertGetId([
        'project_id' => $project->id,
        'title' => 'Waiting for importance classification',
        'status' => 'classifying',
    ]);

    expect(DB::table('knowledge_entries')->where('id', $entryId)->value('status'))->toBe('classifying');
});

it('casts assessment fields and relates an entry to its assessment', function () {
    Queue::fake();

    $project = Project::create([
        'id' => 'importance-models',
        'name' => 'Importance models',
        'root_path' => '/importance-models',
    ]);
    $assessment = ImportanceAssessment::create([
        'project_id' => $project->id,
        'candidate_hash' => str_repeat('a', 64),
        'normalized_candidate' => ['title' => 'Durable rule', 'content' => 'Keep this rule.'],
        'model' => 'claude-test',
        'prompt_version' => 'v1',
        'rules_version' => 'v1',
        'status' => ImportanceAssessmentStatus::Running,
        'durability_score' => 25,
        'actionability_score' => 20,
        'specificity_score' => 20,
        'non_obviousness_score' => 20,
        'future_value_score' => 15,
        'semantic_score' => 100,
        'final_score' => 100,
        'verdict' => ImportanceVerdict::Important,
        'reasons' => ['Durable and actionable.'],
        'rules' => ['version' => 'v1'],
        'duration_ms' => 42,
    ]);
    $entry = KnowledgeEntry::create([
        'project_id' => $project->id,
        'title' => 'Durable rule',
    ]);
    $entry->importanceAssessment()->associate($assessment)->save();

    $assessment->update([
        'status' => ImportanceAssessmentStatus::Failed,
        'error_code' => 'claude_unavailable',
        'error_message' => 'The semantic judge was unavailable.',
    ]);
    $assessment->update([
        'status' => ImportanceAssessmentStatus::Running,
        'error_code' => null,
        'error_message' => null,
    ]);

    $freshAssessment = $assessment->fresh();
    $freshEntry = $entry->fresh();

    expect($freshAssessment->status)->toBe(ImportanceAssessmentStatus::Running)
        ->and($freshAssessment->verdict)->toBe(ImportanceVerdict::Important)
        ->and($freshAssessment->normalized_candidate)->toBe([
            'title' => 'Durable rule',
            'content' => 'Keep this rule.',
        ])
        ->and($freshAssessment->reasons)->toBe(['Durable and actionable.'])
        ->and($freshAssessment->rules)->toBe(['version' => 'v1'])
        ->and($freshAssessment->final_score)->toBe(100)
        ->and($freshAssessment->duration_ms)->toBe(42)
        ->and($freshEntry->importanceAssessment->is($freshAssessment))->toBeTrue();
});
