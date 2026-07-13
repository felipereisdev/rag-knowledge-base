<?php

namespace App\Services\Importance;

use App\Enums\ImportanceVerdict;
use JsonException;

/**
 * Strictly validates the Claude importance response contract.
 *
 * The contract is intentionally rigid: exactly five integer criterion
 * scores within fixed ranges, an exact verdict string, and one or more
 * bounded reasons referencing known criteria — nothing more, nothing less.
 * Any deviation (malformed JSON, missing/extra keys, wrong types,
 * out-of-range values, unknown criteria, empty reasons, oversized
 * explanations) throws `ImportanceClassificationException` immediately.
 * The parser never coerces, clamps, or otherwise repairs a bad payload.
 *
 * The semantic total is always computed here as the sum of the five
 * validated criterion scores; the contract has no "total" field to trust.
 */
final class ImportanceResponseParser
{
    /**
     * Inclusive [min, max] score ranges per criterion, fixed by the brief.
     *
     * @var array<string, array{0:int, 1:int}>
     */
    private const CRITERIA_RANGES = [
        'durability' => [0, 25],
        'actionability' => [0, 20],
        'specificity' => [0, 20],
        'non_obviousness' => [0, 20],
        'future_value' => [0, 15],
    ];

    private const REASON_KEYS = ['criterion', 'explanation'];

    public function __construct(
        private readonly int $maxReasonCount,
        private readonly int $maxReasonLength,
    ) {}

    public function parse(string $raw): SemanticImportanceAssessment
    {
        $decoded = $this->decode($raw);

        $expectedKeys = [...array_keys(self::CRITERIA_RANGES), 'recommended_verdict', 'reasons'];
        $this->assertExactKeys($decoded, $expectedKeys, 'response');

        $scores = [];
        foreach (self::CRITERIA_RANGES as $criterion => [$min, $max]) {
            $scores[$criterion] = $this->readScore($decoded, $criterion, $min, $max);
        }

        $verdict = $this->readVerdict($decoded);
        $reasons = $this->readReasons($decoded);

        return new SemanticImportanceAssessment(
            durability: $scores['durability'],
            actionability: $scores['actionability'],
            specificity: $scores['specificity'],
            nonObviousness: $scores['non_obviousness'],
            futureValue: $scores['future_value'],
            semanticScore: array_sum($scores),
            recommendedVerdict: $verdict,
            reasons: $reasons,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $raw): array
    {
        try {
            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw ImportanceClassificationException::invalidJson($exception);
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw ImportanceClassificationException::invalidSchema('the response must be a JSON object');
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $expectedKeys
     */
    private function assertExactKeys(array $data, array $expectedKeys, string $context): void
    {
        $actualKeys = array_keys($data);
        sort($actualKeys);
        $sortedExpected = $expectedKeys;
        sort($sortedExpected);

        if ($actualKeys !== $sortedExpected) {
            throw ImportanceClassificationException::invalidSchema("the {$context} has missing, extra, or renamed keys");
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function readScore(array $data, string $criterion, int $min, int $max): int
    {
        $value = $data[$criterion];

        if (! is_int($value)) {
            throw ImportanceClassificationException::invalidSchema("\"{$criterion}\" must be an integer");
        }

        if ($value < $min || $value > $max) {
            throw ImportanceClassificationException::invalidSchema("\"{$criterion}\" must be between {$min} and {$max}");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function readVerdict(array $data): ImportanceVerdict
    {
        $value = $data['recommended_verdict'];

        if (! is_string($value) || ! in_array($value, ImportanceVerdict::values(), true)) {
            throw ImportanceClassificationException::invalidSchema('"recommended_verdict" must be "important" or "not_important"');
        }

        return ImportanceVerdict::from($value);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array{criterion:string, explanation:string}>
     */
    private function readReasons(array $data): array
    {
        $reasons = $data['reasons'];

        if (! is_array($reasons) || ! array_is_list($reasons) || $reasons === []) {
            throw ImportanceClassificationException::invalidSchema('"reasons" must be a non-empty list');
        }

        if (count($reasons) > $this->maxReasonCount) {
            throw ImportanceClassificationException::invalidSchema("\"reasons\" must not exceed {$this->maxReasonCount} entries");
        }

        return array_map(fn (mixed $reason): array => $this->readReason($reason), $reasons);
    }

    /**
     * @return array{criterion:string, explanation:string}
     */
    private function readReason(mixed $reason): array
    {
        if (! is_array($reason)) {
            throw ImportanceClassificationException::invalidSchema('each reason must be an object');
        }

        $this->assertExactKeys($reason, self::REASON_KEYS, 'reason');

        $criterion = $reason['criterion'];
        if (! is_string($criterion) || ! array_key_exists($criterion, self::CRITERIA_RANGES)) {
            throw ImportanceClassificationException::invalidSchema('reason "criterion" must be one of the five known criteria');
        }

        $explanation = $reason['explanation'];
        if (! is_string($explanation) || $explanation === '') {
            throw ImportanceClassificationException::invalidSchema('reason "explanation" must be a non-empty string');
        }

        if (mb_strlen($explanation) > $this->maxReasonLength) {
            throw ImportanceClassificationException::invalidSchema("reason \"explanation\" must not exceed {$this->maxReasonLength} characters");
        }

        return ['criterion' => $criterion, 'explanation' => $explanation];
    }
}
