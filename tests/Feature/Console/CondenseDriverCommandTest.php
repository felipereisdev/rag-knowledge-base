<?php

use App\Models\CondenseSetting;

it('prints the api driver from the setting', function () {
    CondenseSetting::current()->update(['driver' => 'api']);

    $this->artisan('rag:condense-driver')
        ->expectsOutput('api')
        ->assertExitCode(0);
});

it('prints the claude_sdk driver from the setting', function () {
    CondenseSetting::current()->update(['driver' => 'claude_sdk']);

    $this->artisan('rag:condense-driver')
        ->expectsOutput('claude_sdk')
        ->assertExitCode(0);
});
