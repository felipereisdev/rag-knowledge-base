<?php

use App\Models\ChunkEmbedding;
use App\Models\KnowledgeEntry;
use App\Models\Project;

describe('ChunkEmbeddingResource', function () {
    it('can list chunk embeddings', function () {
        $project = Project::create(['id' => 'r1', 'name' => 'R1', 'root_path' => '/p']);
        $entry = KnowledgeEntry::create(['project_id' => $project->id, 'title' => 'T']);
        ChunkEmbedding::create([
            'entry_id' => $entry->id,
            'project_id' => $project->id,
            'chunk_index' => 0,
            'content' => 'first chunk',
            'embedding' => '['.implode(',', array_fill(0, 768, '0.1')).']',
        ]);
        ChunkEmbedding::create([
            'entry_id' => $entry->id,
            'project_id' => $project->id,
            'chunk_index' => 1,
            'content' => 'second chunk',
            'embedding' => '['.implode(',', array_fill(0, 768, '0.2')).']',
        ]);

        $response = $this->get('/martis/api/resources/chunk-embeddings');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
    });

    it('can create a chunk embedding', function () {
        $project = Project::create(['id' => 'r1', 'name' => 'R1', 'root_path' => '/p']);
        $entry = KnowledgeEntry::create(['project_id' => $project->id, 'title' => 'T']);

        $response = $this->post('/martis/api/resources/chunk-embeddings', [
            'entry_id' => $entry->id,
            'project_id' => $project->id,
            'chunk_index' => 0,
            'content' => 'chunk text',
            'embedding' => '['.implode(',', array_fill(0, 768, '0.1')).']',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('chunk_embeddings', [
            'entry_id' => $entry->id,
            'project_id' => 'r1',
            'chunk_index' => 0,
            'content' => 'chunk text',
        ]);
    });

    it('can delete a chunk embedding', function () {
        $project = Project::create(['id' => 'r1', 'name' => 'R1', 'root_path' => '/p']);
        $entry = KnowledgeEntry::create(['project_id' => $project->id, 'title' => 'T']);
        $embedding = ChunkEmbedding::create([
            'entry_id' => $entry->id,
            'project_id' => $project->id,
            'chunk_index' => 0,
            'content' => 'to remove',
            'embedding' => '['.implode(',', array_fill(0, 768, '0.1')).']',
        ]);

        $response = $this->delete("/martis/api/resources/chunk-embeddings/{$embedding->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('chunk_embeddings', ['id' => $embedding->id]);
    });
});
