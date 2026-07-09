<?php
// tests/Feature/Stubs/RepoInstructionsTest.php

it('this repo ships the using-rag skill and hooks-aware AGENTS section', function () {
    expect(file_exists(base_path('.claude/skills/using-rag/SKILL.md')))->toBeTrue();

    $agents = file_get_contents(base_path('AGENTS.md'));
    expect($agents)->toContain('rag_search')->and($agents)->toContain('rag_store_knowledge');
});
