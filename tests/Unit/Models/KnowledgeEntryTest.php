<?php

use App\Models\ChunkEmbedding;
use App\Models\KnowledgeEntry;
use App\Models\Project;
use App\Models\Tag;
use Illuminate\Support\Facades\DB;

describe('KnowledgeEntry model', function () {
    it('can be created with defaults', function () {
        $project = Project::create(['id' => 'r1', 'name' => 'R1', 'root_path' => '/p']);
        $entry = KnowledgeEntry::create([
            'project_id' => $project->id,
            'title' => 'Test',
        ]);

        expect($entry->id)->not->toBeEmpty()
            ->and($entry->category)->toBe('insight')
            ->and($entry->status)->toBe('pending')
            ->and($entry->source)->toBe('manual')
            ->and($entry->metadata)->toBe([]);
    });

    it('casts metadata to array', function () {
        $project = Project::create(['id' => 'r1', 'name' => 'R1', 'root_path' => '/p']);
        $entry = KnowledgeEntry::create([
            'project_id' => $project->id,
            'title' => 'Test',
            'metadata' => ['key' => 'value'],
        ]);

        expect($entry->metadata)->toBe(['key' => 'value']);
    });

    it('belongs to project', function () {
        $project = Project::create(['id' => 'r1', 'name' => 'R1', 'root_path' => '/p']);
        $entry = KnowledgeEntry::create(['project_id' => $project->id, 'title' => 'T']);

        expect($entry->project->id)->toBe('r1');
    });

    it('has many tags through entry_tags', function () {
        $project = Project::create(['id' => 'r1', 'name' => 'R1', 'root_path' => '/p']);
        $entry = KnowledgeEntry::create(['project_id' => $project->id, 'title' => 'T']);
        $tag = Tag::create(['project_id' => $project->id, 'name' => 'php']);
        $entry->tags()->attach($tag->id);

        expect($entry->tags)->toHaveCount(1)
            ->and($entry->tags->first()->name)->toBe('php');
    });

    it('has many chunk embeddings', function () {
        $project = Project::create(['id' => 'r1', 'name' => 'R1', 'root_path' => '/p']);
        $entry = KnowledgeEntry::create(['project_id' => $project->id, 'title' => 'T']);
        // ChunkEmbedding requires vector — use raw insert
        DB::table('chunk_embeddings')->insert([
            'entry_id' => $entry->id,
            'project_id' => $project->id,
            'chunk_index' => 0,
            'content' => 'chunk text',
            'embedding' => '['.implode(',', array_fill(0, 768, '0.1')).']',
        ]);

        expect($entry->chunks)->toHaveCount(1);
    });
});
