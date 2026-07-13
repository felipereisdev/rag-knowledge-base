<?php

namespace App\Services\Importance;

use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Invokes the Claude CLI in a locked-down, non-interactive mode to produce a
 * semantic importance assessment for a normalized candidate.
 *
 * Safety properties enforced here (see the design doc, "Segurança do
 * processo Claude"):
 *  - the candidate travels only over stdin, never interpolated into argv or
 *    a shell string, so it cannot smuggle extra CLI flags or shell syntax;
 *  - the process runs with no tools, no slash commands, and safe mode, so it
 *    cannot act on the untrusted candidate content it is asked to judge;
 *  - the timeout is bounded and configured, never "forever";
 *  - every failure mode (non-zero exit, timeout, unexpected exception,
 *    invalid response) maps to `ImportanceClassificationException` with a
 *    sanitized message — raw stderr and raw candidate/response text are
 *    never embedded in the exception or logged.
 */
final class ClaudeImportanceJudge implements SemanticImportanceJudge
{
    public function __construct(
        private readonly ImportancePrompt $prompt,
        private readonly ImportanceResponseParser $parser,
        private readonly string $model,
        private readonly int $timeoutSeconds,
        private readonly string $binary = 'claude',
    ) {}

    public function assess(NormalizedImportanceCandidate $candidate): SemanticImportanceAssessment
    {
        $command = [
            $this->binary,
            '--safe-mode',
            '--disable-slash-commands',
            '--tools',
            '',
            '--output-format',
            'json',
            '--model',
            $this->model,
            '--append-system-prompt',
            $this->prompt->systemPrompt(),
        ];

        try {
            $result = Process::timeout($this->timeoutSeconds)
                ->input($candidate->json())
                ->run($command);
        } catch (ProcessTimedOutException) {
            throw ImportanceClassificationException::timedOut();
        } catch (Throwable $exception) {
            throw ImportanceClassificationException::processError($exception);
        }

        if (! $result->successful()) {
            throw ImportanceClassificationException::processFailed($result->exitCode() ?? -1);
        }

        return $this->parser->parse($this->extractResponseText($result->output()));
    }

    /**
     * `claude --output-format json` wraps the model's reply in an envelope
     * (type/subtype/result/cost/...); the strict five-criterion contract
     * this judge expects lives in the `result` string field. Falling back
     * to the raw output when the envelope is absent lets the (equally
     * strict) parser reject it with a clear invalid-JSON/invalid-schema
     * error rather than this method guessing or repairing anything.
     */
    private function extractResponseText(string $rawOutput): string
    {
        $envelope = json_decode($rawOutput, true);

        if (is_array($envelope) && is_string($envelope['result'] ?? null)) {
            return $envelope['result'];
        }

        return $rawOutput;
    }
}
