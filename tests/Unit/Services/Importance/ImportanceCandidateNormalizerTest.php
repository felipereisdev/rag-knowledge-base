<?php

use App\Enums\KnowledgeSource;
use App\Services\Importance\ImportanceCandidate;
use App\Services\Importance\ImportanceCandidateNormalizer;

it('canonicalizes every candidate field without losing meaningful text', function () {
    $candidate = new ImportanceCandidate(
        title: ' Preserve API punctuation: v1.0! ',
        content: "Line one.\r\n\r\nLine two: keep THIS case!",
        category: 'architecture',
        source: KnowledgeSource::Mcp,
        tags: ['zebra', 'alpha'],
        entities: [
            ['name' => 'Zeta', 'type' => 'service'],
            ['name' => 'Alpha', 'type' => 'class'],
        ],
        relations: [
            ['subject' => 'Zeta', 'predicate' => 'calls', 'object' => 'Alpha'],
            ['subject' => 'Alpha', 'predicate' => 'depends_on', 'object' => 'Zeta'],
        ],
    );

    $normalized = app(ImportanceCandidateNormalizer::class)->normalize($candidate);

    expect($normalized->data())->toBe([
        'title' => 'Preserve API punctuation: v1.0!',
        'content' => "Line one.\n\nLine two: keep THIS case!",
        'category' => 'architecture',
        'source' => 'mcp',
        'tags' => ['alpha', 'zebra'],
        'entities' => [
            ['name' => 'Alpha', 'type' => 'class'],
            ['name' => 'Zeta', 'type' => 'service'],
        ],
        'relations' => [
            ['subject' => 'Alpha', 'predicate' => 'depends_on', 'object' => 'Zeta'],
            ['subject' => 'Zeta', 'predicate' => 'calls', 'object' => 'Alpha'],
        ],
    ]);
});

it('uses the same JSON and hash when unordered collections are reordered', function () {
    $first = new ImportanceCandidate(
        title: 'Stable title',
        content: 'Keep this durable decision.',
        category: 'design-decision',
        source: 'cli',
        tags: ['beta', 'alpha'],
        entities: [['name' => 'B', 'type' => 'class'], ['name' => 'A', 'type' => 'class']],
        relations: [['subject' => 'B', 'predicate' => 'uses', 'object' => 'A']],
    );
    $second = new ImportanceCandidate(
        title: 'Stable title',
        content: 'Keep this durable decision.',
        category: 'design-decision',
        source: 'cli',
        tags: ['alpha', 'beta'],
        entities: [['name' => 'A', 'type' => 'class'], ['name' => 'B', 'type' => 'class']],
        relations: [['object' => 'A', 'predicate' => 'uses', 'subject' => 'B']],
    );

    $normalizer = app(ImportanceCandidateNormalizer::class);

    expect($normalizer->normalize($first)->json())
        ->toBe($normalizer->normalize($second)->json())
        ->and($normalizer->normalize($first)->hash())
        ->toBe($normalizer->normalize($second)->hash())
        ->and($normalizer->normalize($first)->hash())
        ->toHaveLength(64);
});

it('changes the hash when relevant knowledge changes', function () {
    $normalizer = app(ImportanceCandidateNormalizer::class);
    $base = new ImportanceCandidate('Decision', 'Use PostgreSQL.', 'architecture', 'condense');
    $changed = new ImportanceCandidate('Decision', 'Use PostgreSQL with pgvector.', 'architecture', 'condense');

    expect($normalizer->normalize($base)->hash())
        ->not->toBe($normalizer->normalize($changed)->hash());
});
