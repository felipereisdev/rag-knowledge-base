<?php

use App\Martis\Resources\KnowledgeEntryResource;
use App\Models\KnowledgeEntry;
use App\Models\Project;

describe('KnowledgeEntryResource', function () {
    it('can create an entry with defaults', function () {
        $project = Project::create(['id' => 'r1', 'name' => 'R1', 'root_path' => '/p']);

        $response = $this->post('/martis/api/resources/knowledge-entries', [
            'project_id' => $project->id,
            'title' => 'Test entry',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('knowledge_entries', [
            'project_id' => 'r1',
            'title' => 'Test entry',
            'status' => 'pending',
            'category' => 'insight',
        ]);
    });

    it('rejects invalid category and status values', function () {
        $project = Project::create(['id' => 'r1', 'name' => 'R1', 'root_path' => '/p']);

        $response = $this->post('/martis/api/resources/knowledge-entries', [
            'project_id' => $project->id,
            'title' => 'Invalid entry',
            'category' => 'not-real',
            'status' => 'mystery',
        ]);

        $response->assertUnprocessable()
            ->assertJsonFragment([
                'field' => 'category',
                'code' => 'invalid',
            ])
            ->assertJsonFragment([
                'field' => 'status',
                'code' => 'invalid',
            ]);
    });

    it('normalizes explicit null text values when creating an entry', function () {
        $project = Project::create(['id' => 'r1', 'name' => 'R1', 'root_path' => '/p']);

        $response = $this->postJson('/martis/api/resources/knowledge-entries', [
            'project_id' => $project->id,
            'title' => 'Nullable entry',
            'content' => null,
            'source' => null,
            'author' => null,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('knowledge_entries', [
            'project_id' => 'r1',
            'title' => 'Nullable entry',
            'content' => '',
            'source' => 'manual',
            'author' => '',
        ]);
    });

    it('normalizes explicit null text values when updating an entry', function () {
        $project = Project::create(['id' => 'r1', 'name' => 'R1', 'root_path' => '/p']);
        $entry = KnowledgeEntry::create([
            'project_id' => $project->id,
            'title' => 'Existing entry',
            'content' => 'Existing content',
            'source' => 'import',
            'author' => 'Agent',
        ]);

        $response = $this->putJson("/martis/api/resources/knowledge-entries/{$entry->id}", [
            'content' => null,
            'source' => null,
            'author' => null,
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('knowledge_entries', [
            'id' => $entry->id,
            'content' => '',
            'source' => 'manual',
            'author' => '',
        ]);
    });

    it('serializes translated category and status options with machine values', function () {
        $resource = new KnowledgeEntryResource;
        $formFields = collect($resource->fields(request()))
            ->map(fn ($field): array => $field->toArray())
            ->keyBy('attribute');
        $indexFields = collect($resource->fieldsForIndex(request()))
            ->map(fn ($field): array => $field->toArray())
            ->keyBy('attribute');

        expect($formFields['category']['options'][0])->toBe([
            'label' => 'Business Rule',
            'value' => 'business-rule',
        ])->and($formFields['status']['options'][0])->toBe([
            'label' => 'Pending',
            'value' => 'pending',
        ])->and($indexFields['category']['options'][0])->toBe([
            'label' => 'Business Rule',
            'value' => 'business-rule',
        ])->and($indexFields['status']['options'][0])->toBe([
            'label' => 'Pending',
            'value' => 'pending',
        ]);
    });

    it('renders relationship fields full width without changing the scalar detail layout', function () {
        $detailItems = collect((new KnowledgeEntryResource)->fieldsForDetail(request()))
            ->map(fn ($item): array => $item->toArray());
        $fields = $detailItems
            ->filter(fn (array $item): bool => $item['type'] !== 'section')
            ->keyBy('attribute');
        $section = $detailItems->firstWhere('type', 'section');
        $relationships = collect($section['fields'] ?? [])->keyBy('attribute');

        expect($detailItems)->toHaveCount(9)
            ->and($fields->keys()->all())->toBe([
                'project_id',
                'category',
                'title',
                'content',
                'status',
                'source',
                'author',
                'metadata',
            ])
            ->and($fields['content']['label'])->toBe('Content')
            ->and($section['title'])->toBeNull()
            ->and($relationships->keys()->all())->toBe(['tags', 'entities'])
            ->and($relationships['tags']['label'])->toBe('')
            ->and($relationships['tags']['colSpan'])->toBe(12)
            ->and($relationships['entities']['label'])->toBe('')
            ->and($relationships['entities']['colSpan'])->toBe(12);
    });

    it('can list entries', function () {
        $project = Project::create(['id' => 'r1', 'name' => 'R1', 'root_path' => '/p']);
        KnowledgeEntry::create(['project_id' => $project->id, 'title' => 'E1']);
        KnowledgeEntry::create(['project_id' => $project->id, 'title' => 'E2']);

        $response = $this->get('/martis/api/resources/knowledge-entries');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
    });

    it('can update entry status', function () {
        $project = Project::create(['id' => 'r1', 'name' => 'R1', 'root_path' => '/p']);
        $entry = KnowledgeEntry::create(['project_id' => $project->id, 'title' => 'T']);

        $response = $this->put("/martis/api/resources/knowledge-entries/{$entry->id}", [
            'status' => 'approved',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('knowledge_entries', [
            'id' => $entry->id,
            'status' => 'approved',
        ]);
    });
});
