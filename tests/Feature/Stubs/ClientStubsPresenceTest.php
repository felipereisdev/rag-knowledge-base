<?php

// tests/Feature/Stubs/ClientStubsPresenceTest.php

it('ships the shared shell core and config templates', function () {
    $core = base_path('stubs/client/hooks/lib/rag-core.sh');
    $cfg = base_path('stubs/client/hooks/config.sh');

    expect(file_exists($core))->toBeTrue();
    expect(file_exists($cfg))->toBeTrue();

    $coreSrc = file_get_contents($core);
    expect($coreSrc)->toContain('rag_ensure_project')
        ->and($coreSrc)->toContain('rag_condense_post')
        ->and($coreSrc)->not->toContain('rag_condense_instruction')
        ->and($coreSrc)->toContain('--max-time');

    $cfgSrc = file_get_contents($cfg);
    expect($cfgSrc)->toContain('__RAG_URL__')
        ->and($cfgSrc)->toContain('__RAG_TOKEN__')
        ->and($cfgSrc)->toContain('RAG_HOOK_INJECT_ON_START="false"');
});
