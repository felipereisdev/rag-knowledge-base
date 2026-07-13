<?php

use App\Models\Entity;
use App\Models\KnowledgeEntry;
use App\Models\Project;
use App\Models\Relation;
use App\Services\Search\HybridSearcher;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\EmbeddingsPrompt;

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

    it('uses the configured embedding provider', function () {
        config([
            'rag.embeddings.provider' => 'custom-embedder',
            'ai.providers.custom-embedder' => config('ai.providers.local-embedder'),
        ]);

        $entry = KnowledgeEntry::create([
            'project_id' => $this->project->id,
            'title' => 'Configured provider',
            'content' => 'Search uses the configured embedding provider.',
            'status' => 'approved',
        ]);
        DB::table('chunk_embeddings')->insert([
            'entry_id' => $entry->id,
            'project_id' => $this->project->id,
            'chunk_index' => 0,
            'content' => 'Search uses the configured embedding provider.',
            'embedding' => '['.implode(',', $this->fakeVector).']',
        ]);

        $results = (new HybridSearcher)->search('configured provider', $this->project->id);

        expect($results)->not->toBeEmpty();
        Embeddings::assertGenerated(
            fn (EmbeddingsPrompt $prompt): bool => $prompt->provider->name() === 'custom-embedder',
        );
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

    it('uses the project language for keyword search', function () {
        DB::table('projects')
            ->where('id', $this->project->id)
            ->update(['language' => 'pt']);

        $entry = KnowledgeEntry::create([
            'project_id' => $this->project->id,
            'title' => 'Rotas portuguesas',
            'content' => 'As migrações ficam na pasta database.',
            'status' => 'approved',
        ]);

        $results = (new HybridSearcher(expandGraph: false))->search('ficar', $this->project->id);

        expect($results)->not->toBeEmpty()
            ->and($results[0]->entryId)->toBe($entry->id)
            ->and($results[0]->matchedBy)->toContain('keyword');
    });

    it('rebuilds keyword vectors when the project language changes', function () {
        $entry = KnowledgeEntry::create([
            'project_id' => $this->project->id,
            'title' => 'Rotas portuguesas',
            'content' => 'As migrações ficam na pasta database.',
            'status' => 'approved',
        ]);

        DB::table('projects')
            ->where('id', $this->project->id)
            ->update(['language' => 'pt']);

        $results = (new HybridSearcher(expandGraph: false))->search('ficar', $this->project->id);

        expect($results)->not->toBeEmpty()
            ->and($results[0]->entryId)->toBe($entry->id)
            ->and($results[0]->matchedBy)->toContain('keyword');
    });

    it('uses English for prefix-like unknown project languages', function () {
        $this->project->update(['language' => 'ptfoo']);

        $entry = KnowledgeEntry::create([
            'project_id' => $this->project->id,
            'title' => 'Rotas portuguesas',
            'content' => 'As migrações ficam na pasta database.',
            'status' => 'approved',
        ]);

        $results = (new HybridSearcher(expandGraph: false))->search('ficam', $this->project->id);

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

    it('filters non-approved entries before applying vector top k', function () {
        $queryVector = array_fill(0, 768, 0.0);
        $queryVector[0] = 1.0;
        Embeddings::fake([[$queryVector]]);

        $pending = KnowledgeEntry::create([
            'project_id' => $this->project->id,
            'title' => 'Pending nearest entry',
            'content' => 'Pending content.',
            'status' => 'pending',
        ]);
        $approved = KnowledgeEntry::create([
            'project_id' => $this->project->id,
            'title' => 'Approved second-nearest entry',
            'content' => 'Approved content.',
            'status' => 'approved',
        ]);

        $approvedVector = array_fill(0, 768, 0.0);
        $approvedVector[0] = 0.8;
        $approvedVector[1] = 0.6;

        DB::table('chunk_embeddings')->insert([
            [
                'entry_id' => $pending->id,
                'project_id' => $this->project->id,
                'chunk_index' => 0,
                'content' => 'Pending content.',
                'embedding' => '['.implode(',', $queryVector).']',
            ],
            [
                'entry_id' => $approved->id,
                'project_id' => $this->project->id,
                'chunk_index' => 0,
                'content' => 'Approved content.',
                'embedding' => '['.implode(',', $approvedVector).']',
            ],
        ]);

        $results = (new HybridSearcher(
            limit: 1,
            minScore: 0.3,
            expandGraph: false,
            vectorTopK: 1,
        ))->search('vectoronlyterm', $this->project->id);

        expect($results)->toHaveCount(1)
            ->and($results[0]->entryId)->toBe($approved->id);
    });

    it('applies vector top k to entries rather than chunks', function () {
        $queryVector = array_fill(0, 768, 0.0);
        $queryVector[0] = 1.0;
        Embeddings::fake([[$queryVector]]);

        $first = KnowledgeEntry::create([
            'project_id' => $this->project->id,
            'title' => 'Entry with two close chunks',
            'content' => 'First entry.',
            'status' => 'approved',
        ]);
        $second = KnowledgeEntry::create([
            'project_id' => $this->project->id,
            'title' => 'Entry with one useful chunk',
            'content' => 'Second entry.',
            'status' => 'approved',
        ]);

        $almostIdentical = array_fill(0, 768, 0.0);
        $almostIdentical[0] = 0.99;
        $almostIdentical[1] = 0.1;
        $secondVector = array_fill(0, 768, 0.0);
        $secondVector[0] = 0.8;
        $secondVector[1] = 0.6;

        DB::table('chunk_embeddings')->insert([
            [
                'entry_id' => $first->id,
                'project_id' => $this->project->id,
                'chunk_index' => 0,
                'content' => 'Closest chunk.',
                'embedding' => '['.implode(',', $queryVector).']',
            ],
            [
                'entry_id' => $first->id,
                'project_id' => $this->project->id,
                'chunk_index' => 1,
                'content' => 'Almost closest chunk.',
                'embedding' => '['.implode(',', $almostIdentical).']',
            ],
            [
                'entry_id' => $second->id,
                'project_id' => $this->project->id,
                'chunk_index' => 0,
                'content' => 'Useful chunk from a different entry.',
                'embedding' => '['.implode(',', $secondVector).']',
            ],
        ]);

        $results = (new HybridSearcher(
            limit: 2,
            minScore: 0.3,
            expandGraph: false,
            vectorTopK: 2,
        ))->search('vectoronlyterm', $this->project->id);

        expect(array_map(fn ($result) => $result->entryId, $results))
            ->toBe([$first->id, $second->id]);
    });

    it('does not let a zero vector consume the vector top k limit', function () {
        $queryVector = array_fill(0, 768, 0.0);
        $queryVector[0] = 1.0;
        Embeddings::fake([[$queryVector]]);

        $zeroVectorEntry = KnowledgeEntry::create([
            'project_id' => $this->project->id,
            'title' => 'Zero vector entry',
            'content' => 'This vector has no magnitude.',
            'status' => 'approved',
        ]);
        $validEntry = KnowledgeEntry::create([
            'project_id' => $this->project->id,
            'title' => 'Valid vector entry',
            'content' => 'This vector has finite similarity.',
            'status' => 'approved',
        ]);

        $zeroVector = array_fill(0, 768, 0.0);
        $validVector = array_fill(0, 768, 0.0);
        $validVector[0] = 0.8;
        $validVector[1] = 0.6;

        DB::table('chunk_embeddings')->insert([
            [
                'entry_id' => $zeroVectorEntry->id,
                'project_id' => $this->project->id,
                'chunk_index' => 0,
                'content' => 'This vector has no magnitude.',
                'embedding' => '['.implode(',', $zeroVector).']',
            ],
            [
                'entry_id' => $validEntry->id,
                'project_id' => $this->project->id,
                'chunk_index' => 0,
                'content' => 'This vector has finite similarity.',
                'embedding' => '['.implode(',', $validVector).']',
            ],
        ]);

        $results = (new HybridSearcher(
            limit: 1,
            minScore: 0.3,
            expandGraph: false,
            vectorTopK: 1,
        ))->search('vectoronlyterm', $this->project->id);

        expect($results)->toHaveCount(1)
            ->and($results[0]->entryId)->toBe($validEntry->id)
            ->and($results[0]->matchedBy)->toContain('vector');
    });

    it('adaptively over-fetches when one entry crowds the initial candidate window', function () {
        $queryVector = array_fill(0, 768, 0.0);
        $queryVector[0] = 1.0;
        Embeddings::fake([[$queryVector]]);

        $crowdingEntry = KnowledgeEntry::create([
            'project_id' => $this->project->id,
            'title' => 'Entry with many closest chunks',
            'content' => 'This entry crowds the initial candidate window.',
            'status' => 'approved',
        ]);
        $nextEntry = KnowledgeEntry::create([
            'project_id' => $this->project->id,
            'title' => 'Next unique entry',
            'content' => 'This entry appears after the crowded window.',
            'status' => 'approved',
        ]);

        $crowdingChunks = [];
        for ($chunkIndex = 0; $chunkIndex < 9; $chunkIndex++) {
            $crowdingChunks[] = [
                'entry_id' => $crowdingEntry->id,
                'project_id' => $this->project->id,
                'chunk_index' => $chunkIndex,
                'content' => "Crowding chunk {$chunkIndex}.",
                'embedding' => '['.implode(',', $queryVector).']',
            ];
        }

        $nextVector = array_fill(0, 768, 0.0);
        $nextVector[0] = 0.8;
        $nextVector[1] = 0.6;
        $crowdingChunks[] = [
            'entry_id' => $nextEntry->id,
            'project_id' => $this->project->id,
            'chunk_index' => 0,
            'content' => 'The next unique candidate.',
            'embedding' => '['.implode(',', $nextVector).']',
        ];

        DB::table('chunk_embeddings')->insert($crowdingChunks);

        $results = (new HybridSearcher(
            limit: 2,
            minScore: 0.3,
            expandGraph: false,
            vectorTopK: 2,
        ))->search('vectoronlyterm', $this->project->id);

        expect(array_map(fn ($result) => $result->entryId, $results))
            ->toBe([$crowdingEntry->id, $nextEntry->id]);
    });

    it('uses the hnsw index for vector candidate ordering', function () {
        $queryVector = array_fill(0, 768, 0.0);
        $queryVector[0] = 1.0;
        Embeddings::fake([[$queryVector]]);

        $entry = KnowledgeEntry::create([
            'project_id' => $this->project->id,
            'title' => 'HNSW candidate',
            'content' => 'The vector query should use the HNSW index.',
            'status' => 'approved',
        ]);
        DB::table('chunk_embeddings')->insert([
            'entry_id' => $entry->id,
            'project_id' => $this->project->id,
            'chunk_index' => 0,
            'content' => 'The vector query should use the HNSW index.',
            'embedding' => '['.implode(',', $queryVector).']',
        ]);

        $vectorQuery = null;
        DB::listen(function (QueryExecuted $query) use (&$vectorQuery): void {
            if ($vectorQuery === null && str_contains($query->sql, 'chunk_embeddings ce')) {
                $vectorQuery = $query;
            }
        });

        (new HybridSearcher(
            minScore: 0.3,
            expandGraph: false,
            vectorTopK: 1,
        ))->search('vectoronlyterm', $this->project->id);

        expect($vectorQuery)->not->toBeNull();

        $planRows = DB::transaction(function () use ($vectorQuery): array {
            DB::statement('SET LOCAL enable_seqscan = off');

            return DB::select('EXPLAIN (COSTS OFF) '.$vectorQuery->sql, $vectorQuery->bindings);
        });
        $plan = implode("\n", array_map(
            fn (object $row): string => (string) ((array) $row)['QUERY PLAN'],
            $planRows,
        ));

        expect($plan)->toContain('idx_chunk_embeddings_vector');
    });

    it('completes hnsw boundary ties before deterministic entry ordering', function () {
        $queryVector = array_fill(0, 768, 0.0);
        $queryVector[0] = 1.0;
        Embeddings::fake([[$queryVector]]);

        $lowerIdEntry = KnowledgeEntry::create([
            'project_id' => $this->project->id,
            'title' => 'Lower ID tied entry',
            'content' => 'This tied entry should sort first by entry ID.',
            'status' => 'approved',
        ]);
        $crowdingEntry = KnowledgeEntry::create([
            'project_id' => $this->project->id,
            'title' => 'Earlier indexed tied chunks',
            'content' => 'These chunks fill the initial ANN boundary.',
            'status' => 'approved',
        ]);

        $chunks = [];
        for ($chunkIndex = 0; $chunkIndex < 8; $chunkIndex++) {
            $chunks[] = [
                'entry_id' => $crowdingEntry->id,
                'project_id' => $this->project->id,
                'chunk_index' => $chunkIndex,
                'content' => "Earlier indexed tied chunk {$chunkIndex}.",
                'embedding' => '['.implode(',', $queryVector).']',
            ];
        }
        $chunks[] = [
            'entry_id' => $lowerIdEntry->id,
            'project_id' => $this->project->id,
            'chunk_index' => 0,
            'content' => 'Later indexed but lower ID tied chunk.',
            'embedding' => '['.implode(',', $queryVector).']',
        ];
        DB::table('chunk_embeddings')->insert($chunks);

        $results = (new HybridSearcher(
            limit: 1,
            minScore: 0.3,
            expandGraph: false,
            vectorTopK: 1,
        ))->search('vectoronlyterm', $this->project->id);

        expect($results)->toHaveCount(1)
            ->and($results[0]->entryId)->toBe($lowerIdEntry->id);
    });

    it('falls back when out-of-project candidates exhaust the hnsw scan horizon', function () {
        DB::statement('SET LOCAL hnsw.ef_search = 1');
        DB::statement('SET LOCAL hnsw.max_scan_tuples = 1');

        $queryVector = array_fill(0, 768, 0.0);
        $queryVector[0] = 1.0;
        Embeddings::fake([[$queryVector]]);

        $otherProject = Project::create([
            'id' => 'r2',
            'name' => 'R2',
            'root_path' => '/other-project',
        ]);
        $excludedEntry = KnowledgeEntry::create([
            'project_id' => $otherProject->id,
            'title' => 'Excluded nearest chunks',
            'content' => 'These chunks exhaust the filtered ANN scan horizon.',
            'status' => 'approved',
        ]);
        $eligibleEntry = KnowledgeEntry::create([
            'project_id' => $this->project->id,
            'title' => 'Eligible candidate beyond the horizon',
            'content' => 'Exact fallback must recover this entry.',
            'status' => 'approved',
        ]);

        $queryEmbedding = '['.implode(',', $queryVector).']';
        for ($batchStart = 0; $batchStart < 50000; $batchStart += 1000) {
            $chunks = [];
            for ($chunkIndex = $batchStart; $chunkIndex < $batchStart + 1000; $chunkIndex++) {
                $chunks[] = [
                    'entry_id' => $excludedEntry->id,
                    'project_id' => $otherProject->id,
                    'chunk_index' => $chunkIndex,
                    'content' => "Excluded nearest chunk {$chunkIndex}.",
                    'embedding' => $queryEmbedding,
                ];
            }
            DB::table('chunk_embeddings')->insert($chunks);
        }

        $eligibleVector = array_fill(0, 768, 0.0);
        $eligibleVector[0] = 0.98;
        $eligibleVector[1] = 0.2;
        DB::table('chunk_embeddings')->insert([
            'entry_id' => $eligibleEntry->id,
            'project_id' => $this->project->id,
            'chunk_index' => 0,
            'content' => 'Eligible candidate beyond the horizon.',
            'embedding' => '['.implode(',', $eligibleVector).']',
        ]);

        $results = (new HybridSearcher(
            limit: 1,
            minScore: 0.3,
            expandGraph: false,
            vectorTopK: 1,
        ))->search('vectoronlyterm', $this->project->id);

        expect($results)->toHaveCount(1)
            ->and($results[0]->entryId)->toBe($eligibleEntry->id);
    });
});
