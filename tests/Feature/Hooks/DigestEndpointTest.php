<?php
// tests/Feature/Hooks/DigestEndpointTest.php

use App\Models\KnowledgeEntry;
use App\Models\Project;

beforeEach(function () {
    config()->set('rag.hooks.token', 'test-token');
    $this->hdr = ['Authorization' => 'Bearer test-token'];
});

function makeEntry(string $pid, string $title, string $status): void
{
    KnowledgeEntry::withoutEvents(function () use ($pid, $title, $status) {
        KnowledgeEntry::create([
            'project_id' => $pid,
            'title' => $title,
            'content' => 'x',
            'category' => 'insight',
            'status' => $status,
        ]);
    });
}

it('returns only approved entries in the digest', function () {
    Project::create(['id' => 'acme', 'name' => 'acme', 'root_path' => '/tmp/acme']);
    makeEntry('acme', 'Approved rule', 'approved');
    makeEntry('acme', 'Pending rule', 'pending');

    $res = $this->withHeaders($this->hdr)->postJson('/hooks/digest', ['cwd' => '/tmp/acme']);

    $res->assertOk();
    expect($res->getContent())->toContain('Approved rule');
    expect($res->getContent())->not->toContain('Pending rule');
});

it('returns an empty body when nothing is approved', function () {
    Project::create(['id' => 'empty', 'name' => 'empty', 'root_path' => '/tmp/empty']);
    makeEntry('empty', 'Pending only', 'pending');

    $res = $this->withHeaders($this->hdr)->postJson('/hooks/digest', ['cwd' => '/tmp/empty']);

    $res->assertOk();
    expect(trim($res->getContent()))->toBe('');
});
