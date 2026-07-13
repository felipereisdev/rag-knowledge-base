<?php

use App\Models\KnowledgeEntry;
use App\Models\Project;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Embeddings;

describe('Search page', function () {
    it('loads with 200 status', function () {
        $response = $this->get('/search');

        $response->assertOk();
        $response->assertSee('RAG Knowledge Base');
    });

    it('displays search form', function () {
        $response = $this->get('/search');

        $response->assertSee('name="q"', false);
        $response->assertSee('name="project_id"', false);
        $response->assertSee('name="category"', false);
    });

    it('shows results for a matching query', function () {
        Queue::fake();

        $project = Project::create(['id' => 'r1', 'name' => 'R1', 'root_path' => '/p']);
        $entry = KnowledgeEntry::create([
            'project_id' => $project->id,
            'title' => 'Laravel routing',
            'content' => 'Routes are defined in routes/web.php',
            'status' => 'approved',
        ]);

        $fakeVector = array_fill(0, 768, 0.1);
        Embeddings::fake([[$fakeVector]]);

        DB::table('chunk_embeddings')->insert([
            'entry_id' => $entry->id,
            'project_id' => $project->id,
            'chunk_index' => 0,
            'content' => 'Routes are defined in routes/web.php',
            'embedding' => '['.implode(',', $fakeVector).']',
        ]);

        $response = $this->get('/search?q=routing');

        $response->assertOk()
            ->assertSee('Laravel routing')
            ->assertSee('Fusion score')
            ->assertSee('Semantic similarity');
    });

    it('treats empty project and category selectors as unfiltered', function () {
        Queue::fake();

        $project = Project::create([
            'id' => 'empty-filter-project',
            'name' => 'Empty Filter Project',
            'root_path' => '/empty-filter-project',
        ]);
        $entry = KnowledgeEntry::create([
            'project_id' => $project->id,
            'title' => 'Semantic-only result',
            'content' => 'This passage deliberately shares no words with the query.',
            'category' => 'architecture',
            'status' => 'approved',
        ]);

        $fakeVector = array_fill(0, 768, 0.1);
        Embeddings::fake(fn (): array => [$fakeVector])->preventStrayEmbeddings();

        DB::table('chunk_embeddings')->insert([
            'entry_id' => $entry->id,
            'project_id' => $project->id,
            'chunk_index' => 0,
            'content' => 'This passage deliberately shares no words with the query.',
            'embedding' => '['.implode(',', $fakeVector).']',
        ]);

        $this->get('/search?q=zzqvneedle')
            ->assertOk()
            ->assertSee('Semantic-only result');

        // Exercise the search boundary with the raw values emitted by the
        // form's "all" options, before HTTP middleware coerces them to null.
        $this->withoutMiddleware(ConvertEmptyStringsToNull::class);

        $this->get('/search?'.http_build_query([
            'q' => 'zzqvneedle',
            'project_id' => '',
            'category' => '',
        ]))
            ->assertOk()
            ->assertSee('Semantic-only result');
    });

    it('shows empty state when no results', function () {
        $fakeVector = array_fill(0, 768, 0.1);
        Embeddings::fake([[$fakeVector]]);

        $response = $this->get('/search?q=nonexistentxyz');

        $response->assertOk();
        $response->assertSee('Found 0 results');
    });

    it('handles empty query string without crashing', function () {
        $response = $this->get('/search?q=');

        $response->assertOk();
        $response->assertSee('RAG Knowledge Base');
    });

    it('handles whitespace-only query without crashing', function () {
        $response = $this->get('/search?q=%20');

        $response->assertOk();
        $response->assertSee('RAG Knowledge Base');
    });
});
