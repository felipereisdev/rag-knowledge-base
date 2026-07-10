<?php

// tests/Feature/Stubs/CursorStubsTest.php

it('ships Cursor session-start + stop hooks, config, mcp, rules', function () {
    $base = base_path('stubs/client/cursor');

    foreach (['hooks/session-start.sh', 'hooks/stop.sh', 'hooks.json', 'mcp.json', 'cursorrules'] as $rel) {
        expect(file_exists("$base/$rel"))->toBeTrue("missing $rel");
    }
    // No per-prompt hook on Cursor.
    expect(file_exists("$base/hooks/user-prompt.sh"))->toBeFalse();

    $hooks = json_decode(file_get_contents("$base/hooks.json"), true);
    expect($hooks['version'])->toBe(1);
    expect($hooks['hooks'])->toHaveKey('sessionStart');
    expect($hooks['hooks'])->toHaveKey('stop');

    $mcp = json_decode(file_get_contents("$base/mcp.json"), true);
    expect($mcp['mcpServers'])->toHaveKey('rag')->and($mcp['mcpServers'])->not->toHaveKey('martis');

    expect(file_get_contents("$base/hooks/session-start.sh"))->toContain('additional_context');

    $stopSh = file_get_contents("$base/hooks/stop.sh");
    expect($stopSh)->toContain('rag_condense_post')
        ->and($stopSh)->toContain("echo '{}'")
        ->and($stopSh)->not->toContain('followup_message');
    // Stale Python reference must not reappear.
    expect(file_get_contents("$base/cursorrules"))->not->toContain('rag/server/main.py');
});
