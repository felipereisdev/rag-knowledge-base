<?php

namespace App\Models;

use App\Enums\ImportanceAssessmentStatus;
use App\Enums\ImportanceVerdict;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One cached importance judgement, keyed by the cache identity
 * (project + candidate hash + model + prompt version + rules version).
 *
 * @property int $id
 * @property string $project_id
 * @property string $candidate_hash
 * @property array<string, mixed> $normalized_candidate
 * @property string $model
 * @property string $prompt_version
 * @property string $rules_version
 * @property ImportanceAssessmentStatus $status
 * @property int|null $durability_score
 * @property int|null $actionability_score
 * @property int|null $specificity_score
 * @property int|null $non_obviousness_score
 * @property int|null $future_value_score
 * @property int|null $semantic_score
 * @property int|null $final_score
 * @property ImportanceVerdict|null $verdict
 * @property list<array{criterion:string, explanation:string}> $reasons
 * @property list<array{id:string, adjustment:int, reason:string}> $rules
 * @property int|null $duration_ms
 * @property string|null $error_code
 * @property string|null $error_message
 */
class ImportanceAssessment extends Model
{
    protected $fillable = [
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
    ];

    protected function casts(): array
    {
        return [
            'normalized_candidate' => 'array',
            'status' => ImportanceAssessmentStatus::class,
            'durability_score' => 'integer',
            'actionability_score' => 'integer',
            'specificity_score' => 'integer',
            'non_obviousness_score' => 'integer',
            'future_value_score' => 'integer',
            'semantic_score' => 'integer',
            'final_score' => 'integer',
            'verdict' => ImportanceVerdict::class,
            'reasons' => 'array',
            'rules' => 'array',
            'duration_ms' => 'integer',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }
}
