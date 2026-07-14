<?php

use App\Enums\KnowledgeStatus;
use App\Martis\Filters\StatusFilter;

it('offers every status, including classifying, as a read-only filter value', function () {
    $options = StatusFilter::make(__('rag.filters.status'))->options(request());

    // Filtering is read-only: unlike the editable status field, `classifying`
    // MUST be filterable so an administrator can find entries in flight.
    expect($options)->toBe(array_flip(KnowledgeStatus::options()))
        ->and($options)->toHaveKey(__('rag.statuses.classifying'))
        ->and($options[__('rag.statuses.classifying')])->toBe(KnowledgeStatus::Classifying->value)
        ->and(array_values($options))->toBe(KnowledgeStatus::values());
});
