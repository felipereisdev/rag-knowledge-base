<?php

namespace App\Services\Condense;

use App\Enums\ProjectLanguage;

final class ExtractionPrompt
{
    /**
     * Build the extractor's system instructions for a project.
     *
     * The language directive is appended to the override as well: an override
     * replaces the base instructions wholesale, so folding the directive into
     * the base text alone would silently drop the project language for every
     * operator who customises the prompt.
     */
    public function instructions(?string $override, ?string $language): string
    {
        $override = $override !== null ? trim($override) : '';
        $base = $override !== '' ? $override : $this->base();

        return $base."\n\n".$this->languageDirective($language);
    }

    private function base(): string
    {
        return <<<'PROMPT'
        You condense a coding-session transcript into durable project knowledge.

        Extract ONLY durable knowledge: decisions, rules, architecture notes,
        non-obvious fixes, and conventions. Ignore ephemeral chatter, transient
        debugging steps, and anything trivially derivable from reading the code.

        Output ONLY a JSON array (no prose, no code fences). Each item:
        {
          "title": "short descriptive title",
          "content": "Markdown explaining the knowledge",
          "category": "one of: business-rule, design-decision, architecture, documentation, insight, convention, constraint",
          "entities": [{"name": "...", "type": "..."}],
          "relations": [{"subject": "...", "predicate": "...", "object": "..."}]
        }

        If there is nothing durable, output exactly: []
        PROMPT;
    }

    /**
     * An unknown or empty code falls back to English, matching both the
     * `projects.language` column default and PostgresTextSearch's own fallback,
     * so what gets written and what gets stemmed agree.
     */
    private function languageDirective(?string $language): string
    {
        $resolved = ProjectLanguage::tryFromCode($language) ?? ProjectLanguage::English;
        $name = $resolved->promptName();
        $code = $resolved->value;

        return <<<DIRECTIVE
        Write the "title" and "content" values in {$name} ({$code}) — the project's
        configured content language — whatever language the transcript itself is in.

        The JSON keys and the "category" value must stay in English, exactly as
        spelled above: they are a fixed vocabulary the parser matches on, not prose.
        Entity and relation names stay verbatim as they appear in the code.
        DIRECTIVE;
    }
}
