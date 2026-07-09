<?php
// tests/Feature/Stubs/OpencodeStubsTest.php

it('ships the opencode plugin and mcp snippet', function () {
    $base = base_path('stubs/client/opencode');
    expect(file_exists("$base/plugin/rag.ts"))->toBeTrue();
    expect(file_exists("$base/mcp.snippet.json"))->toBeTrue();

    $ts = file_get_contents("$base/plugin/rag.ts");
    expect($ts)->toContain('session.idle')
        ->and($ts)->toContain('chat.message')
        ->and($ts)->toContain('/hooks/')
        ->and($ts)->toContain('ragPost("search"')
        ->and($ts)->toContain('__RAG_URL__');

    $mcp = json_decode(file_get_contents("$base/mcp.snippet.json"), true);
    expect($mcp['mcp'])->toHaveKey('rag')->and($mcp['mcp'])->not->toHaveKey('martis');
});
