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
    app()->setLocale((string) config('app.locale'));

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

it('rejects invalid k options before retrieval', function (mixed $k, string $message) {
    config()->set('rag.search.limit', 10);

    $searcher = Mockery::mock(HybridSearcher::class);
    $searcher->shouldNotReceive('search');
    app()->instance(HybridSearcher::class, $searcher);

    $this->artisan('rag:evaluate', [
        'dataset' => $this->dataset,
        '--k' => $k,
    ])
        ->assertFailed()
        ->expectsOutputToContain($message);
})->with([
    'trailing characters' => ['5foo', '--k must be a positive integer.'],
    'decimal' => ['1.5', '--k must be a positive integer.'],
    'zero' => [0, '--k must be a positive integer.'],
    'negative' => [-1, '--k must be a positive integer.'],
    'above configured search limit' => [11, '--k cannot exceed the configured search limit of 10.'],
]);

it('rejects invalid threshold options before retrieval', function (string $option, mixed $value) {
    $searcher = Mockery::mock(HybridSearcher::class);
    $searcher->shouldNotReceive('search');
    app()->instance(HybridSearcher::class, $searcher);

    $this->artisan('rag:evaluate', [
        'dataset' => $this->dataset,
        '--k' => 5,
        $option => $value,
    ])
        ->assertFailed()
        ->expectsOutputToContain("{$option} must be a finite number between 0 and 1.");
})->with([
    'nonnumeric recall' => ['--min-recall', 'nope'],
    'negative recall' => ['--min-recall', '-0.1'],
    'recall above one' => ['--min-recall', '1.1'],
    'non-finite recall' => ['--min-recall', 'INF'],
    'nonnumeric mrr' => ['--min-mrr', 'nope'],
    'negative mrr' => ['--min-mrr', '-0.1'],
    'mrr above one' => ['--min-mrr', '1.1'],
    'non-finite mrr' => ['--min-mrr', 'NAN'],
]);

it('rejects malformed dataset schema before retrieval', function (array $dataset, string $message) {
    file_put_contents($this->dataset, json_encode($dataset, JSON_THROW_ON_ERROR));

    $searcher = Mockery::mock(HybridSearcher::class);
    $searcher->shouldNotReceive('search');
    app()->instance(HybridSearcher::class, $searcher);

    $this->artisan('rag:evaluate', [
        'dataset' => $this->dataset,
    ])
        ->assertFailed()
        ->expectsOutputToContain($message);
})->with([
    'numeric project id' => [
        [
            'project_id' => 123,
            'queries' => [['query' => 'Question', 'expected_titles' => ['Expected']]],
        ],
        'Evaluation dataset project_id must be a string.',
    ],
    'associative queries object' => [
        [
            'project_id' => 'rag',
            'queries' => ['first' => ['query' => 'Question', 'expected_titles' => ['Expected']]],
        ],
        'Evaluation dataset queries must be a list.',
    ],
    'scalar query item' => [
        [
            'project_id' => 'rag',
            'queries' => ['Question'],
        ],
        'Every evaluation query must be a JSON object.',
    ],
    'list query item' => [
        [
            'project_id' => 'rag',
            'queries' => [['Question', ['Expected']]],
        ],
        'Every evaluation query must be a JSON object.',
    ],
]);

it('reports malformed json with a localized generic error', function () {
    app()->setLocale('pt_BR');
    file_put_contents($this->dataset, '{malformed');

    $searcher = Mockery::mock(HybridSearcher::class);
    $searcher->shouldNotReceive('search');
    app()->instance(HybridSearcher::class, $searcher);

    $this->artisan('rag:evaluate', [
        'dataset' => $this->dataset,
    ])
        ->assertFailed()
        ->expectsOutputToContain('JSON válido');
});
