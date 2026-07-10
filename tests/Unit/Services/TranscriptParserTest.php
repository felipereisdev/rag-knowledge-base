<?php
// tests/Unit/Services/TranscriptParserTest.php

use App\Services\Condense\TranscriptParser;

function writeTranscript(array $lines): string
{
    $path = tempnam(sys_get_temp_dir(), 'tr').'.jsonl';
    file_put_contents($path, implode("\n", array_map('json_encode', $lines))."\n");

    return $path;
}

it('extracts user and assistant text, skipping tool noise', function () {
    $path = writeTranscript([
        ['type' => 'user', 'message' => ['role' => 'user', 'content' => 'Fix the bug']],
        ['type' => 'assistant', 'message' => ['role' => 'assistant', 'content' => [
            ['type' => 'text', 'text' => 'I will use pgvector.'],
            ['type' => 'tool_use', 'name' => 'Bash', 'input' => ['command' => 'ls']],
        ]]],
        ['type' => 'user', 'message' => ['role' => 'user', 'content' => [
            ['type' => 'tool_result', 'content' => 'file.txt'],
        ]]],
    ]);

    $out = app(TranscriptParser::class)->parse($path, 10000);

    expect($out)->toContain('USER: Fix the bug');
    expect($out)->toContain('ASSISTANT: I will use pgvector.');
    expect($out)->not->toContain('ls');
    expect($out)->not->toContain('file.txt');

    @unlink($path);
});

it('returns empty string for a missing file', function () {
    expect(app(TranscriptParser::class)->parse('/no/such/file.jsonl', 100))->toBe('');
});

it('keeps the tail when truncating', function () {
    $path = writeTranscript([
        ['type' => 'assistant', 'message' => ['role' => 'assistant', 'content' => str_repeat('A', 500)]],
        ['type' => 'assistant', 'message' => ['role' => 'assistant', 'content' => 'TAIL_MARKER']],
    ]);

    $out = app(TranscriptParser::class)->parse($path, 50);

    expect(mb_strlen($out))->toBeLessThanOrEqual(50);
    expect($out)->toContain('TAIL_MARKER');

    @unlink($path);
});
