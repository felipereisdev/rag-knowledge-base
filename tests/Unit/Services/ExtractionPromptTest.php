<?php

use App\Services\Condense\ExtractionPrompt;

it('directs the model to write the entry in the project language', function () {
    $out = (new ExtractionPrompt)->instructions(null, 'pt-BR');

    expect($out)->toContain('Brazilian Portuguese')
        ->and($out)->toContain('pt-BR');
});

it('normalizes the locale separator and casing when naming the language', function () {
    // `projects.language` is a free-text column: the Martis Select writes
    // `pt_PT`, the MCP update tool accepts whatever the caller sends.
    expect((new ExtractionPrompt)->instructions(null, 'pt_PT'))->toContain('European Portuguese');
    expect((new ExtractionPrompt)->instructions(null, 'PT-br'))->toContain('Brazilian Portuguese');
});

it('falls back to English when the project has no language or an unknown one', function () {
    expect((new ExtractionPrompt)->instructions(null, null))->toContain('English');
    expect((new ExtractionPrompt)->instructions(null, 'klingon'))->toContain('English');
});

it('keeps the JSON contract in the base instructions', function () {
    $out = (new ExtractionPrompt)->instructions(null, 'pt-BR');

    expect($out)->toContain('Output ONLY a JSON array')
        ->and($out)->toContain('business-rule');
});

it('keeps the JSON keys and the category vocabulary in English', function () {
    // The parser matches `category` against an English vocabulary and reads
    // fixed English keys, so a translated payload would be silently downgraded
    // to 'insight' or dropped entirely.
    expect((new ExtractionPrompt)->instructions(null, 'pt-BR'))->toContain('must stay in English');
});

it('appends the language directive to a system prompt override too', function () {
    // An override replaces the base instructions wholesale; without this, every
    // operator who customises the prompt silently loses the project language.
    $out = (new ExtractionPrompt)->instructions('Custom operator instructions.', 'es');

    expect($out)->toContain('Custom operator instructions.')
        ->and($out)->toContain('Spanish');
});
