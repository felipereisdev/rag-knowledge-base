<?php

// tests/Feature/Stubs/CodexStubsTest.php

it('ships Codex adapters, hooks.json, and mcp snippet', function () {
    $base = base_path('stubs/client/codex');

    foreach (['hooks/session-start.sh', 'hooks/user-prompt.sh', 'hooks/stop.sh',
        'hooks.json', 'config.toml.snippet'] as $rel) {
        expect(file_exists("$base/$rel"))->toBeTrue("missing $rel");
    }

    $hooks = json_decode(file_get_contents("$base/hooks.json"), true);
    expect(json_encode($hooks))->toContain('SessionStart')
        ->and(json_encode($hooks))->toContain('UserPromptSubmit')
        ->and(json_encode($hooks))->toContain('Stop');

    $toml = file_get_contents("$base/config.toml.snippet");
    expect($toml)->toContain('[mcp_servers.rag]')
        ->and($toml)->not->toContain('martis');

    expect(file_get_contents("$base/hooks/user-prompt.sh"))->toContain('additionalContext');

    $stopSh = file_get_contents("$base/hooks/stop.sh");
    expect($stopSh)->toContain('rag_condense_post')
        ->and($stopSh)->toContain('transcript_path')
        ->and($stopSh)->not->toContain('rag_condense_instruction')
        ->and($stopSh)->not->toContain('decision":"block');
});
