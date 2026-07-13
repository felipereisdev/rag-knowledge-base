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
    public const string VERSION = 'v1';

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

    /** Shorter than this (after normalization) there is nothing to judge. */
    public const int MIN_CONTENT_LENGTH = 20;

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
     * Phrases that describe what the agent is doing, not what is true about the
     * project. On their own they are noise; combined with a knowledge assertion
     * they are fine (see `hasKnowledgeAssertion`).
     *
     * @var list<string>
     */
    private const array AGENT_OPERATION_MARKERS = [
        'let me', 'let us', 'i will now', "i'll now", 'i am going to', "i'm going to",
        'i have updated', 'i have created', 'i have added', 'i have fixed', 'i have read',
        'running the tests', 'running the test suite', 'reading the file', 'writing the file',
        'creating the file', 'tool call', 'exit code', 'task completed', 'all tests pass',
        'here is the diff', 'here is a summary',
        'vou executar', 'vou ler', 'vou criar', 'vou atualizar', 'estou a ler',
        'tarefa concluída', 'a executar', 'segue o diff',
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
        $haystack = mb_strtolower($text);

        $vetoes = $this->vetoes($data['content'], $haystack);

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
     * @return list<array{id:string, adjustment:int, reason:string}>
     */
    private function vetoes(string $content, string $haystack): array
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

        if ($this->matchesAny($haystack, self::AGENT_OPERATION_MARKERS) && ! $this->hasKnowledgeAssertion($haystack)) {
            $vetoes[] = $this->rule('agent_operation_only', self::VETO_ADJUSTMENT, 'Content reports an agent operation without asserting knowledge.');
        }

        return $vetoes;
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

    private function isEmptyOrUnreadable(string $content): bool
    {
        return $content === ''
            || mb_strlen($content) < self::MIN_CONTENT_LENGTH
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
     * A knowledge assertion is any of the four positive signals: an operational
     * message that also states a decision, a rule, a reason, or a consequence is
     * knowledge, not chatter — so it must never be vetoed.
     */
    private function hasKnowledgeAssertion(string $haystack): bool
    {
        return $this->matchesAny($haystack, self::EXPLICIT_DECISION_MARKERS)
            || $this->matchesAny($haystack, self::NORMATIVE_RESTRICTION_MARKERS)
            || $this->matchesAny($haystack, self::CAUSAL_RATIONALE_MARKERS)
            || $this->matchesAny($haystack, self::ACTIONABLE_CONSEQUENCE_MARKERS);
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
