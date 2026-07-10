<?php

namespace App\Services\Condense;

use App\Enums\ExtractorDriver;
use App\Models\CondenseSetting;

final class KnowledgeExtractorFactory
{
    public function __construct(
        private readonly ExtractionPrompt $prompt,
        private readonly CandidateParser $parser,
    ) {}

    public function make(CondenseSetting $setting): KnowledgeExtractor
    {
        return match (ExtractorDriver::from($setting->driver)) {
            ExtractorDriver::Api => new ApiExtractor(
                $this->prompt, $this->parser,
                $setting->provider ?: 'anthropic', $setting->model, $setting->system_prompt_override,
            ),
            ExtractorDriver::ClaudeSdk => new ClaudeSdkExtractor(
                $this->prompt, $this->parser, $setting->model, $setting->system_prompt_override,
            ),
        };
    }
}
