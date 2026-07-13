<?php

use App\Services\Evaluation\EvaluationCase;

it('creates a case from valid dataset input', function () {
    $case = EvaluationCase::fromArray([
        'query' => 'Where is owner scoping enforced?',
        'expected_titles' => ['Owner scoping uses indexQuery'],
    ]);

    expect($case->query)->toBe('Where is owner scoping enforced?')
        ->and($case->expectedTitles)->toBe(['Owner scoping uses indexQuery']);
});

it('rejects missing queries and expected titles', function () {
    expect(fn () => EvaluationCase::fromArray([
        'query' => '',
        'expected_titles' => ['Expected'],
    ]))->toThrow(InvalidArgumentException::class, 'query')
        ->and(fn () => EvaluationCase::fromArray([
            'query' => 'Question',
            'expected_titles' => [],
        ]))->toThrow(InvalidArgumentException::class, 'expected_titles');
});
