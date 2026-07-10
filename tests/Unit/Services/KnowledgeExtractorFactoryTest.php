<?php

use App\Models\CondenseSetting;
use App\Services\Condense\ApiExtractor;
use App\Services\Condense\ClaudeSdkExtractor;
use App\Services\Condense\KnowledgeExtractorFactory;

it('builds a ClaudeSdkExtractor for driver claude_sdk', function () {
    $s = new CondenseSetting(['driver' => 'claude_sdk', 'model' => 'claude-haiku-4-5-20251001']);
    expect(app(KnowledgeExtractorFactory::class)->make($s))->toBeInstanceOf(ClaudeSdkExtractor::class);
});

it('builds an ApiExtractor for driver api', function () {
    $s = new CondenseSetting(['driver' => 'api', 'provider' => 'anthropic', 'model' => 'claude-haiku-4-5-20251001']);
    expect(app(KnowledgeExtractorFactory::class)->make($s))->toBeInstanceOf(ApiExtractor::class);
});
