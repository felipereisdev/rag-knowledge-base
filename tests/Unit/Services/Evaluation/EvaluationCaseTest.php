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

it('requires the query to be a string', function (mixed $query) {
    expect(fn () => EvaluationCase::fromArray([
        'query' => $query,
        'expected_titles' => ['Expected'],
    ]))->toThrow(InvalidArgumentException::class, 'query');
})->with([
    'integer' => 42,
    'boolean' => true,
    'array' => [['Question']],
    'object' => new stdClass,
]);

it('requires expected titles to be a list of strings', function (mixed $titles) {
    expect(fn () => EvaluationCase::fromArray([
        'query' => 'Question',
        'expected_titles' => $titles,
    ]))->toThrow(InvalidArgumentException::class, 'expected_titles');
})->with([
    'associative array' => [['first' => 'Expected']],
    'integer title' => [[42]],
    'boolean title' => [[true]],
    'array title' => [[['Expected']]],
    'object title' => [[new stdClass]],
]);

it('trims titles and rejects a list containing only blanks', function () {
    $case = EvaluationCase::fromArray([
        'query' => 'Question',
        'expected_titles' => ['  Expected  ', ''],
    ]);

    expect($case->expectedTitles)->toBe(['Expected'])
        ->and(fn () => EvaluationCase::fromArray([
            'query' => 'Question',
            'expected_titles' => [' ', "\t"],
        ]))->toThrow(InvalidArgumentException::class, 'expected_titles');
});
