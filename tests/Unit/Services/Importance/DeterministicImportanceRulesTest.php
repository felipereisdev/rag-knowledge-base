<?php

use App\Services\Importance\DeterministicImportanceRules;
use App\Services\Importance\ImportanceCandidate;
use App\Services\Importance\ImportanceCandidateNormalizer;
use App\Services\Importance\NormalizedImportanceCandidate;
use App\Services\Importance\RuleEvaluation;

/**
 * @param  list<array{name:string, type?:string}>  $entities
 */
function ruleCandidate(string $content, string $title = 'Note', array $entities = []): NormalizedImportanceCandidate
{
    return (new ImportanceCandidateNormalizer)->normalize(new ImportanceCandidate(
        title: $title,
        content: $content,
        category: 'insight',
        source: 'condense',
        entities: $entities,
    ));
}

function evaluateRules(string $content, string $title = 'Note'): RuleEvaluation
{
    return (new DeterministicImportanceRules)->evaluate(ruleCandidate($content, $title));
}

/**
 * @return list<string>
 */
function triggeredRuleIds(RuleEvaluation $evaluation): array
{
    return array_map(
        static fn (array $rule): string => $rule['id'],
        $evaluation->triggeredRules,
    );
}

/**
 * The reviewed corpus of knowledge that must never be lost to a rule.
 *
 * @return list<array{id:string, kind:string, retention_reason:string, candidate:array{title:string, content:string, category:string, source:string, tags:list<string>, entities:list<array{name:string, type:string}>, relations:list<array{subject:string, predicate:string, object:string}>}}>
 */
function mustKeepFixtures(): array
{
    $path = base_path('tests/Fixtures/importance/must-keep.json');
    $corpus = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

    return $corpus['fixtures'];
}

/**
 * Real, durable knowledge that carries NO positive knowledge-assertion marker and
 * yet uses the vocabulary `agent_operation_only` keys on. Nothing in `fixtures`
 * can exercise that veto (every fixture there carries a positive signal, and the
 * veto only fires when none is present), so this block is the only regression
 * test the highest-cost rule has.
 *
 * @return list<array{id:string, why:string, candidate:array{title:string, content:string, entities:list<array{name:string, type:string}>}}>
 */
function vetoProbeKnowledge(): array
{
    $path = base_path('tests/Fixtures/importance/must-keep.json');
    $corpus = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

    return $corpus['veto_probes']['must_not_be_vetoed'];
}

/**
 * Unambiguous agent chatter: the case `agent_operation_only` exists for. Pins the
 * rule from the other direction, so a precision fix cannot silently delete it.
 *
 * @return list<array{id:string, why:string, candidate:array{title:string, content:string, entities:list<array{name:string, type:string}>}}>
 */
function vetoProbeChatter(): array
{
    $path = base_path('tests/Fixtures/importance/must-keep.json');
    $corpus = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

    return $corpus['veto_probes']['must_be_vetoed'];
}

/**
 * A concrete, decision-bearing baseline that triggers no penalty and no veto.
 */
function baselineContent(): string
{
    return 'We decided that OutboxWriter::append() is the only way to publish a domain event, because publishing inline from the service rolled back a charged order when the broker timed out.';
}

it('hard-vetoes empty content', function () {
    $evaluation = evaluateRules('');

    expect($evaluation->vetoed)->toBeTrue()
        ->and(triggeredRuleIds($evaluation))->toContain('empty_content')
        ->and($evaluation->apply(100))->toBe(0);
});

it('hard-vetoes content that carries no words at all', function () {
    $evaluation = evaluateRules('--- ... !!! ### --- ... !!! ###');

    expect($evaluation->vetoed)->toBeTrue()
        ->and(triggeredRuleIds($evaluation))->toContain('empty_content');
});

it('does not veto short but readable content', function () {
    // Regression for review finding 2: a 19-character normative rule is
    // neither empty nor unreadable. It may still be penalized by
    // insufficient_substance for being thin, but it must never be vetoed
    // (and the public reason for empty_content must never be attached to it).
    $evaluation = evaluateRules('Use UTC everywhere.');

    expect($evaluation->vetoed)->toBeFalse()
        ->and(triggeredRuleIds($evaluation))->not->toContain('empty_content');
});

it('hard-vetoes placeholder-only content', function () {
    $evaluation = evaluateRules('TODO. TBD. N/A. Placeholder.');

    expect($evaluation->vetoed)->toBeTrue()
        ->and(triggeredRuleIds($evaluation))->toContain('placeholder_only');
});

it('does not veto a placeholder word used inside a real statement', function () {
    $evaluation = evaluateRules('The TODO marker in RetryPolicy must be resolved before release, because the retry limit is still hardcoded to 1 and a transient 503 loses the payment webhook.');

    expect($evaluation->vetoed)->toBeFalse();
});

it('hard-vetoes an unanswered question', function () {
    $evaluation = evaluateRules('How should we handle retries on the shipping API? Should the worker back off exponentially?');

    expect($evaluation->vetoed)->toBeTrue()
        ->and(triggeredRuleIds($evaluation))->toContain('unanswered_question');
});

it('does not veto a question that is followed by its answer', function () {
    $evaluation = evaluateRules('Why is the worker limited to a concurrency of 4? Because the shipping API rejects everything above 60 requests per minute with a 10-minute cooldown.');

    expect($evaluation->vetoed)->toBeFalse();
});

it('hard-vetoes an agent-operation message with no knowledge assertion', function () {
    $evaluation = evaluateRules('Let me open the file.');

    expect($evaluation->vetoed)->toBeTrue()
        ->and(triggeredRuleIds($evaluation))->toContain('agent_operation_only');
});

it('does not veto an agent-operation message that carries a knowledge assertion', function () {
    $evaluation = evaluateRules('I have updated RetryPolicy to stop after 3 attempts, because the shipping API applies a 10-minute cooldown after the fourth 429 and the backlog never drains.');

    expect($evaluation->vetoed)->toBeFalse()
        ->and(triggeredRuleIds($evaluation))->not->toContain('agent_operation_only');
});

it('does not veto ordinary technical vocabulary that merely mentions an exit code', function () {
    // Regression for review finding 1: 'exit code' is ordinary technical
    // vocabulary, not agent-operation narration. This sentence is real,
    // durable knowledge and must never be hard-vetoed.
    $evaluation = evaluateRules('A non-zero exit code from the embedder means the model file is missing.');

    expect($evaluation->vetoed)->toBeFalse()
        ->and(triggeredRuleIds($evaluation))->not->toContain('agent_operation_only');
});

it('still hard-vetoes genuine agent-operation narration about tests and tool calls', function () {
    $evaluation = evaluateRules('I ran the tests. All tests pass. Done.');

    expect($evaluation->vetoed)->toBeTrue()
        ->and(triggeredRuleIds($evaluation))->toContain('agent_operation_only');
});

it('does not veto a first-person clause with anything at all after its object', function (string $content) {
    // The round-5 inversion, and the test that ends four rounds of the same
    // losing game.
    //
    // Rounds 1-4 defined shape-(a) narration by enumerating what BREAKS it: a
    // marker list, then a progress-tail reader, then a class of clause-opening
    // separators (',' ';' ':' en-dash, em-dash) plus a head-noun anchor. Each
    // round, an adversarial reviewer found real facts hard-vetoed through the
    // separator that had NOT been enumerated -- '(', '[', a quote, the ASCII
    // '--', '/', a newline -- and the head-noun anchor additionally failed
    // whenever the true head noun ended the sentence ('I updated the log
    // format.'). A blacklist of the ways a sentence can carry a fact cannot be
    // completed. The blacklist WAS the bug.
    //
    // v5 inverts the predicate: shape (a) is a WHITELIST -- a first-person
    // operational opener, a tooling-artefact object, terminal punctuation, END.
    // Any residual content whatsoever means the sentence is not a bare
    // operational clause and it escapes to the judge. Nothing is enumerated, so
    // there is nothing left to under-list. Every case below escapes for the same
    // single reason.
    $evaluation = evaluateRules($content);

    expect($evaluation->vetoed)->toBeFalse(
        'False-vetoed real knowledge by: '.implode(', ', triggeredRuleIds($evaluation)),
    );
})->with([
    // The nine confirmed round-4 false vetoes, verbatim.
    'parenthetical tail' => 'I checked the logs (the worker restarts on every deploy).',
    'parenthetical tail, migration' => 'I read the diff (the migration runs concurrently).',
    'bracket tail' => 'I read the config file [the parser lowercases every key].',
    'ASCII double-dash tail' => 'I checked the logs -- the worker restarts on every deploy.',
    'pt: parenthetical tail' => 'Verifiquei os logs (o worker reinicia a cada deploy).',
    'pt: parenthetical tail, parser' => 'Li o ficheiro (o parser normaliza cada chave).',
    // The sentence-final head-noun bug: the '.' inside the v4 anchor's trailing
    // exclusion class also matched the sentence-final period, so the "another
    // noun follows" test could never fire and the anchor wrongly held. v5 has no
    // anchor: 'format' is simply residual content after the object 'the log'.
    'sentence-final head noun "format"' => 'I updated the log format.',
    'sentence-final head noun "algorithm"' => 'I updated the diff algorithm.',
    'sentence-final head noun "permissions"' => 'I checked the file permissions.',
    'sentence-final head noun "hunks"' => 'I read the diff hunks.',
    // Fresh separators nobody enumerated. Under v5 nobody has to.
    'quoted tail' => 'I read the output "the parser lowercases every key".',
    'slash tail' => 'I checked the logs / the worker restarts on every deploy.',
    'newline tail inside one sentence' => "I checked the logs\nthe worker restarts on every deploy.",
    'no terminal punctuation at all' => 'I checked the logs and the worker restarts on every deploy',
    'code path after the object' => 'I opened the file app/Services/Foo.php and the importer reads it at boot.',
    'pt: bracket tail' => 'Li o diff [a migração corre concorrente].',
    'pt: ASCII double-dash tail' => 'Corri os testes -- a base sqlite corrompe em paralelo.',
    'pt: newline tail' => "Corri os testes\no seeder popula o tenant de demo.",
    'pt: quoted tail' => 'Li o output "o parser normaliza cada chave".',
    'pt: sentence-final head noun "formato"' => 'Atualizei o formato dos logs.',
    'pt: sentence-final head noun "permissões"' => 'Verifiquei as permissões do ficheiro.',
]);

it('does not veto a first-person clause that opens a further finite clause', function (string $content) {
    // Regression for review finding 1 (round 4), kept as-is under the round-5
    // inversion: a coordinated or subordinated clause is just one more kind of
    // residual content after the object, and residual content escapes. Every
    // sentence below was hard-vetoed by v3 unless the tail's verb happened to be
    // in GENERAL_ASSERTION_MARKERS -- which made a hand-tuned word list the only
    // thing between real knowledge and destruction. Nothing was added to any
    // lexicon to make these pass.
    $evaluation = evaluateRules($content);

    expect($evaluation->vetoed)->toBeFalse(
        'False-vetoed real knowledge by: '.implode(', ', triggeredRuleIds($evaluation)),
    );
})->with([
    'and + declarative clause' => 'I read the config file at boot and it is only parsed once.',
    'and + unlisted verb "restarts"' => 'I checked the logs and the worker restarts on every deploy.',
    'and + unlisted verb "runs"' => 'I read the diff and the migration runs concurrently.',
    'and + unlisted verb "populates"' => 'I ran the tests and the seeder populates the demo tenant.',
    'and + unlisted verb "lowercases"' => 'I read the config file and the parser lowercases every key.',
    'semicolon clause' => 'I ran the tests; the seeder populates the demo tenant.',
    'comma-spliced clause' => 'I checked the logs, the worker restarts on every deploy.',
    'colon clause' => 'I read the diff: the migration adds a partial index.',
    'relative clause' => 'I read the diff, which reorders the migration files.',
    'that-complement clause' => 'I opened the file that the importer writes at boot.',
    'when-subordinate clause' => 'I ran the tests when the seeder is disabled.',
    // The PT half must reach exactly as far as the EN half. Each of these was a
    // confirmed v3 false veto whose escape depended on a Portuguese verb that
    // was NOT in the list ('acontece' vs the listed 'ocorre', 'corrompe' vs the
    // listed 'fica'). No PT verb was added: the structure decides.
    'pt: e + unlisted verb "acontece"' => 'Li o ficheiro de configuração e o parsing acontece só no arranque.',
    'pt: colon clause' => 'Reli o ficheiro de rotas: o middleware de auth corre antes do throttle.',
    'pt: e + unlisted verb "reinicia"' => 'Verifiquei os logs e o worker reinicia a cada deploy.',
    'pt: e + unlisted verb "corrompe"' => 'Corri os testes e a base sqlite corrompe em paralelo.',
    'pt: colon + unlisted verb "guarda"' => 'Li o código do importador: cada chunk guarda um hash de conteúdo.',
    'pt: semicolon clause' => 'Corri os testes; a suíte falha sem o seeder.',
    'pt: relative clause' => 'Li o ficheiro de rotas, que o router carrega no arranque.',
    // Accepted, deliberate recall loss. This was the v3 chatter probe
    // 'chatter-i-ran-the-tests'; it is now indistinguishable from the knowledge
    // above it ('I ran the tests and the seeder populates the demo tenant.'), so
    // it escapes to the judge, which scores it low. A missed veto costs one judge
    // call; a false veto destroys knowledge forever.
    'accepted escape: chatter with a coordinated status clause' => 'I ran the tests and all tests pass now, the tool call returned successfully.',
]);

it('does not veto a first-person clause whose object head is not a tooling artefact', function (string $content) {
    // Regression for review finding 2 (round 4): TOOLING_OBJECT closed its
    // keyword alternation on a bare word boundary, so any noun phrase whose
    // FIRST noun was a tooling word satisfied "tooling artefact object" -- and
    // the constraint failed at exactly its stated purpose. 'the file' is a
    // tooling artefact; 'the file naming convention' is a project convention.
    //
    // v4 fixed this with a head-noun anchor whose trailing exclusion class was
    // itself buggy. v5 deletes the anchor entirely: the second noun is residual
    // content after the object, and residual content escapes. No noun-phrase
    // analysis is performed anywhere any more.
    $evaluation = evaluateRules($content);

    expect($evaluation->vetoed)->toBeFalse(
        'False-vetoed real knowledge by: '.implode(', ', triggeredRuleIds($evaluation)),
    );
})->with([
    'head is "convention", not "test suite"' => 'I read the test suite naming convention: one file per aggregate.',
    'head is "convention", not "file"' => 'I updated the file naming convention to kebab-case for migrations.',
    'head is "permissions", not "file"' => 'I checked the file permissions on the socket path, which the worker needs at boot.',
    'head is "credentials", not "test"' => 'I inspected the test database credentials and they come from the CI vault.',
    'head is "format", not "log"' => 'I updated the log format to one JSON object per line.',
    'head is "directory", not "test"' => 'I created the test fixtures directory under tests/Fixtures/importance.',
    'head is "algorithm", not "diff"' => 'I updated the diff algorithm to histogram for large files.',
    // PT: 'logs' sits inside the prepositional phrase 'dos logs' and is not the
    // head at all. Found by adversarial probing -- the PT modifier slots did not
    // exclude 'dos'/'das'/'nos'/'nas', so the keyword was reachable from INSIDE
    // a PP. They do now.
    'pt: head is "formato", not "logs"' => 'Atualizei o formato dos logs para JSON por linha.',
]);

it('does not veto agent vocabulary used inside a declarative statement', function (string $content) {
    // Regression for review finding A (round 2): v1 matched the agent-operation
    // markers as free substrings anywhere in the content, so a phrase that
    // signals narration in one grammatical position also matched in the middle
    // of a declarative sentence about the system. v2 anchored every marker to a
    // first-person subject or to a sentence-initial progress-report shape.
    $evaluation = evaluateRules($content);

    expect($evaluation->vetoed)->toBeFalse(
        'False-vetoed real knowledge by: '.implode(', ', triggeredRuleIds($evaluation)),
    );
})->with([
    'task completed, mid-sentence' => 'Every task completed by the worker writes a row to job_runs.',
    'task completed, passive' => 'A task completed after its deadline is discarded by the scheduler queue.',
    'let us, in its "allowed us" sense' => 'Moving to pgvector let us drop the Pinecone dependency from the stack.',
    'pt progressive verb' => 'O worker de embeddings está a executar em lotes de 32 vetores.',
    'summary opener over durable content' => 'Here is a summary of the retry policy: 3 attempts, then the job is parked.',
    'gerund as the subject of a fact' => 'Running the tests in parallel corrupts the shared sqlite database.',
    'all tests pass as a clause' => 'All tests pass on the release branch only after the seeder has populated the demo tenant.',
    'present-tense "we run" convention' => 'We run model-backed jobs on the classification queue, away from the default queue.',
    'phrasal "I ran into"' => 'I ran into a deadlock when two workers updated the same row.',
]);

it('does not veto a gerund subject, whatever its tail', function (string $content) {
    // Regression for review finding (round 3): a sentence whose subject is a
    // gerund over the agent's own tooling is inherently ambiguous between a
    // progress report and a statement about the system, and v2's progress-tail
    // machinery separated them wrongly: any comma-introduced tail, and any of
    // the adverbs now/again/next/first/then wherever it landed, made the whole
    // sentence a "progress report". v3 deletes the gerund branch outright.
    $evaluation = evaluateRules($content);

    expect($evaluation->vetoed)->toBeFalse(
        'False-vetoed real knowledge by: '.implode(', ', triggeredRuleIds($evaluation)),
    );
})->with([
    'comma tail, CI duration' => 'Running the tests, even with --parallel, takes 12 minutes on CI.',
    'comma tail, chunked read' => 'Reading the file, chunk by chunk, avoids the memory spike on large imports.',
    'comma tail, container limit' => 'Running the tests, in the Docker image, hits the 2 GB tmpfs limit.',
    'colon tail, declarative clause' => 'Checking the file: the audit job compares content hashes, not mtime.',
    'adverb "first" as a predicate' => 'Checking the file first avoids a race with the importer.',
    'adverb "then" as a coordinator' => 'Running the tests then deploying without a rebuild ships stale assets.',
    'adverb "next" as a preposition' => 'Running the tests next to a migration deadlocks the orders table.',
    'pt gerund opener with a tail' => 'A executar os testes em paralelo, o sqlite fica corrompido.',
]);

it('does not veto first-person narration about a system thing', function (string $content) {
    // Regression for review finding (round 3): v2's first-person INSPECTION
    // branch carried no object constraint, so any "I read/inspected/checked X"
    // was narration whatever X was. v3 requires the object to be a tooling
    // artefact (the file, the tests, the diff, the logs...), and the
    // knowledge-assertion safety net covers the case where it genuinely IS one.
    $evaluation = evaluateRules($content);

    expect($evaluation->vetoed)->toBeFalse(
        'False-vetoed real knowledge by: '.implode(', ', triggeredRuleIds($evaluation)),
    );
})->with([
    'system object: the pgvector source' => 'I read the pgvector source and the ivfflat index caps at 2000 dimensions.',
    'system object: the container' => 'I inspected the container and the OOM killer fires at 512 MB.',
    'tooling object, but asserts a fact' => 'We ran the tests against Postgres 16 and the jsonb ordering changed.',
    'tooling object, measured fact' => 'I ran the tests with --parallel and the sqlite database got corrupted.',
    'first-person plural present is the convention voice' => 'We run the tests on every push in CI.',
    'imperative documentation style' => 'Run the migration before deploying, then swap the container.',
]);

it('does not veto knowledge that merely ends with a status line', function (string $content) {
    // Regression for review finding (round 3): v2 vetoed the whole candidate
    // when ANY sentence looked like narration, so a single trailing "Done."
    // destroyed the knowledge above it. The veto now needs EVERY sentence to be
    // narration.
    $evaluation = evaluateRules($content);

    expect($evaluation->vetoed)->toBeFalse(
        'False-vetoed real knowledge by: '.implode(', ', triggeredRuleIds($evaluation)),
    );
})->with([
    'fact then "All tests pass."' => "The retry policy has 3 attempts with a 2s backoff.\nAll tests pass.",
    'fact then "Done."' => "The importer keeps a content hash per chunk.\nDone.",
    'narration then a fact' => 'I ran the tests. The sqlite database is corrupted under --parallel.',
]);

it('hard-vetoes agent narration with no knowledge assertion', function (string $content) {
    // The unambiguous case the veto is FOR, and its exact reach after the round-5
    // inversion: a BARE first-person operational clause over a tooling artefact
    // with nothing after the object, a terse status phrase that is the whole
    // sentence, or content made entirely of those. Pins the rule from the other
    // side, so a precision fix cannot silently turn it into dead code.
    //
    // Everything with a tail is GONE from this list on purpose -- see the report:
    // "I have updated the file app/Services/Foo.php with the new signature.",
    // "I will now run the test suite for the shipping module.", "I'm going to open
    // the config file and check the retry limit." and their PT twins now escape to
    // the judge, which scores them low. A missed veto costs one judge call; a false
    // veto destroys knowledge forever, and every attempt to keep those inside the
    // veto is what produced four rounds of false vetoes.
    $evaluation = evaluateRules($content);

    expect($evaluation->vetoed)->toBeTrue()
        ->and(triggeredRuleIds($evaluation))->toContain('agent_operation_only');
})->with([
    'terse status sentence' => 'All tests pass.',
    'tersest status sentence' => 'Done.',
    'chain of terse status phrases' => 'Ran the tests, everything is green.',
    'subjectless past-tense tool run' => 'Ran the tests.',
    'bare first-person tool run' => 'I ran the tests.',
    'bare first-person tool run, unterminated' => 'I ran the tests',
    'bare let-form over a tooling artefact' => 'Let me open the file.',
    "bare let's-form" => "Let's run the tests.",
    'bare first-person progressive' => "I'm checking the file.",
    'bare first-person mutation' => 'I updated the file.',
    'bare announced intent' => 'I will run the tests.',
    'auxiliary licensing a bare verb' => "I've run the test suite.",
    'first-person-singular bare read' => 'I read the diff.',
    'every sentence is narration' => 'I ran the tests. All tests pass. Done.',
    'bulleted status lines' => "- Ran the tests.\n- All tests pass.",
    'pt first-person past' => 'Executei os testes.',
    'pt bare first-person past' => 'Corri os testes.',
    'pt bare progressive' => 'Estou a ler o ficheiro.',
    'pt bare announced intent' => 'Vou executar os testes.',
    'pt bare let-form' => 'Deixa-me ler o ficheiro.',
]);

it('rewards an explicit decision', function () {
    $evaluation = evaluateRules('We decided to serve reporting endpoints from the report_daily_totals projection instead of the write model, since one dashboard request issued 400 queries.');

    expect(triggeredRuleIds($evaluation))->toContain('explicit_decision')
        ->and($evaluation->adjustment)->toBeGreaterThan(0);
});

it('rewards a normative restriction', function () {
    $evaluation = evaluateRules('Production migrations must set lock_timeout to 3s before altering the orders table; never raise the timeout to make a migration succeed.');

    expect(triggeredRuleIds($evaluation))->toContain('normative_restriction');
});

it('rewards a causal rationale', function () {
    $evaluation = evaluateRules('Workers start with --memory=256 because the container limit is 512Mi and an OOM-killed worker drops the job it had reserved.');

    expect(triggeredRuleIds($evaluation))->toContain('causal_rationale');
});

it('rewards an actionable consequence', function () {
    $evaluation = evaluateRules('Comparing the sessions.uuid column against a text parameter forces a cast, which breaks the btree index and turns a 3ms lookup into a 900ms sequential scan.');

    expect(triggeredRuleIds($evaluation))->toContain('actionable_consequence');
});

it('applies the exact named adjustment of every positive signal', function () {
    $evaluation = evaluateRules('We decided that the InvoiceNumberAllocator must allocate numbers under a row lock, because max(id) + 1 races under concurrency and results in a duplicate invoice number.');

    $adjustments = [];
    foreach ($evaluation->triggeredRules as $rule) {
        $adjustments[$rule['id']] = $rule['adjustment'];
    }

    expect($adjustments)->toBe([
        'explicit_decision' => 6,
        'normative_restriction' => 6,
        'causal_rationale' => 5,
        'actionable_consequence' => 5,
    ])
        ->and($evaluation->adjustment)->toBe(22);
});

it('penalizes speculative language', function () {
    $evaluation = evaluateRules('The checkout latency spike on the orders table might be caused by the new index, but I am not sure; maybe the vacuum schedule is the real reason.');

    expect(triggeredRuleIds($evaluation))->toContain('speculative_language')
        ->and($evaluation->adjustment)->toBeLessThan(0);
});

it('penalizes generic wording with no concrete context', function () {
    $evaluation = evaluateRules('The team should always write good code and keep the system clean, because quality matters and users deserve a reliable product every single day.');

    expect(triggeredRuleIds($evaluation))->toContain('generic_without_context');
});

it('does not penalize concrete wording as generic', function () {
    $evaluation = evaluateRules(baselineContent());

    expect(triggeredRuleIds($evaluation))->not->toContain('generic_without_context');
});

it('penalizes a clearly transient status report', function () {
    $evaluation = evaluateRules('The nightly DailyTotalsJob is temporarily disabled for now while we investigate the duplicate rows; we will fix it later this week.');

    expect(triggeredRuleIds($evaluation))->toContain('transient_status');
});

it('penalizes insufficient substance', function () {
    $evaluation = evaluateRules('The orders table must be vacuumed.');

    expect(triggeredRuleIds($evaluation))->toContain('insufficient_substance')
        ->and($evaluation->vetoed)->toBeFalse();
});

it('applies the exact named adjustment of every penalty', function () {
    $evaluation = evaluateRules('Maybe things are broken for now.');

    $adjustments = [];
    foreach ($evaluation->triggeredRules as $rule) {
        $adjustments[$rule['id']] = $rule['adjustment'];
    }

    expect($adjustments)->toBe([
        'speculative_language' => -8,
        'generic_without_context' => -8,
        'transient_status' => -12,
        'insufficient_substance' => -10,
    ]);
});

it('clamps the final score to 0..100', function () {
    $rewarded = evaluateRules(baselineContent());
    $penalized = evaluateRules('Maybe things are broken for now.');

    // baselineContent() triggers exactly explicit_decision (+6) and
    // causal_rationale (+5): 11. 'Maybe things are broken for now.' triggers
    // exactly the four penalties asserted above: -8 -8 -12 -10 = -38.
    expect($rewarded->adjustment)->toBe(11)
        ->and($rewarded->apply(100))->toBe(100)
        ->and($rewarded->apply(60))->toBe(71)
        ->and($penalized->adjustment)->toBe(-38)
        ->and($penalized->apply(0))->toBe(0)
        ->and($penalized->apply(10))->toBe(0)
        ->and($penalized->apply(80))->toBe(42);
});

it('forces a vetoed candidate to zero whatever the semantic score is', function () {
    $evaluation = evaluateRules('');

    expect($evaluation->apply(100))->toBe(0)
        ->and($evaluation->apply(0))->toBe(0)
        ->and($evaluation->adjustment)->toBe(DeterministicImportanceRules::VETO_ADJUSTMENT);
});

it('stamps the rules version on every evaluation', function () {
    expect(evaluateRules(baselineContent())->rulesVersion)->toBe(DeterministicImportanceRules::VERSION)
        ->and((new DeterministicImportanceRules('v9'))->evaluate(ruleCandidate(baselineContent()))->rulesVersion)->toBe('v9');
});

it('exposes stable identifiers and concise public reasons for every triggered rule', function () {
    $evaluations = [
        evaluateRules(''),
        evaluateRules('TODO. TBD. N/A. Placeholder.'),
        evaluateRules('How should we handle retries on the shipping API? Should the worker back off?'),
        evaluateRules('Let me open the file.'),
        evaluateRules(baselineContent()),
        evaluateRules('Maybe things are broken for now.'),
    ];

    foreach ($evaluations as $evaluation) {
        expect($evaluation->triggeredRules)->not->toBeEmpty();

        foreach ($evaluation->triggeredRules as $rule) {
            expect(array_keys($rule))->toBe(['id', 'adjustment', 'reason'])
                ->and($rule['id'])->toMatch('/^[a-z][a-z_]+[a-z]$/')
                ->and($rule['adjustment'])->toBeInt()
                ->and($rule['reason'])->not->toBeEmpty()
                ->and(mb_strlen($rule['reason']))->toBeLessThanOrEqual(120);
        }
    }
});

it('never hard-vetoes a reviewed must-keep candidate', function () {
    $rules = new DeterministicImportanceRules;

    foreach (mustKeepFixtures() as $fixture) {
        $evaluation = $rules->evaluate(ruleCandidate(
            content: $fixture['candidate']['content'],
            title: $fixture['candidate']['title'],
            entities: $fixture['candidate']['entities'],
        ));

        expect($evaluation->vetoed)->toBeFalse(
            "Must-keep fixture [{$fixture['id']}] was hard-vetoed by: ".implode(', ', triggeredRuleIds($evaluation)),
        );
    }
});

it('recognizes a knowledge signal in every reviewed must-keep candidate', function () {
    $rules = new DeterministicImportanceRules;
    $positiveRuleIds = ['explicit_decision', 'normative_restriction', 'causal_rationale', 'actionable_consequence'];

    foreach (mustKeepFixtures() as $fixture) {
        $evaluation = $rules->evaluate(ruleCandidate(
            content: $fixture['candidate']['content'],
            title: $fixture['candidate']['title'],
            entities: $fixture['candidate']['entities'],
        ));

        expect(array_intersect(triggeredRuleIds($evaluation), $positiveRuleIds))->not->toBeEmpty(
            "Must-keep fixture [{$fixture['id']}] triggered no positive signal.",
        );
    }
});

it('never hard-vetoes signal-free knowledge that uses agent vocabulary', function () {
    // The corpus invariant above ("every must-keep fixture triggers a positive
    // signal") plus the veto predicate ("marker AND NOT knowledge assertion")
    // means no `fixtures` entry can ever exercise agent_operation_only. This
    // block closes that blind spot: real knowledge, no positive signal, and the
    // exact vocabulary the veto keys on. A failure here is a bug in the RULE --
    // never bolt a positive phrase onto a fixture to make it pass.
    $rules = new DeterministicImportanceRules;
    $positiveRuleIds = ['explicit_decision', 'normative_restriction', 'causal_rationale', 'actionable_consequence'];

    foreach (vetoProbeKnowledge() as $probe) {
        $evaluation = $rules->evaluate(ruleCandidate(
            content: $probe['candidate']['content'],
            title: $probe['candidate']['title'],
            entities: $probe['candidate']['entities'],
        ));

        expect($evaluation->vetoed)->toBeFalse(
            "Veto probe [{$probe['id']}] was hard-vetoed by: ".implode(', ', triggeredRuleIds($evaluation)),
        )->and(array_intersect(triggeredRuleIds($evaluation), $positiveRuleIds))->toBeEmpty(
            "Veto probe [{$probe['id']}] triggered a positive signal, so it no longer proves the veto is anchored correctly.",
        );
    }
});

it('hard-vetoes every unambiguous agent-chatter probe', function () {
    $rules = new DeterministicImportanceRules;

    foreach (vetoProbeChatter() as $probe) {
        $evaluation = $rules->evaluate(ruleCandidate(
            content: $probe['candidate']['content'],
            title: $probe['candidate']['title'],
            entities: $probe['candidate']['entities'],
        ));

        expect($evaluation->vetoed)->toBeTrue("Chatter probe [{$probe['id']}] escaped the veto.")
            ->and(in_array('agent_operation_only', triggeredRuleIds($evaluation), true))->toBeTrue(
                "Chatter probe [{$probe['id']}] was vetoed by another rule, not by agent_operation_only.",
            );
    }
});

it('does not depend on the knowledge-assertion lexicon for its precision', function () {
    // The structural invariant, and the one that keeps rounds 1-4 from repeating.
    //
    // Before v4 the assertion lexicon was the LAST line of defence: a first-person
    // sentence that reported a fact was structurally "narration", and only a verb
    // that happened to be listed in GENERAL_ASSERTION_MARKERS saved it. That made
    // the veto's precision hostage to a hand-tuned English word list -- and the
    // Portuguese half of that list was materially thinner, so PT knowledge was
    // systematically more exposed than the identical EN sentence.
    //
    // The v5 whitelist makes the escape purely structural, which demotes the
    // lexicon to a genuine SECOND net. This test asserts exactly that: the
    // narration GRAMMAR alone, with hasKnowledgeAssertion() never consulted,
    // already rejects every probe that must not be vetoed AND every reviewed
    // must-keep fixture. If this fails, someone has re-introduced a structural
    // hole and is relying on the word list to hide it -- fix the grammar, never
    // the list.
    $rules = new DeterministicImportanceRules;

    $isNarration = new ReflectionMethod($rules, 'isAgentOperationNarration');
    $haystack = new ReflectionMethod($rules, 'haystack');

    foreach (vetoProbeKnowledge() as $probe) {
        $content = $haystack->invoke($rules, $probe['candidate']['content']);

        expect($isNarration->invoke($rules, $content))->toBeFalse(
            "Veto probe [{$probe['id']}] is structurally agent narration, so only the knowledge-assertion lexicon keeps it. The GRAMMAR must reject it -- do not widen the lexicon.",
        );
    }

    foreach (mustKeepFixtures() as $fixture) {
        $content = $haystack->invoke($rules, $fixture['candidate']['content']);

        expect($isNarration->invoke($rules, $content))->toBeFalse(
            "Must-keep fixture [{$fixture['id']}] is structurally agent narration, so only the knowledge-assertion lexicon keeps it. The GRAMMAR must reject it -- do not widen the lexicon.",
        );
    }
});

it('pins both directions of the agent-operation veto with enough probes', function () {
    expect(count(vetoProbeKnowledge()))->toBeGreaterThanOrEqual(80)
        ->and(count(vetoProbeChatter()))->toBeGreaterThanOrEqual(24);

    foreach ([...vetoProbeKnowledge(), ...vetoProbeChatter()] as $probe) {
        expect(trim($probe['id']))->not->toBeEmpty()
            ->and(trim($probe['why']))->not->toBeEmpty()
            ->and(trim($probe['candidate']['content']))->not->toBeEmpty();
    }
});

it('covers every knowledge kind the corpus must protect', function () {
    $fixtures = mustKeepFixtures();

    expect(count($fixtures))->toBeGreaterThanOrEqual(20)
        ->and(array_values(array_unique(array_map(static fn (array $fixture): string => $fixture['kind'], $fixtures))))
        ->toEqualCanonicalizing([
            'architectural-decision',
            'business-rule',
            'operational-constraint',
            'convention',
            'non-obvious-fix',
            'decision-with-rationale',
        ]);

    foreach ($fixtures as $fixture) {
        expect(trim($fixture['retention_reason']))->not->toBeEmpty()
            ->and(trim($fixture['candidate']['content']))->not->toBeEmpty()
            ->and(trim($fixture['candidate']['title']))->not->toBeEmpty();
    }
});
