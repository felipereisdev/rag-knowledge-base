# Judge fence fix — markdown code fence from the real Claude CLI

## Root cause

`ClaudeImportanceJudge` invokes the Claude CLI with `--output-format json` and hands the
envelope's `result` string to the strict `ImportanceResponseParser`.

Against the **real** binary, the model **obeys the payload contract** — exactly the five
criterion scores, `recommended_verdict`, `reasons` — but wraps the object in a **markdown
code fence**, despite the system prompt explicitly saying "no markdown code fences". It
did so on every single attempt (3/3 in the original repro, and again on every live call
made while verifying this fix).

```
'```json\n{\n  "durability": 19,\n  "actionability": 17, ... }\n```'
```

`json_decode` on that text fails, the parser (correctly) refuses to repair it, so every
real call died as `invalid_json` and every entry failed open to `pending` with a
`classification_error`.

Evidence it was **only** the fence:

- envelope healthy (`is_error: false`, `subtype: "success"`, `stop_reason: "end_turn"`);
- `--system-prompt` IS honored (a canary prompt "reply with exactly BANANA" returned exactly `BANANA`);
- the scores/verdict/reasons *inside* the fence match the contract byte-for-byte.

Every judge test faked the process with a clean, hand-idealized `{"result": "{...}"}` body,
so no test ever saw the shape the CLI actually returns. That is the blind spot that let
this ship.

## What changed, and where

### `app/Services/Importance/ClaudeImportanceJudge.php`

The fence is removed in the **transport layer**, never in the parser. This distinction is
load-bearing: the parser validates the payload **contract** (exact keys, exact types, exact
ranges) and must never silently repair a malformed payload. A markdown fence is a transport
artifact of the CLI's free-text channel, not a payload defect — the bytes inside are the
contract, exactly. Stripping it is deterministic and unambiguous.

**`ImportanceResponseParser` was not touched at all.** Its repair-refusal behavior is intact.

- `assess()` now parses `unwrapCodeFence(extractResponseText($result->output()))`.
- New private `unwrapCodeFence()`. It removes a fence **only** when the trimmed text is
  *entirely* one fenced block: an opening fence with an optional language tag on its own
  line, and a closing fence at the very end. Anything else is returned untouched so the
  parser still rejects it.

Existing `is_error` / `subtype` envelope handling is unchanged and still wins over fence
handling: a Claude-side error maps to the **process-failure** code (`process_error`), never
to a response-contract code, whatever `result` looks like. (Covered by a new test.)

### The subtlety that bit the first attempt (worth reading)

My first cut guarded against multiple fenced blocks with `str_contains($inner, '```')`.
It passed every test and **still failed against the real binary.**

When the candidate under judgement is *about* markdown code fences — which is exactly what
the candidates documenting this fix are — the model **quotes** `` ```json `` inside a reason
explanation. The blunt guard saw those backticks, concluded "multiple blocks", bailed out,
and the assessment failed as `invalid_json` again.

The correct rule: **a fence delimiter only ever opens a line.** Backticks inside the payload
are ordinary bytes of a JSON string, and a JSON string cannot contain a raw newline, so they
are always mid-line. The guard now rejects only when a line's leading-whitespace-trimmed form
starts with ` ``` `. This is caught by a dedicated regression test.

This is a good example of why the real-binary verification step was worth doing: a fix that
was green across 27 tests was still broken in production.

## Behavior for every malformed input

| Input | Behavior | Rationale |
|---|---|---|
| Whole-response ` ```json ` fence around the contract | **Unwrapped**, parses, assessment succeeds | The bug. Fence is transport, payload is exact. |
| Bare ` ``` ` fence, no language tag | Unwrapped | Same, tag is optional. |
| Uppercase / other language tag, trailing spaces after the tag, leading/trailing whitespace around the fence | Unwrapped | Cosmetic variants of the same unambiguous wrapper. |
| Fenced payload whose reason text **quotes** backticks | Unwrapped, parses | Payload bytes, not a delimiter. Only line-initial fences delimit. |
| **Not fenced at all** (naked contract object) | Passed through **unchanged** | The pre-existing path; must keep working. |
| **Prose before or after** the fence | Passed through → `invalid_json` | The model said something extra we must not silently discard. Not exactly the contract. |
| **Multiple fenced blocks** | Passed through → `invalid_json` | Ambiguous which block is the answer. We refuse to guess. |
| **Unterminated fence** | Passed through → `invalid_json` | A truncated response is not a contract. |
| Closing fence with no opening fence | Passed through → `invalid_json` | Not a well-formed wrapper. |
| **Non-JSON inside a fence** | Unwrapped, then → `invalid_json` | Parser rejects it, as it should. |
| Valid JSON of the wrong schema / a list / an out-of-range score inside a fence | Unwrapped, then → `invalid_schema` | Parser stays strict. No clamping, no coercion. |
| Envelope reports its own error (`is_error` / non-`success` subtype), even with fenced `result` | `process_error` | Process failure, never a response-contract error code. Unchanged. |

No other repair is attempted, ever.

## Decision: prompt and `prompt_version` — leave both alone

**I did not touch the prompt and did NOT bump `prompt_version` (still `v1`).** I agree with
the reviewer's inclination, and the live runs strengthen the case:

1. The prompt **already** says "no markdown code fences" and the model ignores it on every
   attempt. Re-wording it is speculative — the failure is the model's rendering habit on a
   free-text channel, not an ambiguity in the instruction.
2. Bumping `prompt_version` changes **assessment cache identity** and invalidates every
   cached assessment, for a prompt whose *semantics did not change*. That is a real cost paid
   for nothing.
3. The model's compliance with the actual **payload** contract is already perfect — the
   scores, verdict, and reasons inside the fence are exactly right. There is nothing about the
   judging behavior to fix, only the wrapper to strip.
4. Fixing transport in the transport layer is deterministic and testable; prompt-nagging is
   neither.

If the CLI ever stops fencing, the unfenced path already works unchanged — no follow-up needed.

## Tests

`tests/Unit/Services/Importance/ClaudeImportanceJudgeTest.php` — the fix for the blind spot is
that faked process output is now built from the **real captured envelope shape**, not an
idealized one.

- **`realClaudeEnvelope()`** — the envelope the real binary actually emits, captured from a
  live run of this judge's exact argv (`type`, `subtype`, `is_error`, `api_error_status`,
  `duration_ms`, `ttft_ms`, `num_turns`, `result`, `stop_reason`, `session_id`,
  `total_cost_usd`, `usage`, `modelUsage`, `permission_denials`, `terminal_reason`, `uuid`).
  It carries far more keys than the judge reads — that alone is a property the old fake never
  exercised.
- **`realClaudeFencedResult()`** — the `result` text verbatim in shape: a ` ```json ` fence
  around a contract-perfect object.

New tests:

| Test | Asserts |
|---|---|
| assesses the fenced payload the REAL claude cli returns, in the real envelope | Returns a valid `SemanticImportanceAssessment`; all five scores (20/15/18/12/10), `semanticScore` 75, verdict `important`, both reasons intact |
| unwraps every benign shape of a whole-response code fence (5 cases) | ` ```json `, bare ` ``` `, uppercase tag, whitespace around the fence, trailing spaces after the tag |
| unwraps a fenced payload whose reason text quotes a code fence | The regression that the real binary caught; explanation containing ` ```json ` survives intact |
| still accepts an unfenced contract payload untouched | The pre-fix path still works |
| refuses to coerce anything that is not exactly one fenced contract object (9 cases) | prose before / prose after / two blocks / unterminated fence / closing-only fence → `invalid_json`; non-JSON in fence → `invalid_json`; wrong schema / JSON list / out-of-range score in fence → `invalid_schema` |
| files a real error envelope as a process failure even when its result text is fenced | `process_error`, and no leak of the raw error text |

The real binary is **never** called from the automated suite.

## Verification

```
PAO_DISABLE=true ./vendor/bin/pest     715 passed (4161 assertions)   [was 697; +18 new]
./vendor/bin/pint                      passed
./vendor/bin/phpstan analyse           passed, 0 errors
resources/importance/must-keep.json    byte-identical (untouched)
```

### REAL end-to-end classification (live binary, live worker, Postgres `rag`)

Note: the running worker is a long-lived process that had booted the **old** code, so it had
to be cycled before it could pick up the fix — worth remembering for any future deploy of a
judge change.

```
$ php artisan rag:store "Fence unwrap must only treat a line-initial backtick run as a delimiter" \
    --project=rag --category=architecture --content="..."
  ID: 4
```

`importance_assessments` row 4 — **succeeded, with real scores from the real model**:

```json
{
  "id": 4,
  "status": "succeeded",
  "error_code": null,
  "durability_score": 20,
  "actionability_score": 16,
  "specificity_score": 18,
  "non_obviousness_score": 15,
  "future_value_score": 9,
  "semantic_score": 78,
  "final_score": 89,
  "verdict": "important",
  "duration_ms": 35162,
  "model": "claude-haiku-4-5-20251001",
  "prompt_version": "v1",
  "rules_version": "v6"
}
```

`knowledge_entries.metadata.importance` — a real assessment, no `classification_error`:

```json
{
  "mode": "shadow",
  "model": "claude-haiku-4-5-20251001",
  "verdict": "important",
  "cache_hit": false,
  "final_score": 89,
  "semantic_score": 78,
  "would_reject": false,
  "classified_at": "2026-07-14T14:38:17+00:00",
  "prompt_version": "v1",
  "rules_version": "v6",
  "candidate_hash": "47731165dc9dc09d375acaab9ee379340552c7a096c308f16db287b6a0596e89",
  "rules": [
    {"id": "normative_restriction", "reason": "States a rule or restriction to follow.", "adjustment": 6},
    {"id": "causal_rationale", "reason": "Explains why, not only what.", "adjustment": 5}
  ],
  "reasons": [
    {"criterion": "non_obviousness", "explanation": "The constraint about JSON strings never containing raw newlines is non-trivial; it explains why line-initial backticks safely delimit fences without false positives from quoted fences inside payloads."},
    {"criterion": "actionability", "explanation": "Directly specifies the correct rule for fence delimiter detection: treat backtick runs as delimiters only when they open a line, not anywhere in the payload."},
    {"criterion": "specificity", "explanation": "Explains the problem (validation failures), the root cause (overly aggressive backtick rejection), and the solution with clear reasoning about JSON structure."},
    {"criterion": "durability", "explanation": "Describes a fundamental architectural constraint in the CLI that will remain stable as long as markdown fence wrapping is used."},
    {"criterion": "future_value", "explanation": "Limited to developers working on fence-stripping logic or debugging related regressions, but important for that narrow scope to avoid re-introducing the bug."}
  ]
}
```

Semantic 78 + deterministic rules (+6 normative_restriction, +5 causal_rationale) = **final 89**,
verdict **important**, threshold 70, mode `shadow` (`would_reject: false`), in **35.2 s**.

Before the fix, the identical pipeline produced `error_code: invalid_json` and a `pending`
entry every time (assessments 1, 2, and 3 in this same database are exactly that).
