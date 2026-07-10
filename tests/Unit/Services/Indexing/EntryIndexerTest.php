<?php

use App\Models\KnowledgeEntry;
use App\Models\Project;
use App\Services\Chunking\ParagraphChunker;
use App\Services\Indexing\EntryIndexer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Embeddings;

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
});
