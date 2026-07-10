<?php

use App\Services\Condense\CandidateParser;

it('parses a JSON array, tolerating code fences and surrounding prose', function () {
    $raw = "Here you go:\n```json\n".json_encode([
        ['title' => 'A', 'content' => '# a', 'category' => 'design-decision',
         'entities' => [['name' => 'X', 'type' => 'class']],
         'relations' => [['subject' => 'X', 'predicate' => 'uses', 'object' => 'Y']]],
        ['title' => '', 'content' => 'dropped: no title'],
    ])."\n```";

    $out = app(CandidateParser::class)->parse($raw);

    expect($out)->toHaveCount(1);
    expect($out[0]['title'])->toBe('A');
    expect($out[0]['category'])->toBe('design-decision');
    expect($out[0]['entities'][0]['name'])->toBe('X');
    expect($out[0]['relations'][0]['predicate'])->toBe('uses');
});

it('coerces an unknown/absent category to insight and drops malformed graph items', function () {
    $raw = json_encode([
        ['title' => 'T', 'content' => 'c', 'category' => 'decision', // not in chk_category -> insight
         'entities' => [['type' => 'class']], // no name -> dropped
         'relations' => [['subject' => 'X', 'object' => 'Y']]], // no predicate -> dropped
    ]);

    $out = app(CandidateParser::class)->parse($raw);

    expect($out[0]['category'])->toBe('insight');
    expect($out[0]['entities'])->toBe([]);
    expect($out[0]['relations'])->toBe([]);
});

it('returns empty array for non-JSON or empty output', function () {
    expect(app(CandidateParser::class)->parse('nothing here'))->toBe([]);
    expect(app(CandidateParser::class)->parse('[]'))->toBe([]);
});
