<?php

use App\Services\Search\HybridSearcher;
use App\Services\Search\SearchResult;

beforeEach(function () {
    $this->dataset = tempnam(sys_get_temp_dir(), 'rag_eval_');
    file_put_contents($this->dataset, json_encode([
        'project_id' => 'rag',
        'queries' => [[
            'query' => 'Which class owns the technology catalog?',
            'expected_titles' => ['ProjectTechnology owns the catalog'],
        ]],
    ], JSON_THROW_ON_ERROR));
});

afterEach(function () {
    if (is_file($this->dataset)) {
        unlink($this->dataset);
    }
});

it('passes when aggregate metrics meet thresholds', function () {
    $searcher = Mockery::mock(HybridSearcher::class);
    $searcher->shouldReceive('search')->once()->andReturn([
        new SearchResult(
            entryId: 1,
            title: 'ProjectTechnology owns the catalog',
            snippet: 'Catalog details.',
            fusionScore: 1 / 60,
            semanticSimilarity: 0.82,
            keywordScore: null,
            matchedChunkIndex: 0,
            category: 'convention',
            tags: [],
            matchedBy: ['vector'],
            graphExpanded: false,
        ),
    ]);
    app()->instance(HybridSearcher::class, $searcher);

    $this->artisan('rag:evaluate', [
        'dataset' => $this->dataset,
        '--k' => 5,
        '--min-recall' => 0.8,
        '--min-mrr' => 0.8,
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('Average Recall@5: 1.0000')
        ->expectsOutputToContain('Mean Reciprocal Rank: 1.0000');
});

it('fails when aggregate metrics miss thresholds', function () {
    $searcher = Mockery::mock(HybridSearcher::class);
    $searcher->shouldReceive('search')->once()->andReturn([]);
    app()->instance(HybridSearcher::class, $searcher);

    $this->artisan('rag:evaluate', [
        'dataset' => $this->dataset,
        '--k' => 5,
        '--min-recall' => 0.8,
        '--min-mrr' => 0.8,
    ])
        ->assertFailed()
        ->expectsOutputToContain('Evaluation thresholds failed.');
});
