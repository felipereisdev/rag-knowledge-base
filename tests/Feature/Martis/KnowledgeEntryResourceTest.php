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

    it('organizes detail fields into content-first tabs', function () {
        $detail = collect((new KnowledgeEntryResource)->fieldsForDetail(request()))
            ->map(fn ($item): array => $item->toArray());
        $tabGroup = $detail->sole();
        $tabs = collect($tabGroup['tabs'])->keyBy('title');

        expect($tabGroup['type'])->toBe('tab_group')
            ->and($tabs->keys()->all())->toBe([
                __('rag.detail.content'),
                __('rag.detail.context'),
                __('rag.detail.relationships'),
                __('rag.detail.metadata'),
            ])
            ->and(collect($tabs[__('rag.detail.content')]['fields'])->pluck('attribute')->all())
            ->toBe(['status', 'content'])
            ->and(collect($tabs[__('rag.detail.context')]['fields'])->pluck('attribute')->all())
            ->toBe(['project_id', 'category', 'source', 'author', 'created_at'])
            ->and(collect($tabs[__('rag.detail.relationships')]['fields'])->pluck('attribute')->all())
            ->toBe(['tags', 'entities'])
            ->and(collect($tabs[__('rag.detail.metadata')]['fields'])->pluck('attribute')->all())
            ->toBe(['metadata']);
    });

    it('uses the entry title in the Martis detail payload', function () {
        $project = Project::create(['id' => 'rag', 'name' => 'RAG', 'root_path' => '/rag']);
        $entry = KnowledgeEntry::create([
            'project_id' => $project->id,
            'title' => 'Readable knowledge title',
        ]);

        $this->getJson("/martis/api/resources/knowledge-entries/{$entry->id}")
            ->assertOk()
            ->assertJsonPath('data._title', 'Readable knowledge title')
            ->assertJsonPath('data._resource.titleAttribute', 'title');
    });

    it('translates knowledge detail tabs for every supported locale', function () {
        $expected = [
            'en' => ['Content', 'Context', 'Relationships', 'Metadata'],
            'pt_PT' => ['Conteúdo', 'Contexto', 'Relações', 'Metadados'],
            'pt_BR' => ['Conteúdo', 'Contexto', 'Relacionamentos', 'Metadados'],
        ];

        $original = app()->getLocale();

        foreach ($expected as $locale => $labels) {
            app()->setLocale($locale);

            expect([
                __('rag.detail.content'),
                __('rag.detail.context'),
                __('rag.detail.relationships'),
                __('rag.detail.metadata'),
            ])->toBe($labels);
        }

        app()->setLocale($original);
    });

    it('keeps the created date out of the create and update forms', function () {
        $formAttributes = collect((new KnowledgeEntryResource)->fields(request()))
            ->map(fn ($field): array => $field->toArray())
            ->pluck('attribute')
            ->all();

        expect($formAttributes)->not->toContain('created_at');
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
