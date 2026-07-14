<?php

namespace App\Services\Condense;

use Laravel\Ai\AnonymousAgent;

class ApiExtractor implements KnowledgeExtractor
{
    public function __construct(
        private readonly ExtractionPrompt $prompt,
        private readonly CandidateParser $parser,
        private readonly string $provider,
        private readonly string $model,
        private readonly ?string $override,
    ) {}

    public function extract(string $transcript, ?string $language): array
    {
        $instructions = $this->prompt->instructions($this->override, $language);
        $text = $this->respond($instructions, $transcript);

        return $this->parser->parse($text);
    }

    /** Seam for testing; issues the real LLM call. */
    protected function respond(string $instructions, string $transcript): string
    {
        $agent = new AnonymousAgent($instructions, [], []);

        return $agent->prompt($transcript, [], $this->provider, $this->model)->text;
    }
}
