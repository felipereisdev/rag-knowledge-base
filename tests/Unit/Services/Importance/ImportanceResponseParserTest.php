<?php

use App\Enums\ImportanceVerdict;
use App\Services\Importance\ImportanceClassificationException;
use App\Services\Importance\ImportanceResponseParser;
use App\Services\Importance\SemanticImportanceAssessment;

function validImportancePayload(array $overrides = []): array
{
    return array_replace([
        'durability' => 20,
        'actionability' => 15,
        'specificity' => 18,
        'non_obviousness' => 12,
        'future_value' => 10,
        'recommended_verdict' => 'important',
        'reasons' => [
            ['criterion' => 'durability', 'explanation' => 'Describes a durable architectural decision.'],
        ],
    ], $overrides);
}

function importanceParser(int $maxReasonCount = 5, int $maxReasonLength = 280): ImportanceResponseParser
{
    return new ImportanceResponseParser(maxReasonCount: $maxReasonCount, maxReasonLength: $maxReasonLength);
}

it('parses a valid response into a strict assessment with a PHP-computed total', function () {
    $assessment = importanceParser()->parse(json_encode(validImportancePayload()));

    expect($assessment)->toBeInstanceOf(SemanticImportanceAssessment::class)
        ->and($assessment->durability)->toBe(20)
        ->and($assessment->actionability)->toBe(15)
        ->and($assessment->specificity)->toBe(18)
        ->and($assessment->nonObviousness)->toBe(12)
        ->and($assessment->futureValue)->toBe(10)
        ->and($assessment->semanticScore)->toBe(75)
        ->and($assessment->recommendedVerdict)->toBe(ImportanceVerdict::Important)
        ->and($assessment->reasons)->toBe([
            ['criterion' => 'durability', 'explanation' => 'Describes a durable architectural decision.'],
        ]);
});

it('accepts the not_important verdict and sums a different score', function () {
    $assessment = importanceParser()->parse(json_encode(validImportancePayload([
        'durability' => 0,
        'actionability' => 0,
        'specificity' => 0,
        'non_obviousness' => 0,
        'future_value' => 0,
        'recommended_verdict' => 'not_important',
        'reasons' => [
            ['criterion' => 'specificity', 'explanation' => 'No concrete context provided.'],
        ],
    ])));

    expect($assessment->semanticScore)->toBe(0)
        ->and($assessment->recommendedVerdict)->toBe(ImportanceVerdict::NotImportant);
});

it('accepts multiple reasons across different known criteria', function () {
    $assessment = importanceParser()->parse(json_encode(validImportancePayload([
        'reasons' => [
            ['criterion' => 'durability', 'explanation' => 'Stays true across releases.'],
            ['criterion' => 'actionability', 'explanation' => 'Directly guides a future migration.'],
        ],
    ])));

    expect($assessment->reasons)->toHaveCount(2);
});

it('ignores a model-supplied total and only trusts the PHP-computed sum', function () {
    // The strict contract has no "total" key at all; a payload that smuggles
    // one in must be rejected as an unexpected top-level key rather than
    // silently trusted.
    $payload = validImportancePayload();
    $payload['total'] = 999;

    expect(fn () => importanceParser()->parse(json_encode($payload)))
        ->toThrow(ImportanceClassificationException::class);
});

it('throws a typed exception for malformed JSON', function () {
    expect(fn () => importanceParser()->parse('{not valid json'))
        ->toThrow(ImportanceClassificationException::class);
});

it('throws for a JSON value that is not an object', function () {
    expect(fn () => importanceParser()->parse(json_encode([1, 2, 3])))
        ->toThrow(ImportanceClassificationException::class);
});

it('throws when a required top-level key is missing', function () {
    $payload = validImportancePayload();
    unset($payload['future_value']);

    expect(fn () => importanceParser()->parse(json_encode($payload)))
        ->toThrow(ImportanceClassificationException::class);
});

it('throws when an unexpected extra top-level key is present', function () {
    $payload = validImportancePayload();
    $payload['extra_field'] = 'nope';

    expect(fn () => importanceParser()->parse(json_encode($payload)))
        ->toThrow(ImportanceClassificationException::class);
});

it('throws when a criterion score has the wrong type', function () {
    $payload = validImportancePayload(['durability' => '20']);

    expect(fn () => importanceParser()->parse(json_encode($payload)))
        ->toThrow(ImportanceClassificationException::class);
});

it('throws when a criterion score is a float instead of an integer', function () {
    $payload = validImportancePayload(['durability' => 20.5]);

    expect(fn () => importanceParser()->parse(json_encode($payload)))
        ->toThrow(ImportanceClassificationException::class);
});

it('throws when durability exceeds its 0-25 range', function () {
    $payload = validImportancePayload(['durability' => 26]);

    expect(fn () => importanceParser()->parse(json_encode($payload)))
        ->toThrow(ImportanceClassificationException::class);
});

it('throws when durability is negative', function () {
    $payload = validImportancePayload(['durability' => -1]);

    expect(fn () => importanceParser()->parse(json_encode($payload)))
        ->toThrow(ImportanceClassificationException::class);
});

it('throws when actionability exceeds its 0-20 range', function () {
    $payload = validImportancePayload(['actionability' => 21]);

    expect(fn () => importanceParser()->parse(json_encode($payload)))
        ->toThrow(ImportanceClassificationException::class);
});

it('throws when specificity exceeds its 0-20 range', function () {
    $payload = validImportancePayload(['specificity' => 21]);

    expect(fn () => importanceParser()->parse(json_encode($payload)))
        ->toThrow(ImportanceClassificationException::class);
});

it('throws when non_obviousness exceeds its 0-20 range', function () {
    $payload = validImportancePayload(['non_obviousness' => 21]);

    expect(fn () => importanceParser()->parse(json_encode($payload)))
        ->toThrow(ImportanceClassificationException::class);
});

it('throws when future_value exceeds its 0-15 range', function () {
    $payload = validImportancePayload(['future_value' => 16]);

    expect(fn () => importanceParser()->parse(json_encode($payload)))
        ->toThrow(ImportanceClassificationException::class);
});

it('throws when recommended_verdict is not exactly important or not_important', function () {
    $payload = validImportancePayload(['recommended_verdict' => 'maybe']);

    expect(fn () => importanceParser()->parse(json_encode($payload)))
        ->toThrow(ImportanceClassificationException::class);
});

it('throws when recommended_verdict has the wrong type', function () {
    $payload = validImportancePayload(['recommended_verdict' => true]);

    expect(fn () => importanceParser()->parse(json_encode($payload)))
        ->toThrow(ImportanceClassificationException::class);
});

it('throws when reasons is empty', function () {
    $payload = validImportancePayload(['reasons' => []]);

    expect(fn () => importanceParser()->parse(json_encode($payload)))
        ->toThrow(ImportanceClassificationException::class);
});

it('throws when reasons is not a list', function () {
    $payload = validImportancePayload();
    $payload['reasons'] = ['criterion' => 'durability', 'explanation' => 'x'];

    expect(fn () => importanceParser()->parse(json_encode($payload)))
        ->toThrow(ImportanceClassificationException::class);
});

it('throws when a reason references an unknown criterion', function () {
    $payload = validImportancePayload([
        'reasons' => [
            ['criterion' => 'vibes', 'explanation' => 'It feels important.'],
        ],
    ]);

    expect(fn () => importanceParser()->parse(json_encode($payload)))
        ->toThrow(ImportanceClassificationException::class);
});

it('throws when a reason is missing the explanation key', function () {
    $payload = validImportancePayload([
        'reasons' => [
            ['criterion' => 'durability'],
        ],
    ]);

    expect(fn () => importanceParser()->parse(json_encode($payload)))
        ->toThrow(ImportanceClassificationException::class);
});

it('throws when a reason has an unexpected extra key', function () {
    $payload = validImportancePayload([
        'reasons' => [
            ['criterion' => 'durability', 'explanation' => 'Fine.', 'confidence' => 0.9],
        ],
    ]);

    expect(fn () => importanceParser()->parse(json_encode($payload)))
        ->toThrow(ImportanceClassificationException::class);
});

it('throws when a reason explanation is an empty string', function () {
    $payload = validImportancePayload([
        'reasons' => [
            ['criterion' => 'durability', 'explanation' => ''],
        ],
    ]);

    expect(fn () => importanceParser()->parse(json_encode($payload)))
        ->toThrow(ImportanceClassificationException::class);
});

it('throws when an explanation exceeds the configured maximum length', function () {
    $payload = validImportancePayload([
        'reasons' => [
            ['criterion' => 'durability', 'explanation' => str_repeat('a', 21)],
        ],
    ]);

    expect(fn () => importanceParser(maxReasonLength: 20)->parse(json_encode($payload)))
        ->toThrow(ImportanceClassificationException::class);
});

it('throws when the reason count exceeds the configured maximum', function () {
    $payload = validImportancePayload([
        'reasons' => [
            ['criterion' => 'durability', 'explanation' => 'One.'],
            ['criterion' => 'actionability', 'explanation' => 'Two.'],
            ['criterion' => 'specificity', 'explanation' => 'Three.'],
        ],
    ]);

    expect(fn () => importanceParser(maxReasonCount: 2)->parse(json_encode($payload)))
        ->toThrow(ImportanceClassificationException::class);
});

it('never repairs a malformed payload silently', function () {
    // A payload with a wrong-typed score must fail outright, not be
    // coerced/cast into range.
    $payload = validImportancePayload(['durability' => '20']);

    try {
        importanceParser()->parse(json_encode($payload));
        expect(false)->toBeTrue('Expected an exception to be thrown.');
    } catch (ImportanceClassificationException $exception) {
        expect($exception->errorCode)->toBe('invalid_schema');
    }
});
