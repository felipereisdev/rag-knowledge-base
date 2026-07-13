<?php

use App\Services\Evaluation\RetrievalMetrics;

it('calculates recall reciprocal rank and ndcg at k', function () {
    $metrics = (new RetrievalMetrics)->calculate(
        rankedTitles: ['Noise', 'Expected B', 'Expected A', 'Other'],
        expectedTitles: ['Expected A', 'Expected B'],
        k: 3,
    );

    $expectedDcg = (1 / log(3, 2)) + (1 / log(4, 2));
    $idealDcg = 1 + (1 / log(3, 2));

    expect($metrics['recall'])->toBe(1.0)
        ->and($metrics['reciprocalRank'])->toBe(0.5)
        ->and($metrics['ndcg'])->toEqualWithDelta($expectedDcg / $idealDcg, 0.000001)
        ->and($metrics['zeroResults'])->toBeFalse();
});

it('returns zero metrics for an empty ranking', function () {
    $metrics = (new RetrievalMetrics)->calculate(
        rankedTitles: [],
        expectedTitles: ['Expected'],
        k: 5,
    );

    expect($metrics)->toBe([
        'recall' => 0.0,
        'reciprocalRank' => 0.0,
        'ndcg' => 0.0,
        'zeroResults' => true,
    ]);
});

it('matches titles case-insensitively and ignores surrounding whitespace', function () {
    $metrics = (new RetrievalMetrics)->calculate(
        rankedTitles: ['  OWNER SCOPING  '],
        expectedTitles: ['Owner scoping'],
        k: 1,
    );

    expect($metrics['recall'])->toBe(1.0)
        ->and($metrics['reciprocalRank'])->toBe(1.0)
        ->and($metrics['ndcg'])->toBe(1.0);
});

it('does not count the same relevant title twice', function () {
    $metrics = (new RetrievalMetrics)->calculate(
        rankedTitles: ['Expected', 'Expected'],
        expectedTitles: ['Expected', 'Another expected title'],
        k: 2,
    );

    expect($metrics['recall'])->toBe(0.5)
        ->and($metrics['reciprocalRank'])->toBe(1.0);
});

it('rejects invalid metric inputs', function () {
    expect(fn () => (new RetrievalMetrics)->calculate([], [], 5))
        ->toThrow(InvalidArgumentException::class, 'expected_titles')
        ->and(fn () => (new RetrievalMetrics)->calculate([], ['Expected'], 0))
        ->toThrow(InvalidArgumentException::class, 'k');
});
