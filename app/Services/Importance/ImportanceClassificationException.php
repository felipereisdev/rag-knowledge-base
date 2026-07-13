<?php

namespace App\Services\Importance;

use RuntimeException;
use Throwable;

/**
 * Thrown when the semantic judge cannot produce a usable assessment.
 *
 * Every message here is deliberately generic: it must never embed the raw
 * candidate text, the model's raw response body, or raw process stderr, so
 * that it stays safe to surface (directly or via `errorCode`) in logs, audit
 * records, and sanitized error metadata. Callers should branch on
 * `errorCode` rather than parsing `getMessage()`.
 *
 * `errorCode` mirrors the naming used by `ImportanceClassificationResult`
 * (Task 4), which persists exactly this kind of sanitized code/message pair.
 */
final class ImportanceClassificationException extends RuntimeException
{
    private function __construct(
        public readonly string $errorCode,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }

    public static function invalidJson(?Throwable $previous = null): self
    {
        return new self(
            'invalid_json',
            'Claude importance response was not valid JSON.',
            $previous,
        );
    }

    public static function invalidSchema(string $reason): self
    {
        return new self(
            'invalid_schema',
            "Claude importance response did not match the expected contract: {$reason}",
        );
    }

    /**
     * Also covers a missing/unresolvable `claude` binary: without a shell,
     * an unresolvable executable surfaces as a non-zero exit (conventionally
     * 127) rather than a distinct PHP exception, so there is no separate
     * "binary unavailable" case to model here.
     */
    public static function processFailed(int $exitCode): self
    {
        return new self(
            'process_failed',
            "Claude importance process exited with a non-zero status ({$exitCode}).",
        );
    }

    public static function timedOut(): self
    {
        return new self(
            'timeout',
            'Claude importance process timed out.',
        );
    }

    public static function processError(?Throwable $previous = null): self
    {
        return new self(
            'process_error',
            'Claude importance process failed unexpectedly.',
            $previous,
        );
    }
}
