# High-Confidence Auto-Approval — Design

**Status:** approved for planning
**Date:** 2026-07-14
**Builds on:** the hybrid importance classifier (merged, `a1bc4ed`), currently running in `shadow`.

## Goal

Let the classifier **approve** entries it is highly confident about, so obviously-valuable knowledge stops waiting on a human rubber-stamp. Today the classifier only ever removes noise (`enforce` + `not_important` → `rejected`); an `important` entry still queues for a human. This adds the missing fourth transition.

## Why this is the riskiest change in the feature

Approval is not the mirror image of rejection. It inverts the safety asymmetry the classifier was built around.

`approved` is what makes an entry **retrievable** — `HybridSearcher` filters on `status = 'approved'` (`app/Services/Search/HybridSearcher.php:370,533`). So an auto-approved entry is served to agents as trusted project knowledge without a human ever having read it.

Both errors are technically reversible (rejecting an approved entry purges its `chunk_embeddings` via `KnowledgeEntryObserver::updated()`), but they differ in **visibility**:

- A wrong rejection sits inert in a queue, waiting to be noticed.
- A wrong approval **acts silently** — it shapes answers until someone discovers it.

And the escalation that follows: candidate text is untrusted by project definition. Today, a successful prompt injection buys the attacker "junk reaches a human review queue" — a nuisance. With auto-approval, it buys "attacker-controlled text is served as project knowledge". **Injection stops being a nuisance and becomes a write vector.** The semantic score is produced by the model — precisely the component the injection targets.

Every decision below follows from that.

## Eligibility

**Eligibility** and **acting on it** are separate concepts, and the distinction matters: `shadow` computes eligibility and records it without acting.

An entry is **eligible** for auto-approval when all three hold:

1. `final_score >= auto_approve_threshold`;
2. the deterministic layer fired **at least one positive signal** (explicit decision, normative restriction, causal rationale, or actionable consequence);
3. **zero penalties** (speculative language, transient status, generic wording, insufficient substance).

Eligibility is a pure function of the assessment and the rules — it does not depend on the mode. **Only `enforce` acts on it** (see Transitions). `shadow` records eligibility as `would_approve: true` and approves nothing.

**Condition 2 is the load-bearing one.** The deterministic rules are regex, not a model — an injection cannot move them. To write into the base, an attacker must fool Claude *and* read as durable knowledge to an automaton. This is the only barrier in the eligibility chain that does not depend on the model.

Condition 3 is cheap conservatism: if anything smelled off enough to earn a penalty, the entry goes back to the human queue even with a high score.

Vetoes need no explicit check — a veto already forces `not_important` and a score of 0, so a vetoed candidate can never approach the threshold.

## Control and data

One new column on the `importance_classifier_settings` singleton:

`auto_approve_threshold` — smallint, **nullable**, default `90`.

- `null` → auto-approval **off**. Rejection (in `enforce`) still works.
- a value → auto-approval on, in `enforce`, at that threshold.

Editable in Martis alongside `mode` and `threshold`. Validated as an integer `0..100` **and `>= threshold`** — auto-approving something below the importance threshold is incoherent and the resource must refuse it.

The default of `90` means flipping to `enforce` turns on rejection *and* approval together. That coupling is only safe because the readiness report gates **both** behaviours (see below) — `READY` is never reported until auto-approval has itself been validated. `null` remains the escape hatch: if auto-approval misbehaves, disable it without losing rejection.

## Transitions

| Mode | Verdict | Eligible | Final status |
|---|---|---|---|
| `shadow` | any | any | `pending` (records `would_reject` / `would_approve`) |
| `enforce` | `not_important` | — | `rejected` |
| `enforce` | `important` | no | `pending` |
| `enforce` | `important` | **yes** | **`approved`** |

Only the fourth row is new in production.

`shadow` never acts — that is its entire identity. It computes eligibility, records it as `would_approve`, and approves nothing. This is what feeds the empirical gate.

Note a `not_important` verdict can never be eligible: `important` requires `final_score >= threshold`, and `auto_approve_threshold >= threshold`, so anything eligible is necessarily `important`. The second row's "—" is therefore structural, not a special case.

## Audit

The entry's `metadata.importance` gains `auto_approved: true` on the fourth row. An auto-approval must never be mistakable for a human click — the report depends on the distinction, and so does after-the-fact review.

`rag_status` gains an auto-approved count, and Martis gains a way to filter for auto-approved entries. Silent action requires a surface where it can be inspected.

## Calibration: two new gates

The `must-keep` corpus guards against **false rejects**. Nothing guards against **false approves**. Two gates close that.

### Gate 6 — empirical false auto-approvals

Among the entries **the human rejected**, how many would auto-approval have placed in the base?

**Tolerance: zero.** Unlike the false-reject gate (which tolerates 5%, because a false reject costs one click), a single false approval is the exact failure the feature must not produce.

**Anti-vacuity requirement: at least 10 human-rejected entries in the sample.** With zero rejections, "zero false approves among rejected" passes trivially while proving nothing — the same vacuous-pass defect a review already caught in this codebase (`approved === 0` in the false-reject gate). If the reviewer rejects very little, the gate simply cannot be validated. That is honest: a junk filter cannot be validated against junk you do not have.

### Gate 7 — `must-reject` corpus regression

No fixture in `resources/importance/must-reject.json` may satisfy the eligibility conjunction.

**Scope, stated honestly:** that corpus's semantic scores are a reviewer's estimates handed to a fake judge, so it cannot prove what the real model would score (a limitation Task 9's review already recorded). It *can* prove, model-independently, the deterministic half: no unequivocal noise fixture fires a positive signal with zero penalties. That half is what carries the injection defence, and that is what this gate pins.

### New metric (not a gate)

**Projected review reduction** — among the entries the human *approved*, how many would auto-approval have spared the click. This is the feature's benefit, measured before it is switched on.

`READY` becomes the conjunction of **seven** gates: today's five, plus these two.

## Testing

- Eligibility: each of the three conditions independently blocks auto-approval; the conjunction approves.
- Injection posture: a candidate whose text tries to inflate its own score, with a high faked semantic score but no positive deterministic signal, must **not** be auto-approved.
- Transition table: all four rows, asserting the persisted entry status and metadata (not merely that a job ran).
- `shadow` records `would_approve` and approves nothing, in every mode/verdict combination.
- An auto-approved entry is indexed and searchable; rejecting it afterwards purges its chunks.
- Both new gates block `READY` independently, and the anti-vacuity floor (fewer than 10 rejected) blocks it too.
- `auto_approve_threshold < threshold` is refused by the Martis resource.
- No test calls the real Claude binary.

## Non-goals

- Auto-approval in `shadow`. Shadow measures; it never acts.
- Auto-approval for `import` / `manual` sources — they bypass classification entirely and that does not change.
- Changing the existing five gates, the reject path, or the assessment cache identity.
- Retro-approving entries already sitting in `pending`.
