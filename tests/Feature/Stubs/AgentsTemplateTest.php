<?php
// tests/Feature/Stubs/AgentsTemplateTest.php

it('ships an AGENTS.md RAG section with idempotency markers', function () {
    $f = base_path('stubs/client/AGENTS.rag.md');
    expect(file_exists($f))->toBeTrue();
    $src = file_get_contents($f);
    expect($src)->toContain('<!-- rag:begin -->')
        ->and($src)->toContain('<!-- rag:end -->')
        ->and($src)->toContain('rag_search')
        ->and($src)->toContain('rag_store_knowledge');
});
