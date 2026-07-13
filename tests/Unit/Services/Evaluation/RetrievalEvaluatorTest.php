<?php

use App\Services\Evaluation\EvaluationCase;
use App\Services\Evaluation\RetrievalEvaluator;
use App\Services\Evaluation\RetrievalMetrics;
use App\Services\Search\HybridSearcher;
use App\Services\Search\SearchResult;

it('evaluates search results by stable entry title', function () {
    $searcher = Mockery::mock(HybridSearcher::class);
    $searcher->shouldReceive('search')
        ->once()
        ->with('How is the stack catalog represented?', 'rag')
        ->andReturn([
            new SearchResult(
                entryId: 16,
                title: 'ProjectTechnology is the single source of truth for the project tech-stack catalog',
                snippet: 'ProjectTechnology owns the catalog.',
                fusionScore: 1 / 60,
                semanticSimilarity: 0.81,
                keywordScore: null,
                matchedChunkIndex: 0,
                category: 'convention',
                tags: [],
                matchedBy: ['vector'],
                graphExpanded: false,
            ),
        ]);

    $results = (new RetrievalEvaluator($searcher, new RetrievalMetrics))->evaluate(
        projectId: 'rag',
        cases: [new EvaluationCase(
            query: 'How is the stack catalog represented?',
            expectedTitles: ['ProjectTechnology is the single source of truth for the project tech-stack catalog'],
        )],
        k: 5,
    );

    expect($results)->toHaveCount(1)
        ->and($results[0]->recallAtK)->toBe(1.0)
        ->and($results[0]->reciprocalRank)->toBe(1.0)
        ->and($results[0]->ndcgAtK)->toBe(1.0)
        ->and($results[0]->zeroResults)->toBeFalse();
});
