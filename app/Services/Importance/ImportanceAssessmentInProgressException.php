<?php

namespace App\Services\Importance;

use RuntimeException;

/**
 * Thrown when another worker already owns the `running` assessment for this
 * cache identity, so this worker has nothing to do but wait.
 *
 * This is the one *transient* failure of the classifier, and it is a distinct
 * type on purpose: the caller (the classification job) retries it with bounded
 * backoff, while every other failure is terminal and surfaces as a sanitized
 * error on `ImportanceClassificationResult` so the job can fail open.
 */
final class ImportanceAssessmentInProgressException extends RuntimeException
{
    public const string ERROR_CODE = 'assessment_in_progress';

    public readonly string $errorCode;

    private function __construct(string $message)
    {
        parent::__construct($message);

        $this->errorCode = self::ERROR_CODE;
    }

    public static function make(): self
    {
        return new self('Another worker is already assessing this importance candidate.');
    }
}
