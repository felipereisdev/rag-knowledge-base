<?php

use App\Support\Rag\PostgresTextSearch;

it('maps project languages to PostgreSQL text search configurations', function (?string $language, string $expected) {
    expect(PostgresTextSearch::configForLanguage($language))->toBe($expected);
})->with([
    'English' => ['en', 'english'],
    'Portuguese' => ['pt', 'portuguese'],
    'Brazilian Portuguese' => ['pt-BR', 'portuguese'],
    'Portuguese with underscore' => ['pt_PT', 'portuguese'],
    'Spanish' => ['es', 'spanish'],
    'unknown language' => ['unknown', 'english'],
    'missing language' => [null, 'english'],
]);
