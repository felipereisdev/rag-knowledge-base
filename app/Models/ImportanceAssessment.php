<?php

namespace App\Models;

use App\Enums\ImportanceAssessmentStatus;
use App\Enums\ImportanceVerdict;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
