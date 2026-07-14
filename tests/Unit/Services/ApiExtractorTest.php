<?php

use App\Services\Condense\ApiExtractor;
use App\Services\Condense\CandidateParser;
use App\Services\Condense\ExtractionPrompt;

it('maps the model text response into candidates', function () {
    $json = json_encode([[
        'title' => 'Use queue database driver',
        'content' => '# note', 'category' => 'decision',
        'entities' => [], 'relations' => [],
    ]]);

    $extractor = new class(app(ExtractionPrompt::class), app(CandidateParser::class), 'anthropic', 'claude-haiku-4-5-20251001', null) extends ApiExtractor
    {
        public string $captured = '';

        protected function respond(string $instructions, string $transcript): string
        {
            $this->captured = $transcript;

            return '```json'."\n".$GLOBALS['__api_json']."\n".'```';
        }
    };
    $GLOBALS['__api_json'] = $json;

    $out = $extractor->extract('USER: hi', null);

    expect($out)->toHaveCount(1);
    expect($out[0]['title'])->toBe('Use queue database driver');
    expect($extractor->captured)->toBe('USER: hi');
});

it('carries the project language into the system instructions', function () {
    $extractor = new class(app(ExtractionPrompt::class), app(CandidateParser::class), 'anthropic', 'claude-haiku-4-5-20251001', null) extends ApiExtractor
    {
        public string $instructions = '';

        protected function respond(string $instructions, string $transcript): string
        {
            $this->instructions = $instructions;

            return '[]';
        }
    };

    $extractor->extract('USER: hi', 'pt-BR');

    expect($extractor->instructions)->toContain('Brazilian Portuguese');
});
