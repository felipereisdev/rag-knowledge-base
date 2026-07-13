<?php

use App\Enums\ImportanceAssessmentStatus;
use App\Enums\ImportanceClassifierMode;
use App\Enums\ImportanceVerdict;
use App\Enums\KnowledgeSource;
use App\Enums\KnowledgeStatus;

it('exposes the fixed classifier lifecycle values', function () {
    expect(ImportanceClassifierMode::values())->toBe([
        'off',
        'shadow',
        'enforce',
    ])->and(ImportanceAssessmentStatus::values())->toBe([
        'running',
        'succeeded',
        'failed',
    ])->and(ImportanceVerdict::values())->toBe([
        'important',
        'not_important',
    ]);
});

it('exposes every supported knowledge source and status', function () {
    expect(KnowledgeSource::values())->toBe([
        'condense',
        'mcp',
        'cli',
        'import',
        'manual',
    ])->and(KnowledgeStatus::values())->toBe([
        'classifying',
        'pending',
        'approved',
        'rejected',
    ])->and(KnowledgeStatus::options()['classifying'])->toBe('Classifying');
});
