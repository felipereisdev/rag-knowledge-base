<?php

namespace App\Services\Importance;

use App\Enums\ImportanceVerdict;

final readonly class ImportanceClassificationResult
{
    /**
     * @param  list<array{criterion:string, explanation:string}>  $reasons
     * @param  list<array{id:string, adjustment:int, reason:string}>  $triggeredRules
     * @param  ImportanceVerdict|null  $recommendedVerdict  What the semantic judge *suggested* on a
     *                                                      fresh judgement. Informative only: it never takes part in computing
     *                                                      `$verdict`, which is derived from `$finalScore` and the configured
     *                                                      threshold. It is deliberately not persisted on the assessment (it is
     *                                                      not part of the audited decision), so it is null on a cache hit, on the
     *                                                      deterministic veto path, and on failure.
     */
    public function __construct(
        public ?int $semanticScore,
        public ?int $finalScore,
        public ?ImportanceVerdict $verdict,
        public array $reasons,
        public array $triggeredRules,
        public bool $cacheHit,
        public string $model,
        public string $promptVersion,
        public string $rulesVersion,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
        public ?ImportanceVerdict $recommendedVerdict = null,
    ) {}
}
