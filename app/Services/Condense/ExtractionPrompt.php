<?php

namespace App\Services\Condense;

final class ExtractionPrompt
{
    public function instructions(?string $override): string
    {
        $override = $override !== null ? trim($override) : '';
        if ($override !== '') {
            return $override;
        }

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
}
