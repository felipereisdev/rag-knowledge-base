<?php

namespace App\Services\Importance;

/**
 * The deterministic importance rules.
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
    public const string VERSION = 'v6';

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
     * v1..v4 all defined narration by enumerating what BREAKS it: a marker list,
     * then a progress-tail reader, then a list of clause-opening separators
     * (`,` `;` `:` en-dash, em-dash) plus a head-noun anchor. Every round, an
     * adversarial reviewer found real facts hard-vetoed through the separator
     * that had not been enumerated ("I checked the logs (the worker restarts on
     * every deploy).", "I read the config file [the parser lowercases every
     * key].", "I checked the logs -- the worker restarts on every deploy."), and
     * the head-noun anchor had its own hole. A blacklist of the ways a sentence
     * can carry a fact cannot be completed; the next round always finds `/`, `«`
     * or a newline. The blacklist WAS the bug.
     *
     * v5 inverts the predicate. Shape (a) is now a WHITELIST, and it is the same
     * whole-sentence discipline shape (b) has always had:
     *
     *     ^ <first-person operational opener> <tooling-artefact object> <terminal punctuation only> $
     *
     * NOTHING may follow the object. There is no separator class to enumerate and
     * nothing to under-list. Any residual content whatsoever — a parenthetical, a
     * bracket, a quote, an ASCII `--`, a slash, a coordinated clause, a
     * preposition, a second noun, a code path, an adverb — means the sentence is
     * not a bare operational clause, and it escapes to the semantic judge.
     *
     * That kills, by construction and identically in both languages, every tail
     * class listed or unlisted, and the sentence-final head-noun bug too: in "I
     * updated the log format." the object `the log` is followed by `format`, which
     * is residual content, so the sentence escapes without any noun-phrase
     * analysis at all.
     *
     * v6 applies the same whitelist principle to the TITLE. v5 read the narration
     * grammar over `content` ALONE while hasKnowledgeAssertion() read title+content,
     * so a candidate whose durable fact lived in the title over bare narration in
     * the body ("The worker restarts on every deploy." / "I checked the logs.") had
     * its fact invisible to the grammar, and only a lucky hit in the hand-tuned
     * GENERAL_ASSERTION_MARKERS lexicon stood between it and a hard veto — the same
     * failure class rounds 1..5 removed from the content channel, surviving in the
     * title channel. The veto now additionally requires the title to be
     * NON-INFORMATIVE: structurally incapable of carrying a fact. Same asymmetry as
     * everywhere else in this rule — a title nobody anticipated causes an ESCAPE,
     * never a veto.
     *
     * `agent_operation_only` fires only when ALL of the following hold:
     *
     *   1. the candidate asserts no knowledge anywhere (hasKnowledgeAssertion()
     *      over the whole text — kept deliberately broad; it can only ever WEAKEN
     *      a veto, so widening it is the safe direction). Since v5 (content) and
     *      v6 (title) the grammar alone already rejects every probe in the
     *      calibration corpus, so this is a genuine SECOND net rather than the last
     *      line of defence;
     *   2. EVERY sentence of the content is agent narration, not merely some
     *      sentence. A knowledge note with a trailing "All tests pass." keeps its
     *      knowledge, which kills the whole class of poisoning-by-one-line;
     *   3. a sentence is narration only in one of two shapes, and both are now
     *      whole-sentence shapes:
     *      (a) a first-person operational clause over a tooling artefact and
     *          NOTHING ELSE — "I ran the tests.", "I'm checking the file.",
     *          "Let me open the file.", "Corri os testes."
     *          (FIRST_PERSON_OPERATION_PATTERN, PT_FIRST_PERSON_OPERATION_PATTERN,
     *          LET_NARRATION_PATTERN, PT_LET_NARRATION_PATTERN);
     *      (b) a terse status phrase, or a comma/and-joined chain of them, that is
     *          the ENTIRE sentence — "All tests pass.", "Done.", "Ran the tests."
     *          (AGENT_STATUS_PHRASES);
     *   4. the TITLE is non-informative (isNonInformativeTitle()): empty, a
     *      placeholder ("Note", "Nota", "Untitled", "Sem título"), or itself
     *      narration under the very same grammar as (2)+(3) ("Ran the tests",
     *      "All tests pass", "Corri os testes"). Any ordinary noun phrase or
     *      declarative sentence in the title — "The worker restarts on every
     *      deploy", "The parser lowercases every key", "O parser normaliza cada
     *      chave" — means the title MAY carry the fact, and the candidate escapes
     *      structurally, with the lexicon never consulted.
     *
     * The accepted, deliberate recall loss: the veto now reaches only BARE
     * operational clauses and content made entirely of them. Chatter with any tail
     * at all ("I ran the tests and all tests pass now.", "I read the diff (nothing
     * changed).") escapes to the judge, which scores it low. That is the correct
     * trade for a hard veto and it is the end state of this rule, not a gap to be
     * closed later.
     *
     * Tense discipline inside shape (a): the convention voice is first-person
     * PLURAL present ("We run model-backed jobs on the classification queue."), so
     * bare present-tense `we run` / `we read` are NOT narration; `we ran`, `we're
     * reading`, `we've run` are. Bare `I read the diff.` IS narration: English
     * `read` is a present/past homograph and no project convention is ever authored
     * in the first-person singular. Under the whitelist that license is harmless —
     * an `I read …` sentence that reports anything has residual content and escapes.
     */
    private const string TOOLING_OBJECT = '
        (?:(?:the|a|an|this|that|these|those|our|my|its)\s+)?
        (?:(?!(?:into|to|from|for|with|on|at|in|of|about|and|or|the|a|an)(?![\p{L}\p{N}]))[\w.\/-]+\s+){0,2}
        (?:files?|tests?|test\s+suite|suite|diff|patch|stub|snippet|logs?|output|codebase
           |linter|command|script|pest|phpstan|pint|phpunit)
        (?![\p{L}\p{N}])
    ';

    /**
     * The end of a shape-(a) sentence. Only whitespace and punctuation may follow
     * the tooling object: no letter and no digit, in any script. This single class
     * replaces the whole v4 tail apparatus (FURTHER_CLAUSE_PATTERN plus the two
     * head-noun anchors and their closed-class follower lists) and cannot be
     * defeated by an unlisted separator, because it lists nothing — it requires
     * the sentence to be OVER.
     */
    private const string NOTHING_FOLLOWS = '[\s\p{P}\p{S}]*$';

    /** Bare verb forms. They only signal narration behind an auxiliary, a modal, or a let-form. */
    private const string OPERATION_VERBS_BARE = '
        (?:re-?)?
        (?:run|read|check|inspect|execute|open|search|grep|list|update|create|write
           |add|fix|edit|patch|delete|remove|verify|examine|review|apply)
    ';

    /** Inflected forms that are narration on their own: past tense, progressive, participle. */
    private const string OPERATION_VERBS_INFLECTED = '
        (?:
            ran|re-?ran|reran|running
            |re-?read|reading
            |check(?:ed|ing)|inspect(?:ed|ing)|execut(?:ed|ing)
            |open(?:ed|ing)|search(?:ed|ing)|grepp(?:ed|ing)|list(?:ed|ing)
            |updated|updating|created|creating|wrote|written|writing|added|adding
            |fixed|fixing|edited|editing|patched|patching|deleted|deleting
            |removed|removing|renamed
        )
    ';

    /**
     * Shape (a), English: the WHOLE sentence is a first-person subject, an
     * operational verb, and a tooling artefact. `^` and NOTHING_FOLLOWS are the
     * two halves of the same discipline — a narration clause with anything in
     * front of it or anything behind it is not a bare operational clause, and
     * escapes.
     *
     *   (1) narration morphology, no auxiliary needed ("I ran the tests",
     *       "I'm checking the file", "we were running the suite");
     *   (2) an auxiliary / modal / announced intent licenses a bare verb form
     *       ("I have run the tests", "I'll run the suite", "we've run the
     *       tests"). The auxiliary is REQUIRED here, so the plain present tense
     *       of a convention ("We run the tests on every push.") never reaches
     *       this branch;
     *   (3) bare "I read the diff" — licensed for the first-person SINGULAR
     *       only, because a convention is never written as "I".
     */
    private const string FIRST_PERSON_OPERATION_PATTERN = '/^
        (?:
            (?:i|we)(?:\'(?:m|re|ve))?
            (?:\s+(?:am|are|was|were|have|has|had|just|already|now|then|also|finally))*
            \s+'.self::OPERATION_VERBS_INFLECTED.'
            |
            (?:
                (?:i|we)\'(?:ve|ll|m|re|d)
                |
                (?:i|we)(?:\s+(?:have|has|had|will|would|am|are|was|were
                                |need\s+to|want\s+to|going\s+to|about\s+to|plan\s+to))+
            )
            (?:\s+(?:just|already|now|then|also|finally|first|quickly|going\s+to|about\s+to|to))*
            \s+'.self::OPERATION_VERBS_BARE.'
            |
            i(?:\s+(?:just|already|then|also|first))*\s+read
        )
        \s+'.self::TOOLING_OBJECT.self::NOTHING_FOLLOWS.'
    /xu';

    /**
     * Shape (a), English let-form. The operational verb AND the tooling object
     * are both required: "Let's Encrypt certificates renew 30 days before
     * expiry." is knowledge about a certificate authority, and "Let me know the
     * retry limit" is not an operation on a tooling artefact.
     */
    private const string LET_NARRATION_PATTERN = '/^
        let(?:\s+me|\s+us|\'s)
        (?:\s+(?:now|first|then|just|quickly|also))*
        \s+'.self::OPERATION_VERBS_BARE.'
        \s+'.self::TOOLING_OBJECT.self::NOTHING_FOLLOWS.'
    /xu';

    /**
     * Shape (a), Portuguese. Every branch carries an explicit first-person
     * subject or inflection, so a declarative progressive ("O worker está a
     * executar em lotes de 32 vetores.") and a third-person present ("O comando
     * de deploy corre os testes.") can never match. The v2 sentence-initial
     * gerund openers ('a executar os testes…') are gone with the English ones:
     * "A executar os testes em paralelo, o sqlite fica corrompido." is knowledge.
     */
    private const string PT_FIRST_PERSON_OPERATION_PATTERN = '/^
        (?:
            (?:vou|vamos)\s+(?:voltar\s+a\s+)?'.self::PT_OPERATION_VERBS_INFINITIVE.'
            |
            (?:estou|estamos)\s+(?:a\s+)?
            (?:'.self::PT_OPERATION_VERBS_INFINITIVE.'|'.self::PT_OPERATION_VERBS_GERUND.')
            |
            (?:acabei|acabamos|acabo)\s+de\s+'.self::PT_OPERATION_VERBS_INFINITIVE.'
            |
            '.self::PT_OPERATION_VERBS_FIRST_PERSON_PAST.'
        )
        \s+'.self::PT_TOOLING_OBJECT.self::NOTHING_FOLLOWS.'
    /xu';

    /** Shape (a), Portuguese let-form ("Deixa-me ler o ficheiro."). */
    private const string PT_LET_NARRATION_PATTERN = '/^
        deix[ae](?:-|\s+)(?:me|nos)
        \s+'.self::PT_OPERATION_VERBS_INFINITIVE.'
        \s+'.self::PT_TOOLING_OBJECT.self::NOTHING_FOLLOWS.'
    /xu';

    private const string PT_OPERATION_VERBS_INFINITIVE = '
        (?:executar|correr|rodar|ler|reler|abrir|verificar|inspecionar|procurar|listar
           |escrever|criar|atualizar|editar|apagar|remover|corrigir)
    ';

    private const string PT_OPERATION_VERBS_GERUND = '
        (?:executando|correndo|rodando|lendo|abrindo|verificando|inspecionando|procurando
           |listando|escrevendo|criando|atualizando|editando|apagando|removendo|corrigindo)
    ';

    private const string PT_OPERATION_VERBS_FIRST_PERSON_PAST = '
        (?:executei|corri|rodei|li|reli|abri|verifiquei|inspecionei|procurei|listei
           |escrevi|criei|atualizei|editei|apaguei|removi|corrigi)
    ';

    /**
     * The modifier slots exclude every function word that can introduce a
     * PP, so the tooling keyword can never be reached from INSIDE a prepositional
     * phrase ("o formato dos logs" — head is `formato`, `logs` sits inside `dos
     * logs` and must not make the object a tooling artefact).
     */
    private const string PT_TOOLING_OBJECT = '
        (?:(?:o|a|os|as|um|uma|este|esta|esse|essa|nosso|nossa)\s+)?
        (?:(?!(?:o|a|os|as|de|do|da|dos|das|em|no|na|nos|nas|num|numa|para|com|por|pelo|pela
               |ao|aos|à|às|e|ou)(?![\p{L}\p{N}]))[\w.\/-]+\s+){0,2}
        (?:ficheiros?|arquivos?|testes?|su[ií]tes?|diffs?|patch|logs?|comando|linter|c[óo]digo)
        (?![\p{L}\p{N}])
    ';

    /**
     * Shape (b): terse status phrases. They only count as narration when one of
     * them (or a comma/and-joined chain of them, "Ran the tests, everything is
     * green.") is the WHOLE sentence. A declarative tail makes it ambiguous, and
     * ambiguous text escapes: "All tests pass on the release branch only after
     * the seeder has populated the demo tenant." is knowledge.
     *
     * @var list<string>
     */
    private const array AGENT_STATUS_PHRASES = [
        'all tests pass', 'all tests passed', 'all tests now pass', 'all tests are green',
        'tests pass', 'tests passed', 'tests are green', 'everything is green',
        'the build is green', 'build is green', 'done', 'all done', 'task completed',
        'task complete', 'no errors', 'no issues found', 'it works', 'fixed it',
        'ran the tests', 'ran the test suite', 'ran the suite', 'ran pest', 'ran phpstan',
        'ran pint', 'ran the linter', 'ran the command', 're-ran the tests', 'reran the tests',
        'todos os testes passam', 'todos os testes passaram', 'testes todos verdes',
        'tarefa concluída', 'tarefa concluida', 'concluído', 'concluido', 'feito',
        'tudo verde', 'sem erros',
    ];

    /**
     * Generic placeholder titles: a title that names the note instead of stating
     * anything about the project. Together with "the title is itself narration"
     * and "the title is empty", this is the whole of isNonInformativeTitle().
     *
     * A CLOSED LIST IS SAFE HERE, and it is the exact opposite of a marker
     * blacklist. This is a WHITELIST of the titles that PERMIT the veto: a title
     * that is not on it (and is not narration, and is not empty) makes the rule
     * refuse to fire. Under-listing therefore costs one judge call on chatter
     * whose title we did not anticipate; it can never destroy knowledge. Every
     * previous list in this class had the opposite polarity, and that is precisely
     * why every previous list was a bug.
     *
     * Matched as the WHOLE title (after case-folding and stripping surrounding
     * punctuation), never as a substring: "Notes on why the parser lowercases
     * every key" is a real title and must escape.
     *
     * @var list<string>
     */
    private const array PLACEHOLDER_TITLES = [
        'note', 'notes', 'new note', 'quick note', 'agent note', 'session note', 'session notes',
        'untitled', 'no title', 'title', 'entry', 'knowledge', 'memo', 'summary', 'update',
        'info', 'information', 'misc', 'miscellaneous', 'general', 'log', 'notes to self',
        'next steps', 'context', 'observations',
        'nota', 'notas', 'nova nota', 'sem título', 'sem titulo', 'sem nome', 'título',
        'titulo', 'entrada', 'conhecimento', 'resumo', 'atualização', 'atualizacao',
        'informação', 'informacao', 'geral', 'diversos', 'contexto', 'observações',
        'observacoes', 'próximos passos', 'proximos passos',
    ];

    /**
     * General assertion verbs. They are NOT a positive signal (they do not
     * change the score); they only widen the `hasKnowledgeAssertion()` escape
     * hatch, which can only ever WEAKEN a veto — the safe direction under a
     * precision-first veto.
     *
     * Up to v3 this list was the LAST line of defence in the CONTENT channel, and
     * up to v5 it was still the last line of defence in the TITLE channel: a
     * sentence that reported a fact was structurally "narration", so only a verb
     * that happened to be listed here saved it. That made precision hostage to a
     * hand-tuned English word list, and the Portuguese half was materially
     * thinner — the identical fact was destroyed in PT and kept in EN. "The parser
     * rejects every uppercase key" survived on `rejects`; "The parser lowercases
     * every key" died.
     *
     * Both channels are structural now. The grammar ALONE — v5's whole-sentence
     * narration whitelist over the content, plus v6's non-informative-title
     * whitelist — already refuses to call any probe in the calibration corpus
     * narration, WITHOUT consulting this list; the test 'does not depend on the
     * knowledge-assertion lexicon for its precision' bypasses the lexicon entirely
     * and pins exactly that, over the full candidate, title included.
     *
     * So the list is a genuine SECOND net: it can now only weaken a veto that the
     * grammar was already willing to let fire. It is kept — removing entries would
     * NARROW an escape hatch, the one direction this rule must never move in — but
     * it is deliberately never extended. Adding a word here to rescue a false veto
     * is the failure mode of rounds 1..4: it fixes one sentence and leaves the
     * class intact. If a false veto appears, fix the grammar.
     *
     * @var list<string>
     */
    private const array GENERAL_ASSERTION_MARKERS = [
        'means', 'indicates', 'implies', 'denotes', 'stands for', 'refers to',
        'returns', 'yields', 'guarantees', 'enforces', 'defaults to', 'applies to',
        'depends on', 'is defined as', 'says', 'states', 'specifies', 'documents',
        'defines', 'expects', 'accepts', 'rejects', 'blocks', 'allows', 'disables',
        'enables', 'limits', 'corrupts', 'invalidates', 'overwrites',
        // Behaviour of a system thing, stated as an observed fact. These carry no
        // score of their own; they only widen the escape hatch, so a first-person
        // sentence that ALSO reports what the system does ("We ran the tests
        // against Postgres 16 and the jsonb ordering changed.") keeps its fact.
        'changed', 'changes', 'differs', 'fires', 'happens', 'occurs', 'takes',
        'costs', 'hits', 'leaks', 'skips', 'adds', 'drops', 'caps at', 'stops at',
        'corrupted', 'broke', 'crashed', 'deadlocked', 'leaked', 'overflowed',
        'timed out', 'took',
        'significa', 'indica', 'implica', 'retorna', 'devolve', 'garante', 'define',
        'exige', 'depende de', 'corresponde a', 'bloqueia', 'impede', 'permite',
        'rejeita', 'especifica', 'diz', 'mudou', 'alterou', 'muda', 'altera',
        'fica', 'ficam', 'demora', 'ocorre',
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

    /**
     * A quantified measurement ("30s", "12 minutes", "512 MB", "60 requests").
     * It counts as a knowledge assertion on its own: a number carrying a unit is
     * a statement about how the system behaves, and agent chatter reports bare
     * counts ("3 failed", "ran it 3 times") rather than measurements. Like every
     * other branch of hasKnowledgeAssertion() it can only WEAKEN a veto.
     */
    private const string MEASUREMENT_ASSERTION_PATTERN = '/
        \b\d+(?:[.,]\d+)?\s*
        (?:ms|µs|ns|s|secs?|seconds?|mins?|minutes?|hours?|days?|weeks?|months?
           |[kmgt]i?b|%|rps|qps|rpm)
        (?![\p{L}\p{N}])
        |
        \b\d+\s+
        (?:rows?|requests?|dimensions?|bytes?|attempts?|retries|workers?|connections?
           |tokens?|queries|jobs?|entries|chunks?|vectors?|columns?|shards?|replicas?)
        (?![\p{L}\p{N}])
    /xiu';

    /** A concrete anchor: code identifier, path, number, acronym, or dotted token. */
    private const string CONCRETE_ANCHOR_PATTERN = '/`|[\/\\\\][\w.-]+|\b\d+\b|\b[A-Z]{2,}\b|\b\w+_\w+\b|\b[a-z]+[A-Z]\w*\b|\b[A-Z][a-z0-9]+[A-Z]\w*\b|\b\w+\.\w{2,}\b/u';

    public function __construct(private readonly string $rulesVersion = self::VERSION) {}

    public function evaluate(NormalizedImportanceCandidate $candidate): RuleEvaluation
    {
        $data = $candidate->data();
        $text = trim($data['title']."\n".$data['content']);
        $haystack = $this->haystack($text);

        $vetoes = $this->vetoes($data['title'], $data['content'], $text, $haystack, $data['entities']);

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
    private function vetoes(string $title, string $content, string $text, string $haystack, array $entities): array
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

        if ($this->isAgentOperationOnly($title, $content) && ! $this->hasKnowledgeAssertion($haystack, $text, $entities)) {
            $vetoes[] = $this->rule('agent_operation_only', self::VETO_ADJUSTMENT, 'Content reports an agent operation without asserting knowledge.');
        }

        return $vetoes;
    }

    /**
     * The WHOLE structural half of `agent_operation_only`, over the WHOLE
     * candidate: the content must be nothing but narration AND the title must be
     * incapable of carrying a fact. hasKnowledgeAssertion() is not consulted here
     * and must not be — this predicate is what the structural-invariant test
     * exercises with the lexicon bypassed, and it has to see exactly the text the
     * lexicon sees (title included), or the title channel goes back to being
     * pinned by nothing.
     *
     * Both channels have to be closed because a candidate is title+content: the
     * fact can live in either one. v5 read the grammar over the content alone and
     * so could not see "The worker restarts on every deploy." / "I checked the
     * logs." — a fact, hard-vetoed.
     */
    private function isAgentOperationOnly(string $title, string $content): bool
    {
        return $this->isNonInformativeTitle($title)
            && $this->isAgentOperationNarration($this->haystack($content));
    }

    /**
     * True only when the title cannot be carrying the candidate's fact. Three
     * ways, and no fourth:
     *
     *   - it is empty, or has no letters at all;
     *   - it is a generic placeholder (PLACEHOLDER_TITLES, or the same
     *     placeholder tokens PLACEHOLDER_PATTERN already recognises in content —
     *     "TBD", "N/A", "WIP");
     *   - it is itself agent narration or a terse status phrase, under the very
     *     same whole-sentence grammar the content channel uses. A chatter entry's
     *     title routinely IS one: "Ran the tests", "All tests pass", "Corri os
     *     testes".
     *
     * ANYTHING else — an ordinary noun phrase, a declarative sentence, a title in
     * a language or shape nobody enumerated — is treated as possibly carrying the
     * fact, and the veto does not fire. Note the direction of the failure: an
     * unrecognised title makes the rule SAFER (an escape, one judge call), never
     * more dangerous. That is what licenses the closed list in PLACEHOLDER_TITLES
     * and is the opposite of the marker blacklists of rounds 1..4.
     */
    private function isNonInformativeTitle(string $title): bool
    {
        $title = trim($title);

        if ($title === '' || preg_match('/\p{L}/u', $title) !== 1) {
            return true;
        }

        if (preg_match(self::PLACEHOLDER_PATTERN, $title) === 1) {
            return true;
        }

        $haystack = $this->haystack($title);

        // Strip surrounding punctuation and whitespace so "Note:", "[note]" and
        // "— Nota —" are the same placeholder as "Note".
        $bare = trim((string) preg_replace('/^[\s\p{P}\p{S}]+|[\s\p{P}\p{S}]+$/u', '', $haystack));

        if (in_array($bare, self::PLACEHOLDER_TITLES, true)) {
            return true;
        }

        return $this->isAgentOperationNarration($haystack);
    }

    /**
     * True only when EVERY sentence of the content is agent narration. "Some
     * sentence is narration" is not enough: a candidate that states a fact and
     * then signs off with "Done." still carries the fact, and vetoing the whole
     * candidate for its last line destroyed real knowledge. Requiring all of
     * them removes that failure by construction.
     *
     * Narration itself is only the two shapes described on TOOLING_OBJECT above.
     * Anything else escapes to the semantic judge by design — losing recall here
     * is an accepted trade, losing precision is not.
     */
    private function isAgentOperationNarration(string $contentHaystack): bool
    {
        $sentences = $this->sentences($contentHaystack);

        if ($sentences === []) {
            return false;
        }

        foreach ($sentences as $sentence) {
            if (! $this->isNarrationSentence($sentence)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Both shapes are whole-sentence matches: every pattern is anchored at `^`
     * and ends at `$` with nothing but punctuation allowed in between the object
     * and the end. A sentence that carries anything else is not narration, and no
     * separator, clause type or noun phrase has to be recognised to know that.
     */
    private function isNarrationSentence(string $sentence): bool
    {
        if ($this->isStatusSentence($sentence)) {
            return true;
        }

        $patterns = [
            self::FIRST_PERSON_OPERATION_PATTERN,
            self::PT_FIRST_PERSON_OPERATION_PATTERN,
            self::LET_NARRATION_PATTERN,
            self::PT_LET_NARRATION_PATTERN,
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $sentence) === 1) {
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
            // status line is still recognised as a whole sentence.
            static fn (string $sentence): string => trim((string) preg_replace('/^[\s\p{Pd}*>#`"\'\x{2022}\p{Pi}]+/u', '', $sentence)),
            $sentences,
        );

        return array_values(array_filter(
            $sentences,
            static fn (string $sentence): bool => preg_match('/\p{L}/u', $sentence) === 1,
        ));
    }

    /**
     * Shape (b): the sentence is nothing but terse status phrases — one, or a
     * comma/and-joined chain of them. Every segment must be a listed phrase, so
     * no declarative tail can ride along.
     */
    private function isStatusSentence(string $sentence): bool
    {
        $phrase = '(?:'.implode('|', array_map(
            static fn (string $status): string => preg_quote($status, '/'),
            self::AGENT_STATUS_PHRASES,
        )).')(?:\s+(?:now|again|agora|já))?';

        $pattern = '/^'.$phrase.'(?:(?:\s*[,;]\s*|\s+(?:and|e)\s+)'.$phrase.')*\s*[.!\x{2026}]*$/u';

        return preg_match($pattern, $sentence) === 1;
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
     * The safety net under the agent-operation veto, and the first of the three
     * conditions that must ALL hold for it to fire. A knowledge assertion is any
     * of the four positive signals (a decision, a rule, a reason, a
     * consequence), any general assertion verb ("means", "returns", "changed"),
     * a quantified measurement ("the suite took 12 minutes"), or a copula backed
     * by a concrete anchor ("the retry limit is 3"). Widening this can only ever
     * weaken a veto, which is the safe direction under a precision-first veto,
     * so it is deliberately generous.
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

        if (preg_match(self::MEASUREMENT_ASSERTION_PATTERN, $haystack) === 1) {
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
