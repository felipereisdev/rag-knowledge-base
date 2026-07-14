<?php

namespace App\Services\Importance;

use App\Enums\ImportanceVerdict;

final readonly class ImportanceClassificationResult
{
    /**
     * `$verdict` is the authoritative one: derived from `$finalScore` and the
     * administrator's threshold. The judge's own *recommended* verdict
     * (`SemanticImportanceAssessment::$recommendedVerdict`) is deliberately not
     * carried here — it is never persisted, never displayed, and never consulted
     * in the decision, so surfacing it on the result would only invite a caller
     * to start trusting it.
     *
     * @param  list<array{criterion:string, explanation:string}>  $reasons
     * @param  list<array{id:string, adjustment:int, reason:string}>  $triggeredRules
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
    ) {}
}
