<?php

// tests/Feature/Stubs/ClaudeStubsTest.php

it('ships Claude adapters, settings, mcp, and skill', function () {
    $base = base_path('stubs/client/claude');

    foreach (['hooks/session-start.sh', 'hooks/user-prompt.sh', 'hooks/stop.sh',
        'settings.json', 'mcp.json', 'skills/using-rag/SKILL.md'] as $rel) {
        expect(file_exists("$base/$rel"))->toBeTrue("missing $rel");
    }

    $settings = json_decode(file_get_contents("$base/settings.json"), true);
    expect($settings)->toHaveKey('hooks');
    expect(json_encode($settings))->toContain('SessionStart')
        ->and(json_encode($settings))->toContain('UserPromptSubmit')
        ->and(json_encode($settings))->toContain('Stop');

    $mcp = json_decode(file_get_contents("$base/mcp.json"), true);
    expect($mcp['mcpServers'])->toHaveKey('rag');
    expect($mcp['mcpServers'])->not->toHaveKey('martis');

    $stopSh = file_get_contents("$base/hooks/stop.sh");
    expect($stopSh)->toContain('rag_condense_post')
        ->and($stopSh)->toContain('transcript_path')
        ->and($stopSh)->not->toContain('rag_condense_instruction')
        ->and($stopSh)->not->toContain('decision":"block');
});
