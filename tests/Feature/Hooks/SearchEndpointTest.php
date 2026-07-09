<?php
// tests/Feature/Hooks/SearchEndpointTest.php

use App\Models\KnowledgeEntry;
use App\Models\Project;
use App\Services\Search\HybridSearcher;

beforeEach(function () {
    config()->set('rag.hooks.token', 'test-token');
    $this->hdr = ['Authorization' => 'Bearer test-token'];
});

it('returns empty body when project has no approved entries', function () {
    Project::create(['id' => 'acme', 'name' => 'acme', 'root_path' => '/tmp/acme']);

    $res = $this->withHeaders($this->hdr)
        ->postJson('/hooks/search', ['cwd' => '/tmp/acme', 'query' => 'anything']);

    $res->assertOk();
    expect(trim($res->getContent()))->toBe('');
});

it('formats results from the searcher', function () {
    Project::create(['id' => 'acme', 'name' => 'acme', 'root_path' => '/tmp/acme']);
    KnowledgeEntry::withoutEvents(fn () => KnowledgeEntry::create([
        'project_id' => 'acme', 'title' => 'A', 'content' => 'x', 'status' => 'approved',
    ]));

    $mock = \Mockery::mock(HybridSearcher::class);
    $mock->shouldReceive('search')->andReturn([
        (object) ['title' => 'Owner scoping', 'category' => 'architecture',
            'tags' => ['auth'], 'matchedBy' => ['vector'], 'score' => 0.91,
            'snippet' => 'Scope by owner_id.'],
    ]);
    app()->bind(HybridSearcher::class, fn () => $mock);

    $res = $this->withHeaders($this->hdr)
        ->postJson('/hooks/search', ['cwd' => '/tmp/acme', 'query' => 'scoping']);

    $res->assertOk();
    expect($res->getContent())->toContain('Owner scoping')
        ->and($res->getContent())->toContain('score: 0.91');
});
