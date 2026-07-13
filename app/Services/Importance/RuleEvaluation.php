<?php

namespace App\Services\Importance;

/**
 * The deterministic half of a hybrid classification: the versioned rules that
 * fired for one normalized candidate, their net score adjustment, and whether
 * any of them is a hard veto.
 *
 * A veto is reserved for unambiguous noise. It forces the final score to zero
 * regardless of what the semantic judge thinks (and lets the classifier skip
 * the judge entirely), so it must only fire on content that carries no
 * knowledge at all.
 */
final readonly class RuleEvaluation
{
    public const int MIN_SCORE = 0;

    public const int MAX_SCORE = 100;

    /**
     * @param  list<array{id:string, adjustment:int, reason:string}>  $triggeredRules
     */
    public function __construct(
        public bool $vetoed,
        public int $adjustment,
        public array $triggeredRules,
        public string $rulesVersion,
    ) {}

    /**
     * Apply this evaluation to a semantic score, clamped to 0..100.
     */
    public function apply(int $semanticScore): int
    {
        if ($this->vetoed) {
            return self::MIN_SCORE;
        }

        return max(
            self::MIN_SCORE,
            min(self::MAX_SCORE, $semanticScore + $this->adjustment),
        );
    }
}
