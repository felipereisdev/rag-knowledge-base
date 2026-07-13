# Hybrid Knowledge Importance Classifier Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an asynchronous, auditable hybrid classifier that uses the Claude SDK for semantic scoring and deterministic application rules to decide whether automatically captured knowledge deserves the human approval queue.

**Architecture:** All writes flow through `KnowledgeWriter`. Classified sources enter `classifying`, then a dedicated queue job obtains or creates a versioned semantic assessment, applies deterministic rules and the configured threshold, and transitions the entry according to `off`, `shadow`, or `enforce`. Imports and Martis manual creation remain outside classification, while failures always fail open to `pending`.

**Tech Stack:** PHP 8.3, Laravel 13, PostgreSQL, Laravel queues, Claude Code CLI/SDK process, Martis 1.11.7, Pest 4, PHPStan, Pint.

---

## Non-negotiable implementation constraints

- Do not edit `vendor/` or published Martis assets.
- Use `php artisan martis:resource` before writing the Martis resource by hand.
- Put every user-visible label in `lang/en`, `lang/pt_PT`, and `lang/pt_BR` with identical keys.
- Do not classify `import` or `manual` sources.
- Do not call the real Claude process from automated tests; inject a fake semantic judge.
- Do not store chain-of-thought. Persist only bounded scores, short reasons, rule identifiers, versions, timings, and sanitized errors.
- Treat candidate text as untrusted data and invoke Claude without tools, slash commands, or project access.
- Preserve unrelated working-tree changes and the existing deduplication pipeline.

## Target file map

### New production files

- `app/Enums/ImportanceAssessmentStatus.php`
- `app/Enums/ImportanceClassifierMode.php`
- `app/Enums/ImportanceVerdict.php`
- `app/Enums/KnowledgeSource.php`
- `app/Jobs/ClassifyKnowledgeEntryJob.php`
- `app/Martis/Resources/ImportanceClassifierSettingResource.php`
- `app/Models/ImportanceAssessment.php`
- `app/Models/ImportanceClassifierSetting.php`
- `app/Services/Importance/ClaudeImportanceJudge.php`
- `app/Services/Importance/DeterministicImportanceRules.php`
- `app/Services/Importance/HybridImportanceClassifier.php`
- `app/Services/Importance/ImportanceCandidate.php`
- `app/Services/Importance/ImportanceCandidateNormalizer.php`
- `app/Services/Importance/ImportanceClassificationResult.php`
- `app/Services/Importance/ImportanceResponseParser.php`
- `app/Services/Importance/KnowledgeIngestionPolicy.php`
- `app/Services/Importance/RuleEvaluation.php`
- `app/Services/Importance/SemanticImportanceAssessment.php`
- `app/Services/Importance/SemanticImportanceJudge.php`
- `app/Services/Importance/ImportancePrompt.php`
- `app/Console/Commands/RagImportanceReportCommand.php`
- `database/migrations/2026_07_13_000003_add_importance_classifier.php`
- `bin/classification-worker.sh`
- `lang/en/importance.php`
- `lang/pt_PT/importance.php`
- `lang/pt_BR/importance.php`

### Existing production files to modify

- `app/Console/Commands/RagStoreCommand.php`
- `app/Enums/KnowledgeStatus.php`
- `app/Jobs/CondenseSessionJob.php`
- `app/Martis/Actions/ApproveEntries.php`
- `app/Martis/Actions/RejectEntries.php`
- `app/Martis/Dashboards/MainDashboard.php`
- `app/Martis/Filters/StatusFilter.php`
- `app/Martis/Resources/KnowledgeEntryResource.php`
- `app/Mcp/Tools/RagStatusTool.php`
- `app/Mcp/Tools/RagStoreKnowledgeTool.php`
- `app/Models/KnowledgeEntry.php`
- `app/Observers/KnowledgeEntryObserver.php`
- `app/Services/Importing/DocumentImporter.php`
- `app/Services/Knowledge/KnowledgeWriter.php`
- `config/rag.php`
- `README.md`

### Test and fixture files

- `tests/Fixtures/importance/must-keep.json`
- `tests/Feature/Console/RagImportanceReportCommandTest.php`
- `tests/Feature/Console/RagStoreCommandTest.php`
- `tests/Feature/Jobs/ClassifyKnowledgeEntryJobTest.php`
- `tests/Feature/Martis/ImportanceClassifierSettingResourceTest.php`
- `tests/Feature/Mcp/RagStatusToolTest.php`
- `tests/Feature/Mcp/RagStoreKnowledgeToolTest.php`
- `tests/Unit/Observers/KnowledgeEntryObserverTest.php`
- `tests/Unit/Services/Importance/ClaudeImportanceJudgeTest.php`
- `tests/Unit/Services/Importance/DeterministicImportanceRulesTest.php`
- `tests/Unit/Services/Importance/HybridImportanceClassifierTest.php`
- `tests/Unit/Services/Importance/ImportanceCandidateNormalizerTest.php`
- `tests/Unit/Services/Importance/ImportanceResponseParserTest.php`
- `tests/Unit/Services/Importance/KnowledgeIngestionPolicyTest.php`
- `tests/Unit/Services/Knowledge/KnowledgeWriterTest.php`

## Task 1: Introduce the persistence model and closed enums

**Files:**

- Create: `app/Enums/ImportanceAssessmentStatus.php`
- Create: `app/Enums/ImportanceClassifierMode.php`
- Create: `app/Enums/ImportanceVerdict.php`
- Create: `app/Enums/KnowledgeSource.php`
- Modify: `app/Enums/KnowledgeStatus.php`
- Create: `app/Models/ImportanceAssessment.php`
- Create: `app/Models/ImportanceClassifierSetting.php`
- Modify: `app/Models/KnowledgeEntry.php`
- Create: `database/migrations/2026_07_13_000003_add_importance_classifier.php`
- Test: `tests/Feature/Database/ImportanceClassifierSchemaTest.php`
- Test: `tests/Unit/Enums/ImportanceEnumsTest.php`

- [ ] Write failing enum tests that require these exact backed values:
  - classifier mode: `off`, `shadow`, `enforce`;
  - assessment status: `running`, `succeeded`, `failed`;
  - verdict: `important`, `not_important`;
  - knowledge source: `condense`, `mcp`, `cli`, `import`, `manual`;
  - knowledge status: existing values plus `classifying`.

- [ ] Run the focused tests and confirm failure because the new types do not exist:

```bash
PAO_DISABLE=true ./vendor/bin/pest tests/Unit/Enums/ImportanceEnumsTest.php
```

Expected: failing tests or class-not-found errors.

- [ ] Implement the string-backed enums and add `KnowledgeStatus::Classifying` without changing existing stored values.

- [ ] Write a failing schema test that asserts:
  - `importance_classifier_settings` is a singleton-compatible table with `mode` defaulting to `shadow` and `threshold` defaulting to `70`;
  - `importance_assessments` stores project, candidate hash, normalized candidate JSON, model, prompt/rules versions, status, criterion scores, semantic score, final score, verdict, reasons/rules JSON, duration, sanitized error code/message, and timestamps;
  - the cache identity is unique across `(project_id, candidate_hash, model, prompt_version, rules_version)`;
  - `knowledge_entries.importance_assessment_id` is nullable and indexed;
  - the knowledge status constraint accepts `classifying`, `pending`, `approved`, and `rejected`.

- [ ] Implement the migration. In `up()`, create the two new tables first, add the nullable foreign key to `knowledge_entries`, then replace the existing status check constraint. In `down()`, remove the foreign key/column, restore the old three-value constraint, and drop the two tables. Use explicit PostgreSQL-safe constraint names matching the current schema.

- [ ] Seed exactly one `importance_classifier_settings` row inside the migration with `mode=shadow` and `threshold=70`, using an explicit stable primary key so the Resource always edits the singleton rather than creating settings opportunistically at request time.

- [ ] Implement model casts and relationships:
  - `ImportanceClassifierSetting`: enum cast for mode and integer threshold;
  - `ImportanceAssessment`: enum casts, integer score casts, array casts for candidate/reasons/rules, `belongsTo(Project::class)`;
  - `KnowledgeEntry`: `belongsTo(ImportanceAssessment::class)` and enum/source casts only where compatible with current callers.

- [ ] Run the focused tests:

```bash
PAO_DISABLE=true ./vendor/bin/pest tests/Unit/Enums/ImportanceEnumsTest.php tests/Feature/Database/ImportanceClassifierSchemaTest.php
```

Expected: all focused tests pass.

- [ ] Commit the schema slice:

```bash
git add app/Enums app/Models database/migrations tests/Unit/Enums tests/Feature/Database/ImportanceClassifierSchemaTest.php
git commit -m "feat: add importance classifier persistence"
```

## Task 2: Define canonical candidates and ingestion policy

**Files:**

- Create: `app/Services/Importance/ImportanceCandidate.php`
- Create: `app/Services/Importance/ImportanceCandidateNormalizer.php`
- Create: `app/Services/Importance/KnowledgeIngestionPolicy.php`
- Create: `app/Services/Importance/ImportanceClassificationResult.php`
- Test: `tests/Unit/Services/Importance/ImportanceCandidateNormalizerTest.php`
- Test: `tests/Unit/Services/Importance/KnowledgeIngestionPolicyTest.php`

- [ ] Write failing normalizer tests proving that it:
  - includes title, content, category, source, sorted tags, sorted entities, and sorted relations;
  - normalizes line endings and repeated insignificant whitespace;
  - preserves case and meaningful punctuation in knowledge text;
  - produces identical canonical JSON and SHA-256 hashes for semantically identical collection ordering;
  - produces a different hash when relevant content changes.

- [ ] Write failing policy tests proving the closed source matrix:
  - in `shadow` and `enforce`, only `condense`, `mcp`, and `cli` classify;
  - `import` and `manual` always start `pending` and never dispatch;
  - in `off`, every source starts `pending` and never dispatches;
  - an unknown source is rejected by validation rather than classified implicitly.

- [ ] Implement immutable DTOs with documented PHPStan array shapes. `ImportanceClassificationResult` must expose semantic score, final score, verdict, concise reasons, triggered rules, cache hit, versions, and optional sanitized failure metadata.

- [ ] Implement the normalizer with deterministic key ordering and `JSON_THROW_ON_ERROR`.

- [ ] Implement `KnowledgeIngestionPolicy` as the only source-to-classification decision point.

- [ ] Run the focused tests:

```bash
PAO_DISABLE=true ./vendor/bin/pest tests/Unit/Services/Importance/ImportanceCandidateNormalizerTest.php tests/Unit/Services/Importance/KnowledgeIngestionPolicyTest.php
```

Expected: all focused tests pass.

- [ ] Commit the domain slice:

```bash
git add app/Services/Importance tests/Unit/Services/Importance
git commit -m "feat: define importance classification domain"
```

## Task 3: Implement the strict Claude SDK semantic judge

**Files:**

- Create: `app/Services/Importance/SemanticImportanceAssessment.php`
- Create: `app/Services/Importance/SemanticImportanceJudge.php`
- Create: `app/Services/Importance/ImportancePrompt.php`
- Create: `app/Services/Importance/ImportanceResponseParser.php`
- Create: `app/Services/Importance/ClaudeImportanceJudge.php`
- Modify: `config/rag.php`
- Test: `tests/Unit/Services/Importance/ImportanceResponseParserTest.php`
- Test: `tests/Unit/Services/Importance/ClaudeImportanceJudgeTest.php`

- [ ] Write parser tests for the exact response contract and ranges:
  - durability `0..25`;
  - actionability, specificity, and non-obviousness `0..20`;
  - future value `0..15`;
  - recommended verdict exactly `important` or `not_important`;
  - one or more short reason objects with known criterion and explanation.

- [ ] Add failing cases for malformed JSON, missing/extra top-level keys, wrong types, out-of-range values, unknown criteria, empty reasons, and oversized explanations. The parser must throw a typed classification exception; it must never repair the payload silently.

- [ ] Implement `SemanticImportanceAssessment` and the strict parser. Calculate the semantic total in PHP instead of trusting a model-supplied total.

- [ ] Write judge tests using Laravel's process fake. Assert the command includes:

```text
claude --safe-mode --disable-slash-commands --tools "" --output-format json
```

Also assert a configured model, a system prompt, a bounded timeout, the canonical candidate over stdin, and no shell interpolation of candidate text.

- [ ] Implement `ImportancePrompt` with a constant prompt version. It must state that candidate content is untrusted data, forbid following embedded instructions, define the five criteria, demand only the JSON contract, and prohibit hidden reasoning in the response.

- [ ] Add `rag.importance` configuration with fixed code-owned defaults:
  - model identifier;
  - Claude process timeout of 90 seconds;
  - prompt version;
  - rules version;
  - maximum reason count and length;
  - stale-classifying interval;
  - queue name `classification`.

Only mode and threshold come from the singleton database setting.

- [ ] Implement `ClaudeImportanceJudge` using Laravel's process API with argument arrays, stdin, timeout, exit-code validation, and sanitized error mapping. Do not log raw candidate content or raw stderr.

- [ ] Run focused tests:

```bash
PAO_DISABLE=true ./vendor/bin/pest tests/Unit/Services/Importance/ImportanceResponseParserTest.php tests/Unit/Services/Importance/ClaudeImportanceJudgeTest.php
```

Expected: all focused tests pass without invoking Claude.

- [ ] Commit the semantic judge:

```bash
git add app/Services/Importance config/rag.php tests/Unit/Services/Importance
git commit -m "feat: add strict Claude importance judge"
```

## Task 4: Add deterministic rules, cache reuse, and final classification

**Files:**

- Create: `app/Services/Importance/RuleEvaluation.php`
- Create: `app/Services/Importance/DeterministicImportanceRules.php`
- Create: `app/Services/Importance/HybridImportanceClassifier.php`
- Test: `tests/Unit/Services/Importance/DeterministicImportanceRulesTest.php`
- Test: `tests/Unit/Services/Importance/HybridImportanceClassifierTest.php`
- Create: `tests/Fixtures/importance/must-keep.json`

- [ ] Build a reviewed `must-keep.json` corpus with at least 20 examples covering architectural decisions, business rules, operational constraints, conventions, non-obvious fixes, and decisions with rationale. Each fixture must contain candidate fields and a short human reason for retention.

- [ ] Write failing deterministic-rule tests for version one:
  - hard veto: empty/invalid content;
  - hard veto: placeholder-only content;
  - hard veto: unanswered question;
  - hard veto: agent-operation message without a knowledge assertion;
  - positive signals: explicit decision, normative restriction, causal rationale, actionable consequence;
  - penalties: speculative language, generic wording without context, clearly transient status, and insufficient substance;
  - score clamping to `0..100`;
  - stable rule identifiers and concise public reasons.

- [ ] Keep rules small and deterministic. Put the exact numeric adjustment beside each rule as a named constant and include the rules version in the evaluation. Do not infer duplicates here; condensation dedup remains separate.

- [ ] Write failing classifier tests proving:
  - semantic scores are summed, then deterministic adjustments are applied;
  - the final verdict is `important` when `final_score >= threshold`;
  - Claude's recommended verdict is recorded but cannot override the computed verdict;
  - a succeeded assessment with the same cache identity skips the judge;
  - changing only threshold reuses semantic assessment and recalculates the final verdict;
  - changing candidate, model, prompt version, or rules version misses cache;
  - a failed assessment is retried rather than treated as a successful cache hit;
  - concurrent unique-key contention reads the winning assessment;
  - no fixture in the must-keep corpus can receive a deterministic hard veto.

- [ ] Implement `HybridImportanceClassifier` with a transaction around cache acquisition. Persist `running` before the external call, then update to `succeeded` or `failed`. Keep network/process execution outside long-held database locks.

- [ ] On a unique-key race, catch only the database unique-violation case, reload the matching row, and either reuse `succeeded` or wait/retry through a typed transient exception when another worker still owns `running`.

- [ ] Persist only the canonical candidate, bounded criterion scores, public reasons, triggered rule IDs/adjustments, duration, versions, and sanitized errors.

- [ ] Run focused tests:

```bash
PAO_DISABLE=true ./vendor/bin/pest tests/Unit/Services/Importance/DeterministicImportanceRulesTest.php tests/Unit/Services/Importance/HybridImportanceClassifierTest.php
```

Expected: all focused tests pass, including the must-keep corpus assertions.

- [ ] Commit the hybrid classifier:

```bash
git add app/Services/Importance tests/Unit/Services/Importance tests/Fixtures/importance/must-keep.json
git commit -m "feat: combine semantic and deterministic importance rules"
```

## Task 5: Add the idempotent asynchronous classification job

**Files:**

- Create: `app/Jobs/ClassifyKnowledgeEntryJob.php`
- Modify: `app/Observers/KnowledgeEntryObserver.php`
- Test: `tests/Feature/Jobs/ClassifyKnowledgeEntryJobTest.php`
- Modify: `tests/Unit/Observers/KnowledgeEntryObserverTest.php`

- [ ] Write failing job tests for all state transitions:

| Mode | Computed verdict | Final entry status | Required metadata |
|---|---|---|---|
| `shadow` | important | `pending` | score, verdict, versions |
| `shadow` | not important | `pending` | score, verdict, `would_reject=true` |
| `enforce` | important | `pending` | score, verdict, versions |
| `enforce` | not important | `rejected` | score, verdict, versions |

- [ ] Add failing cases proving the job:
  - runs on queue `classification`;
  - returns immediately unless the entry is still `classifying`;
  - associates `importance_assessment_id` and writes `metadata.importance` atomically;
  - does not overwrite unrelated metadata keys;
  - is harmless when delivered twice;
  - retries typed transient failures with bounded attempts/backoff;
  - fails open from `classifying` to `pending` on terminal timeout, unavailable binary, invalid response, or unexpected exception;
  - writes `classification_error` with safe code/message/version and never rejects on a technical failure;
  - terminal `failed()` recovery is itself idempotent.

- [ ] Implement the job with explicit `$tries`, `$timeout`, `backoff()`, queue selection, structured logs, and a private atomic transition method guarded by `where status = classifying`.

- [ ] Update observer tests first. Require no indexing on entry creation while `classifying`, and exactly one index dispatch when status changes from `classifying` to `pending`. Existing approved/pending/rejected behavior must remain intact.

- [ ] Modify `KnowledgeEntryObserver` to use enum values and status transitions rather than indexing a newly created `classifying` entry.

- [ ] Run focused tests:

```bash
PAO_DISABLE=true ./vendor/bin/pest tests/Feature/Jobs/ClassifyKnowledgeEntryJobTest.php tests/Unit/Observers/KnowledgeEntryObserverTest.php
```

Expected: all job and observer tests pass.

- [ ] Commit the async slice:

```bash
git add app/Jobs/ClassifyKnowledgeEntryJob.php app/Observers/KnowledgeEntryObserver.php tests/Feature/Jobs/ClassifyKnowledgeEntryJobTest.php tests/Unit/Observers/KnowledgeEntryObserverTest.php
git commit -m "feat: classify knowledge asynchronously"
```

## Task 6: Centralize every ingestion source in KnowledgeWriter

**Files:**

- Modify: `app/Services/Knowledge/KnowledgeWriter.php`
- Modify: `app/Console/Commands/RagStoreCommand.php`
- Modify: `app/Mcp/Tools/RagStoreKnowledgeTool.php`
- Modify: `app/Jobs/CondenseSessionJob.php`
- Modify: `app/Services/Importing/DocumentImporter.php`
- Create: `tests/Unit/Services/Knowledge/KnowledgeWriterTest.php`
- Modify: `tests/Feature/Console/RagStoreCommandTest.php`
- Modify: `tests/Feature/Mcp/RagStoreKnowledgeToolTest.php`
- Modify: existing condensation/import tests that assert initial status or dispatched jobs.

- [ ] Write failing `KnowledgeWriter` tests that use `Queue::fake()` and prove:
  - classified sources in `shadow`/`enforce` are created as `classifying` and dispatch after commit;
  - the dispatch cannot observe a partially written entry or missing tags/entities/relations;
  - `off`, `import`, and `manual` create `pending` and do not dispatch;
  - an unknown source fails validation before persistence;
  - tags, entities, and relations keep their current behavior.

- [ ] Refactor `KnowledgeWriter::store()` to accept `KnowledgeSource|string`, normalize it through the closed enum, consult `KnowledgeIngestionPolicy`, persist all related records in one transaction, and schedule `ClassifyKnowledgeEntryJob::dispatch($entry->id)->afterCommit()` only when required.

- [ ] Update the CLI test to assert it delegates to `KnowledgeWriter` with `KnowledgeSource::Cli`; remove the command's duplicate direct database/entity/relation implementation.

- [ ] Update the MCP tool test to expect a response that says the entry is being classified when mode is `shadow`/`enforce`, and pending approval immediately when mode is `off`. Keep the returned entry identifier.

- [ ] Update condensation to pass `KnowledgeSource::Condense`, MCP to pass `KnowledgeSource::Mcp`, and importer to pass `KnowledgeSource::Import`. Do not change condensation's semantic dedup ordering.

- [ ] Add regression assertions that imported entries remain `pending` and never dispatch classification.

- [ ] Run all affected tests:

```bash
PAO_DISABLE=true ./vendor/bin/pest tests/Unit/Services/Knowledge/KnowledgeWriterTest.php tests/Feature/Console/RagStoreCommandTest.php tests/Feature/Mcp/RagStoreKnowledgeToolTest.php tests/Feature/Jobs/CondenseSessionJobTest.php tests/Unit/Services/Importing
```

Expected: all ingestion paths pass through the writer and only the three approved sources dispatch classification.

- [ ] Commit ingestion centralization:

```bash
git add app/Services/Knowledge/KnowledgeWriter.php app/Console/Commands/RagStoreCommand.php app/Mcp/Tools/RagStoreKnowledgeTool.php app/Jobs/CondenseSessionJob.php app/Services/Importing/DocumentImporter.php tests
git commit -m "refactor: centralize knowledge ingestion policy"
```

## Task 7: Expose safe Martis configuration and audit details

**Files:**

- Create via generator: `app/Martis/Resources/ImportanceClassifierSettingResource.php`
- Modify: `app/Martis/Resources/KnowledgeEntryResource.php`
- Modify: `app/Martis/Actions/ApproveEntries.php`
- Modify: `app/Martis/Actions/RejectEntries.php`
- Modify: `app/Martis/Filters/StatusFilter.php`
- Modify: `app/Martis/Dashboards/MainDashboard.php`
- Create: `lang/en/importance.php`
- Create: `lang/pt_PT/importance.php`
- Create: `lang/pt_BR/importance.php`
- Create: `tests/Feature/Martis/ImportanceClassifierSettingResourceTest.php`
- Modify: existing Martis resource/action/dashboard/filter tests.

- [ ] Run the Martis generator before editing the resource:

```bash
php artisan martis:resource ImportanceClassifierSetting
```

Expected: a resource is generated under `app/Martis/Resources`. Inspect the generated file and preserve package conventions rather than replacing unrelated registration code.

- [ ] Write failing resource tests requiring singleton behavior: creation and deletion unauthorized, update authorized according to existing policy, model resolves to `ImportanceClassifierSetting`, and only mode/threshold are editable.

- [ ] Implement the fields with Martis primitives:
  - `Select` for mode, fed from enum values and validated against the enum;
  - `Number` for threshold, validated as integer `0..100`;
  - read-only detail/index fields for active model, prompt version, and rules version if the Resource can expose them without persisting editable copies.

- [ ] Add the same translation key set in all three locales for resource labels, fields, help text, modes, audit labels, action errors, and dashboard classification count. Verify no user-visible string is hardcoded.

- [ ] Write failing action tests proving `ApproveEntries` and `RejectEntries` refuse every `classifying` model with a translated `ActionResponse::danger`, while retaining existing behavior for `pending`, `approved`, and `rejected` entries.

- [ ] Update `KnowledgeEntryResource` to display importance score, verdict, reasons, rules, versions, cache status, and classification error from the relation/metadata on detail. Keep raw normalized candidate and process diagnostics hidden. Ensure the status field cannot be used to bypass the `classifying` guard.

- [ ] Update `StatusFilter` options through `KnowledgeStatus::options()` and add a dashboard count for `classifying` separate from human `pending`.

- [ ] Run Martis tests:

```bash
PAO_DISABLE=true ./vendor/bin/pest tests/Feature/Martis tests/Unit/Martis
```

Expected: singleton configuration, translated fields, action guards, filters, and dashboard counts pass.

- [ ] Commit the admin surface:

```bash
git add app/Martis lang tests/Feature/Martis tests/Unit/Martis
git commit -m "feat: expose importance classifier administration"
```

## Task 8: Add operational status, health reporting, and the host worker

**Files:**

- Modify: `app/Mcp/Tools/RagStatusTool.php`
- Create: `app/Console/Commands/RagImportanceReportCommand.php`
- Create: `bin/classification-worker.sh`
- Modify: `README.md`
- Create: `tests/Feature/Mcp/RagStatusToolTest.php`
- Create: `tests/Feature/Console/RagImportanceReportCommandTest.php`

- [ ] Write failing `rag_status` tests requiring:
  - count of entries in `classifying`;
  - count of stale `classifying` entries older than the configured interval;
  - succeeded and failed assessment counts;
  - shadow `would_keep` and `would_reject` counts;
  - active mode, threshold, model, prompt version, and rules version;
  - pending and failed job counts scoped to the `classification` queue where database queue tables are available.

- [ ] Implement status queries without loading entry bodies or assessment payloads. Preserve all existing status keys for backward compatibility and add a nested `importance_classifier` object.

- [ ] Write failing command tests for `php artisan rag:importance-report`. Require project selection, a minimum reviewed sample option defaulting to 50, score distribution, projected queue reduction, approved entries marked `would_reject`, rejected entries marked `would_keep`, stale entries, and an explicit readiness verdict.

- [ ] Implement the report so readiness is true only when all approved rollout gates hold:
  - at least 50 classified and subsequently human-reviewed entries;
  - zero false rejects in the must-keep corpus test suite;
  - at most 5% `would_reject` among human-approved entries;
  - at least 30% projected queue reduction;
  - zero stale `classifying` entries.

The command reports readiness only; it must never change mode automatically.

- [ ] Add `bin/classification-worker.sh` as a host-side helper. It must fail clearly when `claude` is absent, resolve the project root safely, and run:

```bash
php artisan queue:work classification --queue=classification --tries=3 --timeout=120
```

Note the leading `classification` connection argument (Task 5 fix round 1): the job runs on the dedicated `classification` queue *connection* (`config/queue.php`, wired from `rag.importance.queue_connection`), not the default `database` connection — its `retry_after` is sized above the job's `$timeout` via `ClassifyKnowledgeEntryJob::classificationRetryAfterSeconds()`. Running `queue:work` without the connection argument falls back to the default connection's 90s `retry_after`, which is shorter than the job's 120s `$timeout` and reopens the double-delivery bug the dedicated connection exists to prevent.

Document that the ordering is: Claude process timeout (`rag.importance.timeout`, 90s) < job `$timeout` (120s) < `classification` connection `retry_after` (150s) — each derived from the one before it, not independently configured.

- [ ] Document setup, shadow rollout, worker supervision, status inspection through MCP, calibration report usage, manual `enforce` activation, and rollback to `shadow`/`off`. State explicitly that the production Docker image does not provide Claude and the classification worker therefore runs on a trusted host with Claude authenticated.

- [ ] Run focused operational tests and shell syntax validation:

```bash
PAO_DISABLE=true ./vendor/bin/pest tests/Feature/Mcp/RagStatusToolTest.php tests/Feature/Console/RagImportanceReportCommandTest.php
bash -n bin/classification-worker.sh
```

Expected: tests pass and the worker script has valid shell syntax.

- [ ] Commit operations support:

```bash
git add app/Mcp/Tools/RagStatusTool.php app/Console/Commands/RagImportanceReportCommand.php bin/classification-worker.sh README.md tests/Feature/Mcp/RagStatusToolTest.php tests/Feature/Console/RagImportanceReportCommandTest.php
git commit -m "feat: add importance classifier operations support"
```

## Task 9: Complete calibration coverage and end-to-end regressions

**Files:**

- Expand: `tests/Fixtures/importance/must-keep.json`
- Create: `tests/Fixtures/importance/must-reject.json`
- Create: `tests/Fixtures/importance/borderline.json`
- Create: `tests/Feature/ImportanceClassifierWorkflowTest.php`
- Modify: relevant search/indexing tests.

- [ ] Expand the versioned corpus to at least 50 reviewed examples total across must-keep, must-reject, and borderline sets. Use synthetic or anonymized text only; record expected disposition and a concise reviewer rationale for each example.

- [ ] Write corpus tests that exercise the deterministic layer and fake semantic outputs. Require zero hard-veto false rejects for must-keep, expected vetos for unequivocal must-reject noise, stable hashes, and explicit expectations for borderline cases.

- [ ] Write an end-to-end feature test covering these workflows with queues/processes faked:
  1. MCP write returns promptly with `classifying`;
  2. the classification job writes an audit assessment;
  3. shadow non-important becomes `pending` with `would_reject`;
  4. enforce non-important becomes recoverable `rejected`;
  5. a technical failure becomes `pending` with `classification_error`;
  6. an import remains immediate `pending` without classification;
  7. a repeated candidate reuses the assessment;
  8. lowering threshold reuses semantic scores and changes the computed disposition.

- [ ] Add search/indexing regressions proving `classifying` and `rejected` are not searchable, while transition to `pending` follows current pending-index behavior and approval still produces the existing approved search behavior.

- [ ] Run the workflow and search suites:

```bash
PAO_DISABLE=true ./vendor/bin/pest tests/Feature/ImportanceClassifierWorkflowTest.php tests/Unit/Services/Importance tests/Feature/SearchPageTest.php tests/Unit/Services/Search tests/Unit/Services/Indexing
```

Expected: the full lifecycle passes without a real Claude call.

- [ ] Commit calibration and workflow coverage:

```bash
git add tests/Fixtures/importance tests/Feature/ImportanceClassifierWorkflowTest.php tests/Unit/Services/Importance tests/Feature/SearchPageTest.php tests/Unit/Services/Search tests/Unit/Services/Indexing
git commit -m "test: cover importance classifier rollout behavior"
```

## Task 10: Run migration, quality, and acceptance verification

**Files:**

- Review all files changed in Tasks 1–9.

- [ ] Rebuild a clean test database through the project's normal test bootstrap and run migration rollback/forward coverage. Confirm the new status constraint survives both directions.

- [ ] Run the entire test suite:

```bash
PAO_DISABLE=true ./vendor/bin/pest
```

Expected: zero failures.

- [ ] Format production and test PHP:

```bash
./vendor/bin/pint
```

Expected: command exits successfully; inspect and retain only formatter changes inside this feature's files.

- [ ] Run static analysis:

```bash
./vendor/bin/phpstan analyse --memory-limit=2G
```

Expected: zero errors.

- [ ] Verify cached Laravel configuration works and contains no closures:

```bash
php artisan config:clear
php artisan config:cache
php artisan config:clear
```

Expected: all three commands succeed.

- [ ] Run operational smoke checks without invoking Claude:

```bash
php artisan list | rg "rag:importance-report"
php artisan rag:importance-report --help
bash -n bin/classification-worker.sh
```

Expected: the command is registered, help renders, and the worker script parses.

- [ ] Review the acceptance matrix manually and record evidence in the final handoff:
  - only `condense`, `mcp`, and `cli` classify;
  - no MCP/CLI request waits for Claude;
  - cache identity and threshold-only recalculation work;
  - audit data contains scores/reasons/model/versions but no chain-of-thought;
  - shadow never rejects;
  - enforce rejects only computed non-important content;
  - every technical failure fails open;
  - `classifying` is absent from search and approval queue;
  - import/manual bypass classification;
  - activation remains a human Martis change.

- [ ] Inspect the final diff and ensure no unrelated files, secrets, raw candidate fixtures, or vendor changes are included:

```bash
git status --short
git diff --check
git diff --stat
```

- [ ] Commit any final formatter or verification adjustments:

```bash
git add app config database/migrations bin lang tests README.md
git commit -m "chore: finalize importance classifier verification"
```

## Rollout after merge

- Deploy the schema and code with the seeded singleton in `shadow` mode.
- Start and supervise the host `classification` worker before producing classified entries.
- Review normal pending entries while collecting at least 50 human outcomes.
- Run `php artisan rag:importance-report` and inspect `rag_status` until every readiness gate passes.
- If prompt or rule behavior changes, increment its version and preserve historical assessments.
- Activate `enforce` manually in Martis only after review; rollback is an immediate manual switch to `shadow` or `off`.
