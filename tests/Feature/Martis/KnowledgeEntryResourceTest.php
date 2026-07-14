<?php

use App\Enums\ImportanceAssessmentStatus;
use App\Enums\ImportanceVerdict;
use App\Enums\KnowledgeSource;
use App\Enums\KnowledgeStatus;
use App\Martis\Resources\KnowledgeEntryResource;
use App\Models\ImportanceAssessment;
use App\Models\KnowledgeEntry;
use App\Models\Project;
use Martis\Fields\Field;

/** @return array<string, mixed> */
function detailValues(KnowledgeEntry $entry): array
{
    $values = [];

    foreach ((new KnowledgeEntryResource($entry))->fieldsForDetail(request()) as $item) {
        $fields = $item instanceof Field ? [$item] : $item->flattenFields();

        foreach ($fields as $field) {
            $values[$field->attribute()] = $field->resolveForDisplay($entry);
        }
    }

    return $values;
}

function classifiedEntry(): KnowledgeEntry
{
    Project::firstOrCreate(['id' => 'r1'], ['name' => 'R1', 'root_path' => '/p']);

    $assessment = ImportanceAssessment::create([
        'project_id' => 'r1',
        'candidate_hash' => str_repeat('a', 64),
        'normalized_candidate' => ['title' => 'secret raw candidate'],
        'model' => 'claude-haiku-4-5-20251001',
        'prompt_version' => 'p1',
        'rules_version' => 'r1',
        'status' => ImportanceAssessmentStatus::Succeeded->value,
        'semantic_score' => 60,
        'final_score' => 82,
        'verdict' => ImportanceVerdict::Important->value,
        'reasons' => [['criterion' => 'durability', 'explanation' => 'Long lived.']],
        'rules' => [['id' => 'has-decision', 'adjustment' => 10, 'reason' => 'Records a decision.']],
        'duration_ms' => 1234,
    ]);

    $entry = KnowledgeEntry::create([
        'project_id' => 'r1',
        'title' => 'Classified entry',
        'status' => KnowledgeStatus::Pending->value,
        'metadata' => [
            'session_id' => 'abc123',
            'importance' => [
                'final_score' => 82,
                'verdict' => ImportanceVerdict::Important->value,
                'mode' => 'enforce',
                'reasons' => [['criterion' => 'durability', 'explanation' => 'Long lived.']],
                'rules' => [['id' => 'has-decision', 'adjustment' => 10, 'reason' => 'Records a decision.']],
                'model' => 'claude-haiku-4-5-20251001',
                'prompt_version' => 'p1',
                'rules_version' => 'r1',
                'candidate_hash' => str_repeat('a', 64),
                'cache_hit' => true,
            ],
        ],
    ]);

    $entry->importance_assessment_id = $assessment->id;
    $entry->save();

    return $entry->refresh();
}

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

    it('never offers classifying as an editable status', function () {
        $resource = new KnowledgeEntryResource;

        $formOptions = collect($resource->fields(request()))
            ->keyBy(fn ($field) => $field->attribute())['status']
            ->toArray()['options'];
        $indexOptions = collect($resource->fieldsForIndex(request()))
            ->keyBy(fn ($field) => $field->attribute())['status']
            ->toArray()['options'];

        // `classifying` is owned by the classifier pipeline: an entry parked there
        // by hand is never dispatched to a job, never indexed and never approvable.
        // The index column is a read-only badge, so it must still be able to
        // *render* the label of an entry in flight — it just cannot be *set*.
        expect(collect($formOptions)->pluck('value')->all())->toBe(['pending', 'approved', 'rejected'])
            ->and(collect($indexOptions)->pluck('value')->all())->toBe(KnowledgeStatus::values())
            ->and(collect($indexOptions)->pluck('value')->all())->toContain('classifying');
    });

    it('shows the true classifying value and marks it immutable when editing a classifying entry', function () {
        Project::create(['id' => 'r1', 'name' => 'R1', 'root_path' => '/p']);
        $entry = KnowledgeEntry::create([
            'project_id' => 'r1',
            'title' => 'Stuck entry',
            'status' => KnowledgeStatus::Classifying->value,
        ]);

        $statusField = collect((new KnowledgeEntryResource($entry))->fields(request()))
            ->keyBy(fn ($field) => $field->attribute())['status']
            ->toArray();

        expect(collect($statusField['options'])->pluck('value')->all())->toBe(KnowledgeStatus::values())
            ->and($statusField['immutable'])->toBeTrue();
    });

    it('refuses to create or update an entry into classifying through the API', function () {
        Project::create(['id' => 'r1', 'name' => 'R1', 'root_path' => '/p']);

        $this->postJson('/martis/api/resources/knowledge-entries', [
            'project_id' => 'r1',
            'title' => 'Stuck entry',
            'status' => KnowledgeStatus::Classifying->value,
        ])->assertUnprocessable()->assertJsonFragment(['field' => 'status', 'code' => 'invalid']);

        $this->assertDatabaseMissing('knowledge_entries', ['status' => KnowledgeStatus::Classifying->value]);

        $entry = KnowledgeEntry::create(['project_id' => 'r1', 'title' => 'Live entry']);

        $this->putJson("/martis/api/resources/knowledge-entries/{$entry->id}", [
            'status' => KnowledgeStatus::Classifying->value,
        ])->assertUnprocessable()->assertJsonFragment(['field' => 'status', 'code' => 'invalid']);

        expect($entry->refresh()->status)->toBe(KnowledgeStatus::Pending->value);
    });

    it('constrains source to the KnowledgeSource enum', function () {
        $options = collect((new KnowledgeEntryResource)->fields(request()))
            ->keyBy(fn ($field) => $field->attribute())['source']
            ->toArray();

        expect($options['type'])->toBe('select')
            ->and(collect($options['options'])->pluck('value')->all())->toBe(KnowledgeSource::values())
            ->and($options['options'][0]['label'])->not->toBe('rag.sources.condense');
    });

    it('refuses a source outside the enum through the API', function () {
        Project::create(['id' => 'r1', 'name' => 'R1', 'root_path' => '/p']);

        $this->postJson('/martis/api/resources/knowledge-entries', [
            'project_id' => 'r1',
            'title' => 'Bogus source',
            'source' => 'telepathy',
        ])->assertUnprocessable()->assertJsonFragment(['field' => 'source', 'code' => 'invalid']);

        $this->assertDatabaseMissing('knowledge_entries', ['title' => 'Bogus source']);
    });

    it('renders relationship fields full width without changing the scalar detail layout', function () {
        $detailItems = collect((new KnowledgeEntryResource)->fieldsForDetail(request()))
            ->map(fn ($item): array => $item->toArray());
        $fields = $detailItems
            ->filter(fn (array $item): bool => $item['type'] !== 'section')
            ->keyBy('attribute');
        $sections = $detailItems->filter(fn (array $item): bool => $item['type'] === 'section')->values();
        $relationships = collect($sections[0]['fields'] ?? [])->keyBy('attribute');

        expect($detailItems)->toHaveCount(10)
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
            ->and($sections[0]['title'])->toBeNull()
            ->and($relationships->keys()->all())->toBe(['tags', 'entities'])
            ->and($relationships['tags']['label'])->toBe(__('rag.fields.tags'))
            ->and($relationships['tags']['colSpan'])->toBe(12)
            ->and($relationships['entities']['label'])->toBe(__('rag.fields.entities'))
            ->and($relationships['entities']['colSpan'])->toBe(12);
    });

    it('shows the importance audit on detail without leaking the raw candidate or process diagnostics', function () {
        $entry = classifiedEntry();

        $values = detailValues($entry);

        expect($values['importance_score'])->toBe(82)
            ->and($values['importance_verdict'])->toBe(__('importance.verdicts.important'))
            ->and($values['importance_mode'])->toBe(__('importance.modes.enforce'))
            ->and($values['importance_reasons'])->toContain('durability', 'Long lived.')
            ->and($values['importance_rules'])->toContain('has-decision', 'Records a decision.')
            ->and($values['importance_model'])->toBe('claude-haiku-4-5-20251001')
            ->and($values['importance_prompt_version'])->toBe('p1')
            ->and($values['importance_rules_version'])->toBe('r1')
            ->and($values['importance_cache'])->toBe(__('importance.audit.cache_hit'))
            ->and($values['importance_error'])->toBeNull();

        // Audit internals: never rendered to an administrator.
        $serialized = json_encode($values, JSON_THROW_ON_ERROR);
        expect(array_keys($values))->not->toContain('normalized_candidate', 'candidate_hash', 'duration_ms')
            ->and($serialized)->not->toContain('secret raw candidate')
            ->and($serialized)->not->toContain('1234')
            ->and($serialized)->not->toContain(str_repeat('a', 64));
    });

    it('shows the classification error of an entry that failed open', function () {
        Project::create(['id' => 'r1', 'name' => 'R1', 'root_path' => '/p']);

        $entry = KnowledgeEntry::create([
            'project_id' => 'r1',
            'title' => 'Failed open entry',
            'status' => KnowledgeStatus::Pending->value,
            'metadata' => [
                'importance' => [
                    'mode' => 'shadow',
                    'classification_error' => [
                        'code' => 'unexpected_error',
                        'message' => 'The importance classifier failed unexpectedly.',
                        'model' => 'claude-haiku-4-5-20251001',
                        'prompt_version' => 'p1',
                        'rules_version' => 'r1',
                    ],
                ],
            ],
        ]);

        $values = detailValues($entry);

        expect($values['importance_error'])->toContain('unexpected_error', 'The importance classifier failed unexpectedly.')
            ->and($values['importance_score'])->toBeNull()
            ->and($values['importance_verdict'])->toBeNull()
            ->and($values['importance_model'])->toBe('claude-haiku-4-5-20251001');
    });

    it('leaves the importance audit empty for an entry that was never classified', function () {
        Project::create(['id' => 'r1', 'name' => 'R1', 'root_path' => '/p']);
        $entry = KnowledgeEntry::create(['project_id' => 'r1', 'title' => 'Manual entry']);

        $values = detailValues($entry);

        expect($values['importance_score'])->toBeNull()
            ->and($values['importance_verdict'])->toBeNull()
            ->and($values['importance_reasons'])->toBeNull()
            ->and($values['importance_rules'])->toBeNull()
            ->and($values['importance_cache'])->toBeNull()
            ->and($values['importance_error'])->toBeNull();
    });

    it('blanks a verdict or mode this build no longer knows instead of failing the detail page', function () {
        Project::create(['id' => 'r1', 'name' => 'R1', 'root_path' => '/p']);

        $entry = KnowledgeEntry::create([
            'project_id' => 'r1',
            'title' => 'Entry from an older prompt version',
            'metadata' => ['importance' => ['verdict' => 'maybe', 'mode' => 'paranoid', 'final_score' => 50]],
        ]);

        $values = detailValues($entry);

        expect($values['importance_verdict'])->toBeNull()
            ->and($values['importance_mode'])->toBeNull()
            ->and($values['importance_score'])->toBe(50);

        $this->getJson("/martis/api/resources/knowledge-entries/{$entry->id}")->assertOk();
    });

    it('serves the detail endpoint of a classified entry, whose metadata is now nested', function () {
        $entry = classifiedEntry();

        $response = $this->getJson("/martis/api/resources/knowledge-entries/{$entry->id}");

        // `metadata.importance` is a nested object; the KeyValue editor only
        // speaks flat key => string, and stringifying an array is a fatal
        // "Array to string conversion" — the detail page of every classified
        // entry would 500.
        $response->assertOk();

        $metadata = collect($response->json('data.metadata'))->pluck('value', 'key');

        expect($metadata->keys()->all())->toBe(['session_id'])
            ->and($metadata['session_id'])->toBe('abc123');
    });

    it('preserves machine-owned metadata when an administrator edits an entry', function () {
        $entry = classifiedEntry();

        $this->putJson("/martis/api/resources/knowledge-entries/{$entry->id}", [
            'metadata' => [['key' => 'session_id', 'value' => 'edited']],
        ])->assertOk();

        $metadata = $entry->refresh()->metadata;

        expect($metadata['session_id'])->toBe('edited')
            ->and($metadata['importance']['final_score'])->toBe(82)
            ->and($metadata['importance']['verdict'])->toBe('important');
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

    it('refuses a PUT that moves a classifying entry to approved, rejected or pending', function () {
        Project::create(['id' => 'r1', 'name' => 'R1', 'root_path' => '/p']);

        foreach ([KnowledgeStatus::Approved, KnowledgeStatus::Rejected, KnowledgeStatus::Pending] as $target) {
            $entry = KnowledgeEntry::create([
                'project_id' => 'r1',
                'title' => 'Stuck entry',
                'status' => KnowledgeStatus::Classifying->value,
            ]);

            $this->putJson("/martis/api/resources/knowledge-entries/{$entry->id}", [
                'status' => $target->value,
            ])->assertForbidden();

            expect($entry->refresh()->status)->toBe(KnowledgeStatus::Classifying->value);
        }
    });

    it('still applies an unrelated field edit to a classifying entry without clobbering its status', function () {
        Project::create(['id' => 'r1', 'name' => 'R1', 'root_path' => '/p']);
        $entry = KnowledgeEntry::create([
            'project_id' => 'r1',
            'title' => 'Tpyo in the title',
            'status' => KnowledgeStatus::Classifying->value,
        ]);

        $this->putJson("/martis/api/resources/knowledge-entries/{$entry->id}", [
            'title' => 'Typo in the title',
        ])->assertOk();

        $entry->refresh();

        expect($entry->title)->toBe('Typo in the title')
            ->and($entry->status)->toBe(KnowledgeStatus::Classifying->value);
    });

    it('accepts the full drawer payload the shipped edit form actually sends for a classifying entry', function () {
        // The real DrawerUpdate seeds every scalar field from the row's raw
        // values (including `status: "classifying"`, which SelectField never
        // normalises) and resubmits all of them on save — not just the ones
        // the admin touched. This is that payload, verbatim, for a title fix.
        Project::create(['id' => 'r1', 'name' => 'R1', 'root_path' => '/p']);
        $entry = KnowledgeEntry::create([
            'project_id' => 'r1',
            'title' => 'Tpyo in the title',
            'category' => 'insight',
            'content' => 'Some content',
            'status' => KnowledgeStatus::Classifying->value,
            'source' => 'manual',
            'author' => 'Agent',
        ]);

        $this->putJson("/martis/api/resources/knowledge-entries/{$entry->id}", [
            'project_id' => 'r1',
            'category' => 'insight',
            'title' => 'Typo in the title',
            'content' => 'Some content',
            'status' => KnowledgeStatus::Classifying->value,
            'source' => 'manual',
            'author' => 'Agent',
        ])->assertOk();

        $entry->refresh();

        expect($entry->title)->toBe('Typo in the title')
            ->and($entry->status)->toBe(KnowledgeStatus::Classifying->value);
    });

    it('keeps normal status transitions working for entries not stuck in classifying', function () {
        Project::create(['id' => 'r1', 'name' => 'R1', 'root_path' => '/p']);

        $transitions = [
            [KnowledgeStatus::Pending, KnowledgeStatus::Approved],
            [KnowledgeStatus::Pending, KnowledgeStatus::Rejected],
            [KnowledgeStatus::Rejected, KnowledgeStatus::Pending],
            [KnowledgeStatus::Approved, KnowledgeStatus::Rejected],
        ];

        foreach ($transitions as [$from, $to]) {
            $entry = KnowledgeEntry::create([
                'project_id' => 'r1',
                'title' => 'Entry '.$from->value.' to '.$to->value,
                'status' => $from->value,
            ]);

            $this->putJson("/martis/api/resources/knowledge-entries/{$entry->id}", [
                'status' => $to->value,
            ])->assertOk();

            expect($entry->refresh()->status)->toBe($to->value);
        }
    });
});
