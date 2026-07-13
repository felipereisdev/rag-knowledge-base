<?php

namespace App\Services\Importance;

use App\Enums\ImportanceVerdict;

final readonly class ImportanceClassificationResult
{
    /**
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
