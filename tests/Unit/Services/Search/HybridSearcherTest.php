<?php

use App\Models\Entity;
use App\Models\KnowledgeEntry;
use App\Models\Project;
use App\Models\Relation;
use App\Services\Search\HybridSearcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Embeddings;

describe('HybridSearcher', function () {
    beforeEach(function () {
        // Prevent IndexEntryJob from running synchronously when entries are created
        Queue::fake();

        $this->project = Project::create(['id' => 'r1', 'name' => 'R1', 'root_path' => '/p']);
        $this->fakeVector = array_fill(0, 768, 0.1);

        // Mock the Embeddings static method
        Embeddings::fake([[$this->fakeVector]]);
    });

    it('finds entries by vector match', function () {
        $entry = KnowledgeEntry::create([
            'project_id' => $this->project->id,
            'title' => 'Laravel routing',
            'content' => 'Routes are defined in routes/web.php',
            'status' => 'approved',
        ]);
        DB::table('chunk_embeddings')->insert([
            'entry_id' => $entry->id,
            'project_id' => $this->project->id,
            'chunk_index' => 0,
            'content' => 'Routes are defined in routes/web.php',
            'embedding' => '['.implode(',', $this->fakeVector).']',
        ]);

        $searcher = new HybridSearcher;
        $results = $searcher->search('routing', $this->project->id);

        expect($results)->not->toBeEmpty()
            ->and($results[0]->entryId)->toBe($entry->id)
            ->and($results[0]->matchedBy)->toContain('vector');
    });

    it('finds entries by keyword match', function () {
        $entry = KnowledgeEntry::create([
            'project_id' => $this->project->id,
            'title' => 'Database migrations',
            'content' => 'Migrations are stored in database/migrations',
            'status' => 'approved',
        ]);

        $searcher = new HybridSearcher;
        $results = $searcher->search('database migrations', $this->project->id);

        expect($results)->not->toBeEmpty()
            ->and($results[0]->entryId)->toBe($entry->id)
            ->and($results[0]->matchedBy)->toContain('keyword');
    });

    it('combines vector and keyword via RRF', function () {
        $entry = KnowledgeEntry::create([
            'project_id' => $this->project->id,
            'title' => 'Eloquent models',
            'content' => 'Eloquent is the ORM',
            'status' => 'approved',
        ]);
        DB::table('chunk_embeddings')->insert([
            'entry_id' => $entry->id,
            'project_id' => $this->project->id,
            'chunk_index' => 0,
            'content' => 'Eloquent is the ORM',
            'embedding' => '['.implode(',', $this->fakeVector).']',
        ]);

        $searcher = new HybridSearcher;
        $results = $searcher->search('eloquent', $this->project->id);

        expect($results)->not->toBeEmpty()
            ->and($results[0]->matchedBy)->toContain('vector')
            ->and($results[0]->matchedBy)->toContain('keyword');
    });

    it('respects min_score filter', function () {
        $entry = KnowledgeEntry::create([
            'project_id' => $this->project->id,
            'title' => 'Unrelated',
            'content' => 'xyz123',
            'status' => 'approved',
        ]);
        // Insert a chunk with a very different vector (all zeros)
        $zeroVector = array_fill(0, 768, 0.0);
        DB::table('chunk_embeddings')->insert([
            'entry_id' => $entry->id,
            'project_id' => $this->project->id,
            'chunk_index' => 0,
            'content' => 'xyz123',
            'embedding' => '['.implode(',', $zeroVector).']',
        ]);

        $searcher = new HybridSearcher(minScore: 0.99);
        $results = $searcher->search('test', $this->project->id);

        expect($results)->toBeEmpty();
    });

    it('expands results via graph', function () {
        $entry1 = KnowledgeEntry::create([
            'project_id' => $this->project->id,
            'title' => 'Main entry',
            'content' => 'About Laravel',
            'status' => 'approved',
        ]);
        $entry2 = KnowledgeEntry::create([
            'project_id' => $this->project->id,
            'title' => 'Related entry',
            'content' => 'About PHP',
            'status' => 'approved',
        ]);

        $entity1 = Entity::create(['project_id' => $this->project->id, 'name' => 'Laravel']);
        $entity2 = Entity::create(['project_id' => $this->project->id, 'name' => 'PHP']);
        DB::table('entry_entities')->insert([
            ['entry_id' => $entry1->id, 'entity_id' => $entity1->id],
            ['entry_id' => $entry2->id, 'entity_id' => $entity2->id],
        ]);
        Relation::create([
            'project_id' => $this->project->id,
            'subject_id' => $entity1->id,
            'predicate' => 'related_to',
            'object_id' => $entity2->id,
        ]);

        // Only entry1 has a vector match
        DB::table('chunk_embeddings')->insert([
            'entry_id' => $entry1->id,
            'project_id' => $this->project->id,
            'chunk_index' => 0,
            'content' => 'About Laravel',
            'embedding' => '['.implode(',', $this->fakeVector).']',
        ]);

        $searcher = new HybridSearcher(expandGraph: true);
        $results = $searcher->search('laravel', $this->project->id);

        $entryIds = array_map(fn ($r) => $r->entryId, $results);
        expect($entryIds)->toContain($entry1->id)
            ->and($entryIds)->toContain($entry2->id);
    });

    it('returns results without crashing when expand_graph is false', function () {
        $entry = KnowledgeEntry::create([
            'project_id' => $this->project->id,
            'title' => 'No graph expansion',
            'content' => 'Routes are defined in routes/web.php',
            'status' => 'approved',
        ]);
        DB::table('chunk_embeddings')->insert([
            'entry_id' => $entry->id,
            'project_id' => $this->project->id,
            'chunk_index' => 0,
            'content' => 'Routes are defined in routes/web.php',
            'embedding' => '['.implode(',', $this->fakeVector).']',
        ]);

        $searcher = new HybridSearcher(minScore: 0.2, expandGraph: false);
        $results = $searcher->search('routing', $this->project->id);

        expect($results)->not->toBeEmpty()
            ->and($results[0]->entryId)->toBe($entry->id)
            ->and($results[0]->graphExpanded)->toBeFalse();
    });

    it('normalizes fused scores so a top hit scores near 1.0, not the raw RRF ceiling', function () {
        // Entry matched by BOTH vector and keyword at rank 0. The raw RRF score
        // for that case is 2/rrfK ≈ 0.033; after normalization it must land
        // near 1.0 (well above the documented ~0.65 relevance calibration and
        // the default 0.30 min_score), not at the tiny raw ceiling.
        $entry = KnowledgeEntry::create([
            'project_id' => $this->project->id,
            'title' => 'Eloquent models',
            'content' => 'Eloquent is the ORM',
            'status' => 'approved',
        ]);
        DB::table('chunk_embeddings')->insert([
            'entry_id' => $entry->id,
            'project_id' => $this->project->id,
            'chunk_index' => 0,
            'content' => 'Eloquent is the ORM',
            'embedding' => '['.implode(',', $this->fakeVector).']',
        ]);

        $searcher = new HybridSearcher(minScore: 0.2, expandGraph: false);
        $results = $searcher->search('eloquent', $this->project->id);

        expect($results)->not->toBeEmpty()
            ->and($results[0]->matchedBy)->toContain('vector')
            ->and($results[0]->matchedBy)->toContain('keyword')
            ->and($results[0]->score)->toBeGreaterThanOrEqual(0.65)
            ->and($results[0]->score)->toBeLessThanOrEqual(1.0);
    });

    it('returns empty results for no matches', function () {
        $searcher = new HybridSearcher;
        $results = $searcher->search('nonexistent query xyz', $this->project->id);

        expect($results)->toBeEmpty();
    });

    it('respects limit', function () {
        for ($i = 0; $i < 5; $i++) {
            $entry = KnowledgeEntry::create([
                'project_id' => $this->project->id,
                'title' => "Entry $i",
                'content' => 'common content',
                'status' => 'approved',
            ]);
            DB::table('chunk_embeddings')->insert([
                'entry_id' => $entry->id,
                'project_id' => $this->project->id,
                'chunk_index' => 0,
                'content' => 'common content',
                'embedding' => '['.implode(',', $this->fakeVector).']',
            ]);
        }

        $searcher = new HybridSearcher(limit: 3);
        $results = $searcher->search('common', $this->project->id);

        expect($results)->toHaveCount(3);
    });

    it('filters by category', function () {
        $entry1 = KnowledgeEntry::create([
            'project_id' => $this->project->id,
            'title' => 'Rule A',
            'content' => 'business rule content',
            'category' => 'business-rule',
            'status' => 'approved',
        ]);
        $entry2 = KnowledgeEntry::create([
            'project_id' => $this->project->id,
            'title' => 'Insight B',
            'content' => 'business rule content',
            'category' => 'insight',
            'status' => 'approved',
        ]);

        $searcher = new HybridSearcher;
        $results = $searcher->search('business rule', $this->project->id, category: 'business-rule');

        $entryIds = array_map(fn ($r) => $r->entryId, $results);
        expect($entryIds)->toContain($entry1->id)
            ->and($entryIds)->not->toContain($entry2->id);
    });
});
