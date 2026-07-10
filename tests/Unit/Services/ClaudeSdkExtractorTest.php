<?php

use App\Services\Condense\CandidateParser;
use App\Services\Condense\ClaudeSdkExtractor;
use App\Services\Condense\ExtractionPrompt;
use Illuminate\Support\Facades\Log;

it('returns empty and logs when the claude binary is missing', function () {
    Log::spy();

    $extractor = new ClaudeSdkExtractor(
        app(ExtractionPrompt::class), app(CandidateParser::class),
        'claude-haiku-4-5-20251001', null,
        binary: 'claude-binary-that-does-not-exist-xyz',
    );

    expect($extractor->extract('USER: hi'))->toBe([]);
    Log::shouldHaveReceived('warning')->once();
});
