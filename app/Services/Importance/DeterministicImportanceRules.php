<?php

namespace App\Services\Importance;

/**
 * Version one of the deterministic importance rules.
 *
 * The rules are intentionally small, independent, and explainable. They never
 * try to be a second judge: the semantic score comes from the model, and these
 * rules only nudge it (positive signals, penalties) or refuse it outright (hard
 * vetoes) when the candidate is unambiguous noise.
 *
 * Every adjustment is a named constant on this class, and every triggered rule
 * carries a stable id plus a concise public reason, so a stored assessment can
 * always be explained to a human without re-running anything.
 *
 * Duplication is deliberately NOT a rule here: semantic dedup belongs to the
 * condensation pipeline (`CondenseDedup`), not to importance.
 *
 * Changing any threshold, marker list, or adjustment below is a behavioural
 * change and MUST come with a new `VERSION`, because the version is part of the
 * assessment cache identity and historical assessments must stay attributable.
 */
final class DeterministicImportanceRules
{
    public const string VERSION = 'v2';

    /**
     * A hard veto forces the final score to zero. It is exactly the negative of
     * the maximum semantic score (25 + 20 + 20 + 20 + 15), so even a perfect
     * semantic judgement clamps to zero.
     */
    public const int VETO_ADJUSTMENT = -100;

    public const int EXPLICIT_DECISION_ADJUSTMENT = 6;

    public const int NORMATIVE_RESTRICTION_ADJUSTMENT = 6;

    public const int CAUSAL_RATIONALE_ADJUSTMENT = 5;

    public const int ACTIONABLE_CONSEQUENCE_ADJUSTMENT = 5;

    public const int SPECULATIVE_LANGUAGE_ADJUSTMENT = -8;

    public const int GENERIC_WITHOUT_CONTEXT_ADJUSTMENT = -8;

    public const int TRANSIENT_STATUS_ADJUSTMENT = -12;

    public const int INSUFFICIENT_SUBSTANCE_ADJUSTMENT = -10;

    /** Below this word count the content is too thin to be useful later. */
    public const int MIN_SUBSTANCE_WORDS = 12;

    /** @var list<string> */
    private const array EXPLICIT_DECISION_MARKERS = [
        'we decided', 'we have decided', 'we chose', 'we choose', 'we picked', 'we adopted',
        'we standardized on', 'we settled on', 'we agreed', 'we will use', 'we centralized',
        'decision', 'decided to', 'chose to', 'opted for', 'rejected in favor of',
        'decidimos', 'a decisão', 'ficou decidido', 'optámos', 'optamos', 'escolhemos',
        'adotámos', 'adotamos', 'vamos usar',
    ];

    /** @var list<string> */
    private const array NORMATIVE_RESTRICTION_MARKERS = [
        'must', 'must not', 'never', 'always', 'forbidden', 'prohibited', 'not allowed',
        'do not', "don't", 'should not', 'shall', 'mandatory', 'is required', 'are required',
        'the rule is', 'is a review failure',
        'deve', 'não deve', 'nunca', 'sempre', 'proibido', 'obrigatório', 'não pode',
        'é preciso',
    ];

    /** @var list<string> */
    private const array CAUSAL_RATIONALE_MARKERS = [
        'because', 'since', 'due to', 'caused by', 'the reason', 'that is why', 'which is why',
        'so that', 'in order to', 'after a', 'this is how',
        'porque', 'devido a', 'a razão', 'uma vez que', 'já que', 'pois',
    ];

    /** @var list<string> */
    private const array ACTIONABLE_CONSEQUENCE_MARKERS = [
        'otherwise', 'will fail', 'will break', 'breaks', 'causes', 'leads to', 'results in',
        'produces', 'prevents', 'instead of', 'rather than', 'prefer', 'avoid', 'ensure',
        'make sure', 'requires', 'is lost', 'is erased',
        'caso contrário', 'senão', 'em vez de', 'evite', 'evitar', 'prefira', 'garanta',
        'provoca', 'resulta em', 'quebra',
    ];

    /** @var list<string> */
    private const array SPECULATIVE_LANGUAGE_MARKERS = [
        'maybe', 'perhaps', 'might', 'may be', 'possibly', 'probably', 'presumably', 'likely',
        'i think', 'i believe', 'i guess', 'not sure', 'unclear', 'seems', 'appears to',
        'could be', 'we could probably',
        'talvez', 'provavelmente', 'possivelmente', 'parece', 'acho que', 'se calhar',
    ];

    /** @var list<string> */
    private const array TRANSIENT_STATUS_MARKERS = [
        'for now', 'right now', 'at the moment', 'for the moment', 'temporarily', 'temporary',
        'work in progress', 'wip', 'will fix later', 'fix later', 'later this week',
        'currently broken', 'currently failing', 'currently disabled', 'today', 'yesterday',
        'this session', 'so far',
        'por agora', 'por enquanto', 'temporariamente', 'provisório', 'provisoriamente',
        'de momento', 'em curso',
    ];

    /**
     * The `agent_operation_only` veto is precision-first, because it is a HARD
     * veto: it forces `not_important` whatever the threshold, the semantic judge
     * is never consulted, and in `enforce` mode the entry is silently rejected.
     * A false veto destroys real knowledge; a missed veto only costs one judge
     * call on text the judge will score low anyway. The asymmetry is the whole
     * design constraint, so ambiguous text is deliberately allowed to escape.
     *
     * v1 matched a flat list of markers as free substrings ANYWHERE in the
     * content, which false-vetoed ordinary knowledge that happened to contain
     * the same words in a different grammatical position ("Every task completed
     * by the worker writes a row to job_runs.", "Moving to pgvector let us drop
     * the Pinecone dependency."). v2 therefore anchors every marker to the
     * grammatical position that actually signals operation narration:
     *
     *  - FIRST_PERSON_OPERATION_PATTERN — an explicit `I`/`we` subject in front
     *    of a tooling verb ("I ran…", "I'm reading…", "I have updated the
     *    file…"). First person is the strongest, safest signal: durable project
     *    knowledge is not written in the first person. Present-tense forms ("we
     *    run model jobs on the classification queue") are excluded on purpose —
     *    that is the canonical voice of a convention, not of narration.
     *  - AGENT_NARRATION_OPENERS — sentence-INITIAL only ("Let me…", "Ran the
     *    tests…"). Mid-sentence occurrences ("…let us drop…") never match.
     *  - AGENT_PROGRESS_OPENERS — sentence-initial gerund + the agent's own
     *    tooling as object, and only when the rest of the sentence is a progress
     *    tail (see PROGRESS_TAIL_PATTERN). "Running the tests now, 3 failed."
     *    matches; "Running the test suite twice leaks 400 MB." does not.
     *  - AGENT_STATUS_SENTENCES — terse status reports that are the WHOLE
     *    sentence ("All tests pass.", "Done."). "…unless all tests pass on the
     *    main pipeline" never matches.
     *
     * `hasKnowledgeAssertion()` is the second safety net on top of all of them.
     *
     * @var list<string>
     */
    private const array AGENT_FIRST_PERSON_MARKERS = [
        // Portuguese first-person narration (the English forms are covered by
        // FIRST_PERSON_OPERATION_PATTERN). Every entry carries an explicit
        // first-person subject or inflection, so it cannot appear inside a
        // declarative statement about the system. The v1 marker 'a executar' is
        // gone: it matched any progressive verb ("O worker está a executar em
        // lotes de 32 vetores.").
        'vou executar', 'vou correr', 'vou rodar', 'vou ler', 'vou criar', 'vou atualizar',
        'vou escrever', 'vou verificar', 'vou abrir', 'vou procurar',
        'estou a executar', 'estou a correr', 'estou a ler', 'estou a escrever',
        'estou a criar', 'estou a atualizar', 'estou a verificar',
        'estou executando', 'estou lendo', 'estou rodando', 'estou escrevendo',
        'estou criando', 'estou atualizando',
        'acabei de executar', 'acabei de correr', 'acabei de rodar', 'acabei de ler',
        'acabei de atualizar', 'acabei de criar',
        'executei os testes', 'corri os testes', 'rodei os testes',
        'li o ficheiro', 'li o arquivo', 'atualizei o ficheiro', 'criei o ficheiro',
    ];

    /**
     * Sentence-initial narration. Any tail is allowed: nothing that opens a
     * sentence with these words is a statement about the project.
     *
     * @var list<string>
     */
    private const array AGENT_NARRATION_OPENERS = [
        'ran the tests', 'ran the test suite', 'ran the suite', 'ran pest', 'ran phpstan',
        'ran the linter', 'ran the command', 're-ran the tests', 'reran the tests',
        'deixa-me', 'deixe-me', 'a executar os testes', 'a correr os testes', 'a rodar os testes',
    ];

    /**
     * "Let me run…", "Let's read…": a sentence-initial let-form followed by an
     * operational verb. The verb is required: "Let's Encrypt certificates renew
     * 30 days before expiry." is knowledge about a CA, and a bare `let's` opener
     * false-vetoed it.
     */
    private const string LET_NARRATION_PATTERN = '/^let(?:\s+me|\s+us|\'s)\s+
        (?:(?:now|first|then|just|quickly|also)\s+)*
        (?:re-?)?
        (?:run|read|check|write|create|update|open|look|see|inspect|search|grep|fix|start
           |add|try|verify|examine|apply|build|test|edit|patch|delete|remove|list|explore|review|dig)
        (?![\p{L}\p{N}])
    /xu';

    /**
     * Sentence-initial gerund progress reports over the agent's own tooling.
     * Only fire when the rest of the sentence is a progress tail, so that a
     * gerund used as the SUBJECT of a declarative sentence ("Running the tests
     * in parallel corrupts the shared sqlite database.") is never vetoed.
     *
     * @var list<string>
     */
    private const array AGENT_PROGRESS_OPENERS = [
        'running the tests', 'running the test suite', 'running the suite', 'running tests',
        'running the command', 'running the linter', 'running pest', 'running phpstan', 'running pint',
        'reading the file', 'reading file', 'reading the config file', 'reading the source file',
        'writing the file', 'writing file', 'creating the file', 'updating the file',
        'editing the file', 'opening the file', 'checking the file', 'checking the tests',
        'inspecting the file', 'listing the files', 'searching the codebase', 'grepping for',
        'a ler o ficheiro', 'a ler o arquivo', 'a escrever o ficheiro', 'a escrever o arquivo',
        'a criar o ficheiro', 'a atualizar o ficheiro',
    ];

    /**
     * Terse status reports. They must be the WHOLE sentence (an optional "now"
     * or "again" plus punctuation aside), which is what separates "All tests
     * pass." from "Branch protection blocks merges unless all tests pass."
     *
     * @var list<string>
     */
    private const array AGENT_STATUS_SENTENCES = [
        'all tests pass', 'all tests passed', 'all tests now pass', 'all tests are green',
        'tests pass', 'tests passed', 'tests are green', 'everything is green',
        'the build is green', 'build is green', 'done', 'all done', 'task completed',
        'task complete', 'no errors', 'no issues found', 'it works', 'fixed it',
        'todos os testes passam', 'todos os testes passaram', 'testes todos verdes',
        'tarefa concluída', 'tarefa concluida', 'concluído', 'concluido', 'feito',
        'tudo verde', 'sem erros',
    ];

    /**
     * An explicit first-person subject in front of a tooling verb. See the
     * AGENT_FIRST_PERSON_MARKERS docblock for the design; the branches are:
     *   (a) I + past/progressive execution or inspection verb ("I ran", "I'm reading");
     *   (b) I/we + past/progressive mutation verb, but ONLY of a tooling artefact
     *       ("I have updated the file"). "I added a unique index on chunks.hash"
     *       is a fact about the system and deliberately escapes;
     *   (c) I have run / I've run;
     *   (d) we ran/are running the tests — the only "we" narration that is
     *       unambiguous, because "we run/we write/we create <system thing>" is
     *       how conventions are written;
     *   (e) announced intent ("I'm going to…", "I will now…", "I'll run…").
     */
    private const string FIRST_PERSON_OPERATION_PATTERN = '/
        (?<![\p{L}\p{N}])
        (?:
            i(?:\'(?:m|ve|ll))?
            (?:\s+(?:am|was|have|had|will|just|already|now|then|also|finally|need|to))*
            \s+
            (?:
                (?:ran|re-?ran)(?!\s+into)
                |running
                |read|re-?read|reading
                |check(?:ed|ing)|inspect(?:ed|ing)|execut(?:ed|ing)
                |open(?:ed|ing)|search(?:ed|ing)|grepp(?:ed|ing)|list(?:ed|ing)
            )
            |
            (?:i|we)(?:\'(?:m|ve|ll|re))?
            (?:\s+(?:am|are|have|had|will|just|already|now|then|also))*
            \s+
            (?:updated|updating|created|creating|wrote|written|writing|added|adding
               |fixed|fixing|edited|editing|patched|patching|deleted|removed|renamed)
            \s+
            (?:(?:the|a|an|this|that|these|those|our|my)\s+)?
            (?:files?|tests?|test\s+suite|diff|patch|stub|snippet)
            |
            i(?:\'ve|\s+have)(?:\s+(?:just|already|now))?\s+run
            |
            we(?:\'(?:ve|re))?(?:\s+(?:just|already|have|are|will))*\s+(?:ran|run|running)
            \s+(?:the\s+)?(?:tests?|test\s+suite|suite)
            |
            i(?:\'m)?(?:\s+am)?\s+(?:going\s+to|about\s+to)
            |
            i(?:\'ll|\s+will)\s+(?:now|run|read|update|create|write|add|fix|check|open|edit|patch|execute|inspect)
        )
        (?![\p{L}\p{N}])
    /xu';

    /**
     * What may follow a sentence-initial progress opener for it to still be a
     * progress report: nothing, an ellipsis, a result clause introduced by a
     * comma, a progress adverb ("now", "again"), or a single path/identifier
     * token ("Reading the file config/rag.php…"). Anything else means the
     * gerund phrase is the subject of a declarative sentence, i.e. knowledge.
     */
    private const string PROGRESS_TAIL_PATTERN = '/^\s*(?:
        [.!\x{2026}]*\s*
        |\.{3}.*
        |[\x{2026}].*
        |[,;:].*
        |(?:now|again|next|first|then)(?![\p{L}\p{N}]).*
        |[`"\']?[\p{L}\p{N}][\w.\/\\\\@:-]*[`"\']?\s*[.!\x{2026}]*\s*
    )$/xu';

    /**
     * General assertion verbs. They are NOT a positive signal (they do not
     * change the score); they only widen the `hasKnowledgeAssertion()` escape
     * hatch, which can only ever WEAKEN a veto — the safe direction under a
     * precision-first veto.
     *
     * @var list<string>
     */
    private const array GENERAL_ASSERTION_MARKERS = [
        'means', 'indicates', 'implies', 'denotes', 'stands for', 'refers to',
        'returns', 'yields', 'guarantees', 'enforces', 'defaults to', 'applies to',
        'depends on', 'is defined as', 'says', 'states', 'specifies', 'documents',
        'defines', 'expects', 'accepts', 'rejects', 'blocks', 'allows', 'disables',
        'enables', 'limits', 'corrupts', 'invalidates', 'overwrites',
        'significa', 'indica', 'implica', 'retorna', 'devolve', 'garante', 'define',
        'exige', 'depende de', 'corresponde a', 'bloqueia', 'impede', 'permite',
        'rejeita', 'especifica', 'diz',
    ];

    /**
     * A copula only counts as a knowledge assertion when the content also has a
     * concrete anchor: "The retry limit is 3" asserts something about the
     * project, "everything is green" does not.
     *
     * @var list<string>
     */
    private const array COPULA_ASSERTION_MARKERS = [
        'is', 'are', 'was', 'were',
        'é', 'são', 'está', 'estão', 'era', 'eram',
    ];

    /** Content made only of placeholder tokens carries no knowledge at all. */
    private const string PLACEHOLDER_PATTERN = '/^(?:[\p{P}\p{S}\s]*(?:tbd|tbc|to\s?do|n\/?a|none|null|undefined|placeholder|lorem\s+ipsum|x{3,}|fixme|wip|nothing\s+to\s+add|no\s+notes|a\s+definir|pendente|em\s+branco|sem\s+(?:informa[çc][ãa]o|conte[úu]do|notas))[\p{P}\p{S}\s]*)+$/iu';

    /** A concrete anchor: code identifier, path, number, acronym, or dotted token. */
    private const string CONCRETE_ANCHOR_PATTERN = '/`|[\/\\\\][\w.-]+|\b\d+\b|\b[A-Z]{2,}\b|\b\w+_\w+\b|\b[a-z]+[A-Z]\w*\b|\b[A-Z][a-z0-9]+[A-Z]\w*\b|\b\w+\.\w{2,}\b/u';

    public function __construct(private readonly string $rulesVersion = self::VERSION) {}

    public function evaluate(NormalizedImportanceCandidate $candidate): RuleEvaluation
    {
        $data = $candidate->data();
        $text = trim($data['title']."\n".$data['content']);
        $haystack = $this->haystack($text);

        $vetoes = $this->vetoes($data['content'], $text, $haystack, $data['entities']);

        if ($vetoes !== []) {
            return new RuleEvaluation(
                vetoed: true,
                adjustment: self::VETO_ADJUSTMENT,
                triggeredRules: $vetoes,
                rulesVersion: $this->rulesVersion,
            );
        }

        $triggered = [
            ...$this->positiveSignals($haystack),
            ...$this->penalties($data['content'], $text, $haystack, $data['entities']),
        ];

        $adjustment = array_sum(array_column($triggered, 'adjustment'));

        return new RuleEvaluation(
            vetoed: false,
            adjustment: $adjustment,
            triggeredRules: $triggered,
            rulesVersion: $this->rulesVersion,
        );
    }

    /**
     * @param  list<array{name:string, type:string}>  $entities
     * @return list<array{id:string, adjustment:int, reason:string}>
     */
    private function vetoes(string $content, string $text, string $haystack, array $entities): array
    {
        $vetoes = [];

        if ($this->isEmptyOrUnreadable($content)) {
            $vetoes[] = $this->rule('empty_content', self::VETO_ADJUSTMENT, 'Content is empty or carries no readable statement.');
        }

        if (preg_match(self::PLACEHOLDER_PATTERN, $content) === 1) {
            $vetoes[] = $this->rule('placeholder_only', self::VETO_ADJUSTMENT, 'Content is a placeholder and states no knowledge.');
        }

        if ($this->isOnlyQuestions($content)) {
            $vetoes[] = $this->rule('unanswered_question', self::VETO_ADJUSTMENT, 'Content only asks questions and answers none.');
        }

        if ($this->isAgentOperationNarration($haystack) && ! $this->hasKnowledgeAssertion($haystack, $text, $entities)) {
            $vetoes[] = $this->rule('agent_operation_only', self::VETO_ADJUSTMENT, 'Content reports an agent operation without asserting knowledge.');
        }

        return $vetoes;
    }

    /**
     * True only for UNAMBIGUOUS agent-operation narration: a first-person
     * operational subject anywhere, or a progress-report shape anchored at the
     * start of a sentence. Ambiguous text escapes to the semantic judge by
     * design — losing recall here is an accepted trade, losing precision is not.
     */
    private function isAgentOperationNarration(string $haystack): bool
    {
        if (preg_match(self::FIRST_PERSON_OPERATION_PATTERN, $haystack) === 1) {
            return true;
        }

        if ($this->matchesAny($haystack, self::AGENT_FIRST_PERSON_MARKERS)) {
            return true;
        }

        foreach ($this->sentences($haystack) as $sentence) {
            if ($this->startsWithAny($sentence, self::AGENT_NARRATION_OPENERS)) {
                return true;
            }

            if (preg_match(self::LET_NARRATION_PATTERN, $sentence) === 1) {
                return true;
            }

            if ($this->isProgressReport($sentence)) {
                return true;
            }

            if ($this->isStatusSentence($sentence)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function sentences(string $haystack): array
    {
        $sentences = preg_split('/(?<=[.!?\x{2026}])\s+|\n+/u', $haystack, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $sentences = array_map(
            // Drop leading list bullets, quotes and markdown so a bulleted
            // progress report is still recognised as sentence-initial.
            static fn (string $sentence): string => (string) preg_replace('/^[\s\p{Pd}*>#`"\'\x{2022}\p{Pi}]+/u', '', $sentence),
            $sentences,
        );

        return array_values(array_filter(
            $sentences,
            static fn (string $sentence): bool => preg_match('/\p{L}/u', $sentence) === 1,
        ));
    }

    /**
     * @param  list<string>  $openers
     */
    private function startsWithAny(string $sentence, array $openers): bool
    {
        foreach ($openers as $opener) {
            if (preg_match('/^'.preg_quote($opener, '/').'(?![\p{L}\p{N}])/u', $sentence) === 1) {
                return true;
            }
        }

        return false;
    }

    private function isProgressReport(string $sentence): bool
    {
        foreach (self::AGENT_PROGRESS_OPENERS as $opener) {
            $pattern = '/^'.preg_quote($opener, '/').'(?![\p{L}\p{N}])(?<tail>.*)$/u';

            if (preg_match($pattern, $sentence, $matches) !== 1) {
                continue;
            }

            if (preg_match(self::PROGRESS_TAIL_PATTERN, $matches['tail']) === 1) {
                return true;
            }
        }

        return false;
    }

    private function isStatusSentence(string $sentence): bool
    {
        foreach (self::AGENT_STATUS_SENTENCES as $status) {
            $pattern = '/^'.preg_quote($status, '/').'(?:\s+(?:now|again|agora|já))?\s*[.!\x{2026}]*$/u';

            if (preg_match($pattern, $sentence) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{id:string, adjustment:int, reason:string}>
     */
    private function positiveSignals(string $haystack): array
    {
        $signals = [];

        if ($this->matchesAny($haystack, self::EXPLICIT_DECISION_MARKERS)) {
            $signals[] = $this->rule('explicit_decision', self::EXPLICIT_DECISION_ADJUSTMENT, 'States an explicit decision.');
        }

        if ($this->matchesAny($haystack, self::NORMATIVE_RESTRICTION_MARKERS)) {
            $signals[] = $this->rule('normative_restriction', self::NORMATIVE_RESTRICTION_ADJUSTMENT, 'States a rule or restriction to follow.');
        }

        if ($this->matchesAny($haystack, self::CAUSAL_RATIONALE_MARKERS)) {
            $signals[] = $this->rule('causal_rationale', self::CAUSAL_RATIONALE_ADJUSTMENT, 'Explains why, not only what.');
        }

        if ($this->matchesAny($haystack, self::ACTIONABLE_CONSEQUENCE_MARKERS)) {
            $signals[] = $this->rule('actionable_consequence', self::ACTIONABLE_CONSEQUENCE_ADJUSTMENT, 'Names a concrete consequence to act on.');
        }

        return $signals;
    }

    /**
     * @param  list<array{name:string, type:string}>  $entities
     * @return list<array{id:string, adjustment:int, reason:string}>
     */
    private function penalties(string $content, string $text, string $haystack, array $entities): array
    {
        $penalties = [];

        if ($this->matchesAny($haystack, self::SPECULATIVE_LANGUAGE_MARKERS)) {
            $penalties[] = $this->rule('speculative_language', self::SPECULATIVE_LANGUAGE_ADJUSTMENT, 'Speculative wording rather than an established fact.');
        }

        if (! $this->hasConcreteAnchor($text, $entities)) {
            $penalties[] = $this->rule('generic_without_context', self::GENERIC_WITHOUT_CONTEXT_ADJUSTMENT, 'Generic wording with no concrete anchor in the project.');
        }

        if ($this->matchesAny($haystack, self::TRANSIENT_STATUS_MARKERS)) {
            $penalties[] = $this->rule('transient_status', self::TRANSIENT_STATUS_ADJUSTMENT, 'Reports a transient status rather than durable knowledge.');
        }

        if ($this->wordCount($content) < self::MIN_SUBSTANCE_WORDS) {
            $penalties[] = $this->rule('insufficient_substance', self::INSUFFICIENT_SUBSTANCE_ADJUSTMENT, 'Too little substance to be useful in a later session.');
        }

        return $penalties;
    }

    /**
     * A veto is reserved for content that is genuinely empty or carries no
     * readable statement at all (no letters). Short-but-readable content (a
     * one-line rule, a terse fact) is real knowledge and is never vetoed on
     * length alone; `insufficient_substance` already penalizes thin content by
     * word count without forcing the final score to zero.
     */
    private function isEmptyOrUnreadable(string $content): bool
    {
        return $content === ''
            || preg_match('/\p{L}/u', $content) !== 1;
    }

    private function isOnlyQuestions(string $content): bool
    {
        $sentences = preg_split('/(?<=[.!?])\s+|\n+/u', $content, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $sentences = array_values(array_filter(
            array_map(trim(...), $sentences),
            static fn (string $sentence): bool => preg_match('/\p{L}/u', $sentence) === 1,
        ));

        if ($sentences === []) {
            return false;
        }

        foreach ($sentences as $sentence) {
            if (! str_ends_with(rtrim($sentence, " \t\"')]"), '?')) {
                return false;
            }
        }

        return true;
    }

    /**
     * The second safety net under the agent-operation veto. A knowledge
     * assertion is any of the four positive signals (a decision, a rule, a
     * reason, a consequence), any general assertion verb ("means", "returns",
     * "indicates"), or a copula backed by a concrete anchor ("the retry limit
     * is 3"). Widening this can only weaken a veto, which is the safe direction.
     *
     * @param  list<array{name:string, type:string}>  $entities
     */
    private function hasKnowledgeAssertion(string $haystack, string $text, array $entities): bool
    {
        if ($this->matchesAny($haystack, self::EXPLICIT_DECISION_MARKERS)
            || $this->matchesAny($haystack, self::NORMATIVE_RESTRICTION_MARKERS)
            || $this->matchesAny($haystack, self::CAUSAL_RATIONALE_MARKERS)
            || $this->matchesAny($haystack, self::ACTIONABLE_CONSEQUENCE_MARKERS)
            || $this->matchesAny($haystack, self::GENERAL_ASSERTION_MARKERS)
        ) {
            return true;
        }

        return $this->matchesAny($haystack, self::COPULA_ASSERTION_MARKERS)
            && $this->hasConcreteAnchor($text, $entities);
    }

    /** Lowercase, with typographic apostrophes folded to ASCII so "I'm" always matches. */
    private function haystack(string $text): string
    {
        return mb_strtolower(str_replace(['’', '‘', 'ʼ', '´'], "'", $text));
    }

    /**
     * @param  list<array{name:string, type:string}>  $entities
     */
    private function hasConcreteAnchor(string $text, array $entities): bool
    {
        if ($entities !== []) {
            return true;
        }

        return preg_match(self::CONCRETE_ANCHOR_PATTERN, $text) === 1;
    }

    private function wordCount(string $content): int
    {
        $words = preg_split('/\s+/u', $content, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return count(array_filter(
            $words,
            static fn (string $word): bool => preg_match('/\p{L}/u', $word) === 1,
        ));
    }

    /**
     * @param  list<string>  $markers
     */
    private function matchesAny(string $haystack, array $markers): bool
    {
        foreach ($markers as $marker) {
            $pattern = '/(?<![\p{L}\p{N}])'.preg_quote($marker, '/').'(?![\p{L}\p{N}])/u';

            if (preg_match($pattern, $haystack) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{id:string, adjustment:int, reason:string}
     */
    private function rule(string $id, int $adjustment, string $reason): array
    {
        return ['id' => $id, 'adjustment' => $adjustment, 'reason' => $reason];
    }
}
