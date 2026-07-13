<?php

use App\Enums\ImportanceClassifierMode;
use App\Enums\KnowledgeSource;
use App\Enums\KnowledgeStatus;
use App\Services\Importance\KnowledgeIngestionPolicy;

dataset('classifiedSources', [KnowledgeSource::Condense, KnowledgeSource::Mcp, KnowledgeSource::Cli]);
dataset('exemptSources', [KnowledgeSource::Import, KnowledgeSource::Manual]);

it('classifies only approved sources outside off mode', function (KnowledgeSource $source) {
    $policy = app(KnowledgeIngestionPolicy::class);

    expect($policy->shouldClassify(ImportanceClassifierMode::Shadow, $source))->toBeTrue()
        ->and($policy->shouldClassify(ImportanceClassifierMode::Enforce, $source))->toBeTrue()
        ->and($policy->initialStatus(ImportanceClassifierMode::Shadow, $source))->toBe(KnowledgeStatus::Classifying);
})->with('classifiedSources');

it('never classifies exempt sources', function (KnowledgeSource $source) {
    $policy = app(KnowledgeIngestionPolicy::class);

    foreach (ImportanceClassifierMode::cases() as $mode) {
        expect($policy->shouldClassify($mode, $source))->toBeFalse()
            ->and($policy->initialStatus($mode, $source))->toBe(KnowledgeStatus::Pending);
    }
})->with('exemptSources');

it('disables classification for every source in off mode', function (KnowledgeSource $source) {
    $policy = app(KnowledgeIngestionPolicy::class);

    expect($policy->shouldClassify(ImportanceClassifierMode::Off, $source))->toBeFalse()
        ->and($policy->initialStatus(ImportanceClassifierMode::Off, $source))->toBe(KnowledgeStatus::Pending);
})->with(KnowledgeSource::cases());

it('rejects an unknown source instead of classifying it accidentally', function () {
    $policy = app(KnowledgeIngestionPolicy::class);

    expect(fn () => $policy->shouldClassify(ImportanceClassifierMode::Shadow, 'webhook'))
        ->toThrow(ValueError::class);
});
