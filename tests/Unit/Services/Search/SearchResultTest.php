<?php

use App\Services\Search\SearchResult;

it('keeps fusion and source scores distinct', function () {
    $result = new SearchResult(
        entryId: 42,
        title: 'Owner scoping',
        snippet: 'Scope entries by owner.',
        fusionScore: 2 / 60,
        category: 'architecture',
        tags: ['search'],
        matchedBy: ['vector', 'keyword'],
        graphExpanded: false,
        semanticSimilarity: 0.84,
        keywordScore: 0.12,
        matchedChunkIndex: 3,
    );

    expect($result->fusionScore)->toBe(2 / 60)
        ->and($result->semanticSimilarity)->toBe(0.84)
        ->and($result->keywordScore)->toBe(0.12)
        ->and($result->matchedChunkIndex)->toBe(3)
        ->and(property_exists($result, 'score'))->toBeFalse();
});
