<?php

use App\Models\KnowledgeEntry;
use App\Models\Project;
use App\Services\Chunking\ParagraphChunker;
use App\Services\Indexing\EntryIndexer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\EmbeddingsPrompt;

describe('EntryIndexer', function () {
    it('chunks and indexes an entry', function () {
        $project = Project::create(['id' => 'r1', 'name' => 'R1', 'root_path' => '/p']);
        $entry = KnowledgeEntry::create([
            'project_id' => $project->id,
            'title' => 'Test',
            'content' => "First paragraph.\n\nSecond paragraph.",
        ]);

        // The SDK's fake accepts a list of responses; each response is a list of
        // vectors (array<float>). One response with two 768-dim vectors.
        $fakeVector = array_fill(0, 768, 0.1);
        Embeddings::fake([[$fakeVector, $fakeVector]]);

        // Default chunker (maxChars: 0) yields one chunk per paragraph.
        $indexer = new EntryIndexer(new ParagraphChunker);
        $indexer->index($entry);

        $chunks = DB::table('chunk_embeddings')->where('entry_id', $entry->id)->orderBy('chunk_index')->get();
        expect($chunks)->toHaveCount(2)
            ->and($chunks[0]->chunk_index)->toBe(0)
            ->and($chunks[1]->chunk_index)->toBe(1)
            ->and($chunks[0]->content)->toBe('First paragraph.')
            ->and($chunks[1]->content)->toBe('Second paragraph.');
    });

    it('uses the configured embedding provider', function () {
        config([
            'rag.embeddings.provider' => 'custom-embedder',
            'ai.providers.custom-embedder' => config('ai.providers.local-embedder'),
        ]);

        $project = Project::create(['id' => 'r1', 'name' => 'R1', 'root_path' => '/p']);
        $entry = KnowledgeEntry::create([
            'project_id' => $project->id,
            'title' => 'Test',
            'content' => 'One paragraph.',
        ]);

        $fakeVector = array_fill(0, 768, 0.1);
        Embeddings::fake([[$fakeVector]]);

        (new EntryIndexer(new ParagraphChunker))->index($entry);

        expect(DB::table('chunk_embeddings')->where('entry_id', $entry->id)->exists())->toBeTrue();
        Embeddings::assertGenerated(
            fn (EmbeddingsPrompt $prompt): bool => $prompt->provider->name() === 'custom-embedder',
        );
    });

    it('deletes old chunks before re-indexing', function () {
        // Fake the queue so KnowledgeEntry::create's observer doesn't
        // synchronously dispatch IndexEntryJob (sync connection in tests)
        // and race with the manually-inserted "old chunk" below.
        Queue::fake();

        $project = Project::create(['id' => 'r1', 'name' => 'R1', 'root_path' => '/p']);
        $entry = KnowledgeEntry::create([
            'project_id' => $project->id,
            'title' => 'Test',
            'content' => 'One paragraph.',
        ]);

        // Insert a fake old chunk that should be replaced.
        DB::table('chunk_embeddings')->insert([
            'entry_id' => $entry->id,
            'project_id' => $project->id,
            'chunk_index' => 0,
            'content' => 'old content',
            'embedding' => '['.implode(',', array_fill(0, 768, '0.5')).']',
        ]);

        $fakeVector = array_fill(0, 768, 0.1);
        Embeddings::fake([[$fakeVector]]);

        $indexer = new EntryIndexer(new ParagraphChunker);
        $indexer->index($entry);

        $chunks = DB::table('chunk_embeddings')->where('entry_id', $entry->id)->get();
        expect($chunks)->toHaveCount(1)
            ->and($chunks[0]->content)->toBe('One paragraph.');
    });

    it('handles empty content without calling the embedder', function () {
        $project = Project::create(['id' => 'r1', 'name' => 'R1', 'root_path' => '/p']);
        $entry = KnowledgeEntry::create([
            'project_id' => $project->id,
            'title' => 'Test',
            'content' => '',
        ]);

        // preventStrayEmbeddings makes the fake throw if the embedder is called,
        // so an empty-content entry must short-circuit before reaching the SDK.
        Embeddings::fake()->preventStrayEmbeddings();

        $indexer = new EntryIndexer(new ParagraphChunker);
        $indexer->index($entry);

        $chunks = DB::table('chunk_embeddings')->where('entry_id', $entry->id)->get();
        expect($chunks)->toBeEmpty();
    });

    it('clears existing chunks when content becomes empty', function () {
        $project = Project::create(['id' => 'r1', 'name' => 'R1', 'root_path' => '/p']);
        $entry = KnowledgeEntry::create([
            'project_id' => $project->id,
            'title' => 'Test',
            'content' => '',
        ]);

        // Pre-existing chunks should be removed even when no new chunks are produced.
        DB::table('chunk_embeddings')->insert([
            'entry_id' => $entry->id,
            'project_id' => $project->id,
            'chunk_index' => 0,
            'content' => 'stale content',
            'embedding' => '['.implode(',', array_fill(0, 768, '0.5')).']',
        ]);

        Embeddings::fake()->preventStrayEmbeddings();

        $indexer = new EntryIndexer(new ParagraphChunker);
        $indexer->index($entry);

        $chunks = DB::table('chunk_embeddings')->where('entry_id', $entry->id)->get();
        expect($chunks)->toBeEmpty();
    });

    it('does not persist chunks when the entry is rejected during embedding', function () {
        Queue::fake();

        $project = Project::create(['id' => 'r1', 'name' => 'R1', 'root_path' => '/p']);
        $entry = KnowledgeEntry::create([
            'project_id' => $project->id,
            'title' => 'Test',
            'content' => 'One paragraph.',
            'status' => 'pending',
        ]);

        $fakeVector = array_fill(0, 768, 0.1);
        Embeddings::fake(function () use ($entry, $fakeVector): array {
            $entry->update(['status' => 'rejected']);

            return [$fakeVector];
        });

        (new EntryIndexer(new ParagraphChunker))->index($entry);

        expect(DB::table('chunk_embeddings')->where('entry_id', $entry->id)->exists())->toBeFalse();
    });

    it('does not let older embedding work overwrite chunks for newer content', function () {
        Queue::fake();

        $project = Project::create(['id' => 'r1', 'name' => 'R1', 'root_path' => '/p']);
        $entry = KnowledgeEntry::create([
            'project_id' => $project->id,
            'title' => 'Test',
            'content' => 'Old content.',
            'status' => 'pending',
        ]);

        $fakeVector = array_fill(0, 768, 0.1);
        Embeddings::fake(function () use ($entry, $project, $fakeVector): array {
            $entry->update(['content' => 'New content.']);
            DB::table('chunk_embeddings')->insert([
                'entry_id' => $entry->id,
                'project_id' => $project->id,
                'chunk_index' => 0,
                'content' => 'New content.',
                'embedding' => '['.implode(',', $fakeVector).']',
            ]);

            return [$fakeVector];
        });

        (new EntryIndexer(new ParagraphChunker))->index($entry);

        $chunks = DB::table('chunk_embeddings')->where('entry_id', $entry->id)->get();
        expect($chunks)->toHaveCount(1)
            ->and($chunks[0]->content)->toBe('New content.');
    });

    it('does not index an entry that is still being classified', function () {
        // The importance classifier's first invariant on the read path: an entry
        // awaiting its verdict is not in the index. The observer already declines
        // to schedule the job, so nothing normally calls this — but a stale job, a
        // manual re-index, or a retry that lands after the entry went BACK to
        // `classifying` all can, and the indexer must refuse on its own.
        Queue::fake();

        $project = Project::create(['id' => 'r1', 'name' => 'R1', 'root_path' => '/p']);
        $entry = KnowledgeEntry::create([
            'project_id' => $project->id,
            'title' => 'Awaiting a verdict',
            'content' => 'Orders above 1000 EUR need a manager approval before the label is bought.',
            'status' => 'classifying',
        ]);

        (new EntryIndexer(new ParagraphChunker))->index($entry);

        expect(DB::table('chunk_embeddings')->where('entry_id', $entry->id)->exists())->toBeFalse();
    });

    it('drops the chunks of an entry that becomes classifying again mid-embedding', function () {
        // The race the status guard exists for, in the one direction the classifier
        // added: the embedder is slow, and while it runs the entry is put back into
        // `classifying`. The work in flight was computed for an entry that no longer
        // belongs in the index, and it must not be written.
        Queue::fake();

        $project = Project::create(['id' => 'r1', 'name' => 'R1', 'root_path' => '/p']);
        $entry = KnowledgeEntry::create([
            'project_id' => $project->id,
            'title' => 'Re-queued for classification',
            'content' => 'One paragraph.',
            'status' => 'pending',
        ]);

        $fakeVector = array_fill(0, 768, 0.1);
        Embeddings::fake(function () use ($entry, $fakeVector): array {
            DB::table('knowledge_entries')->where('id', $entry->id)->update(['status' => 'classifying']);

            return [$fakeVector];
        });

        (new EntryIndexer(new ParagraphChunker))->index($entry);

        expect(DB::table('chunk_embeddings')->where('entry_id', $entry->id)->exists())->toBeFalse();
    });
});
