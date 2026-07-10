<?php

use App\Enums\ExtractorDriver;
use App\Enums\ExtractorProvider;

it('exposes driver options keyed by value', function () {
    expect(ExtractorDriver::options())->toHaveKeys(['claude_sdk', 'api']);
    expect(ExtractorDriver::from('claude_sdk'))->toBe(ExtractorDriver::ClaudeSdk);
});

it('exposes provider options keyed by value', function () {
    expect(ExtractorProvider::options())->toHaveKeys(['anthropic', 'openai', 'gemini', 'openrouter']);
});
