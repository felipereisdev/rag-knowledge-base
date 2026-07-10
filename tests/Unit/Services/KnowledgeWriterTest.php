<?php

use App\Models\Entity;
use App\Models\KnowledgeEntry;
use App\Models\Project;
use App\Models\Relation;
use App\Services\Knowledge\KnowledgeWriter;

beforeEach(function () {
    Project::create(['id' => 'p1', 'name' => 'p1', 'root_path' => '/tmp/p1']);
});

it('creates a pending entry with tags, entities and relations', function () {
    $writer = app(KnowledgeWriter::class);

    $entry = $writer->store(
        projectId: 'p1',
        title: 'Use pgvector for search',
        content: '# note',
        category: 'design-decision',
        source: 'condense',
        tags: ['search', 'db'],
        entities: [['name' => 'pgvector', 'type' => 'library'], ['name' => 'HybridSearcher', 'type' => 'class']],
        relations: [['subject' => 'HybridSearcher', 'predicate' => 'uses', 'object' => 'pgvector']],
    );

    expect($entry->status)->toBe('pending');
    expect($entry->source)->toBe('condense');
    expect($entry->tags()->count())->toBe(2);
    expect($entry->entities()->count())->toBe(2);
    expect(Relation::where('entry_id', $entry->id)->count())->toBe(1);
    expect(Entity::where('project_id', 'p1')->where('name', 'pgvector')->exists())->toBeTrue();
    expect(KnowledgeEntry::where('project_id', 'p1')->where('status', 'pending')->count())->toBe(1);
});

it('reuses an existing entity for relations referenced by name', function () {
    Entity::create(['project_id' => 'p1', 'name' => 'HybridSearcher', 'type' => 'class']);
    $writer = app(KnowledgeWriter::class);

    $writer->store('p1', 't', 'c', 'insight', 'condense',
        relations: [['subject' => 'HybridSearcher', 'predicate' => 'uses', 'object' => 'pgvector']]);

    // No duplicate typed entity created; a bare 'pgvector' placeholder is added.
    expect(Entity::where('project_id', 'p1')->where('name', 'HybridSearcher')->count())->toBe(1);
    expect(Entity::where('project_id', 'p1')->where('name', 'pgvector')->count())->toBe(1);
});
