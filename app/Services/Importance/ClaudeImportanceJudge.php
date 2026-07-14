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
 *  - the process runs from an isolated working directory (the system temp
 *    dir, never the project root), so it has no project access and cannot
 *    auto-load this repo's CLAUDE.md or other project context;
 *  - the timeout is bounded and configured, never "forever";
 *  - every failure mode (non-zero exit, timeout, unexpected exception, an
 *    envelope that reports its own error, invalid response) maps to
 *    `ImportanceClassificationException` with a sanitized message — raw stderr
 *    and raw candidate/response text are never embedded in the exception or
 *    logged.
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
            '-p',
            '--model',
            $this->model,
            // `--system-prompt`, not `--append-system-prompt`: the judge persona
            // must OWN the system slot. Appending would leave Claude Code's
            // default agentic system prompt in place and merely bolt the strict
            // five-criterion contract onto the end of it, which loosens the
            // output contract (more prose, more `invalid_json`) for no benefit —
            // this session has no tools and no project access to need the
            // agentic preamble for.
            '--system-prompt',
            $this->prompt->systemPrompt(),
        ];

        try {
            $result = Process::timeout($this->timeoutSeconds)
                // Run from an isolated cwd (never the project root) so Claude
                // Code cannot auto-load CLAUDE.md or other project context
                // into a session that is judging untrusted candidate text.
                ->path(sys_get_temp_dir())
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

        return $this->parser->parse(
            $this->unwrapCodeFence($this->extractResponseText($result->output()))
        );
    }

    /**
     * `claude --output-format json` wraps the model's reply in an envelope
     * (type/subtype/is_error/result/cost/...); the strict five-criterion
     * contract this judge expects lives in the `result` string field. Falling
     * back to the raw output when the envelope is absent lets the (equally
     * strict) parser reject it with a clear invalid-JSON/invalid-schema
     * error rather than this method guessing or repairing anything.
     *
     * The envelope's own failure signal is honoured first. The CLI can exit 0
     * and still report the turn failed (`is_error: true`, `subtype` other than
     * `success` — e.g. an API error or a max-turns abort), in which case
     * `result` holds an English error string, not the contract. Handing that to
     * the parser would fail open all the same, but it would file a PROCESS
     * failure under a RESPONSE-CONTRACT error code (`invalid_json` /
     * `invalid_schema`) and so corrupt the very signal `rag_status` and the
     * calibration report use to tell "Claude is broken" from "the prompt needs
     * work".
     */
    private function extractResponseText(string $rawOutput): string
    {
        $envelope = json_decode($rawOutput, true);

        if (! is_array($envelope)) {
            return $rawOutput;
        }

        $subtype = is_string($envelope['subtype'] ?? null) ? $envelope['subtype'] : null;

        if (! empty($envelope['is_error']) || ($subtype !== null && $subtype !== 'success')) {
            throw ImportanceClassificationException::processErrored($subtype);
        }

        if (is_string($envelope['result'] ?? null)) {
            return $envelope['result'];
        }

        return $rawOutput;
    }

    /**
     * Strips a markdown code fence that wraps the whole response text.
     *
     * The real CLI returns the contract object fenced (```json ... ```) even
     * though the system prompt forbids fences, and it does so consistently.
     * That fence is a TRANSPORT artifact of the CLI's free-text channel, not a
     * payload defect: the bytes inside are the contract, exactly. Removing an
     * unambiguous wrapper here — in the transport layer — keeps
     * `ImportanceResponseParser` strict, which is the point: the parser
     * validates the payload CONTRACT (exact keys, exact types, exact ranges)
     * and must never repair a malformed payload.
     *
     * Deliberately narrow. The fence is only removed when the trimmed text is
     * ENTIRELY one fenced block: an opening fence with an optional language tag
     * on its own line, and a closing fence at the very end. Everything else is
     * returned untouched, so the parser still rejects it:
     *  - prose before or after the fence -> not entirely a fenced block, passed
     *    through, fails as `invalid_json` (the model added content we must not
     *    silently discard);
     *  - more than one fenced block -> ambiguous which one is the answer, passed
     *    through, fails as `invalid_json`;
     *  - an unterminated fence -> the response was truncated, passed through,
     *    fails as `invalid_json`;
     *  - non-JSON (or non-contract JSON) inside the fence -> unwrapped, then
     *    fails as `invalid_json` / `invalid_schema` in the parser, as it should.
     *
     * No other repair is attempted here, ever.
     */
    private function unwrapCodeFence(string $text): string
    {
        $trimmed = trim($text);

        if (preg_match('/\A```[A-Za-z0-9_+-]*[^\S\r\n]*\R(.*)\R[^\S\r\n]*```\z/s', $trimmed, $matches) !== 1) {
            return $text;
        }

        $inner = $matches[1];

        // A fence DELIMITER only ever opens at the start of a line, so that is
        // the only thing that means "there is more than one block in here" —
        // and multiple blocks are ambiguous, so we refuse to guess.
        //
        // Backticks that merely appear INSIDE the payload are not delimiters
        // and must not trip this: the model happily quotes "```json" inside a
        // reason explanation when the candidate it is judging talks about code
        // fences (which is exactly what the candidates for THIS fix do). A JSON
        // string cannot contain a raw newline, so such backticks are always
        // mid-line, never at a line start. Rejecting on a bare str_contains
        // would fail those payloads as `invalid_json` for no reason.
        foreach (preg_split('/\R/', $inner) ?: [] as $line) {
            if (str_starts_with(ltrim($line), '```')) {
                return $text;
            }
        }

        return $inner;
    }
}
