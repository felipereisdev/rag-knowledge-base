<?php

// tests/Feature/Hooks/EnsureProjectEndpointTest.php

use App\Models\Project;

function hookHeaders(): array
{
    config()->set('rag.hooks.token', 'test-token');

    return ['Authorization' => 'Bearer test-token'];
}

it('rejects requests without a valid token when a token is configured', function () {
    config()->set('rag.hooks.token', 'test-token');

    $this->postJson('/hooks/ensure-project', ['cwd' => '/tmp/acme'])
        ->assertStatus(401);
});

it('allows requests when no token is configured (localhost model)', function () {
    config()->set('rag.hooks.token', '');

    $res = $this->postJson('/hooks/ensure-project', ['cwd' => '/tmp/open-app']);

    $res->assertOk();
    expect(trim($res->getContent()))->toBe('open-app');
});

it('creates the project from cwd and returns its id', function () {
    $res = $this->withHeaders(hookHeaders())
        ->postJson('/hooks/ensure-project', ['cwd' => '/tmp/acme-app']);

    $res->assertOk();
    expect(trim($res->getContent()))->toBe('acme-app');
    expect(Project::where('id', 'acme-app')->exists())->toBeTrue();
});

it('is idempotent for an existing project', function () {
    Project::create(['id' => 'acme-app', 'name' => 'acme-app', 'root_path' => '/tmp/acme-app']);

    $res = $this->withHeaders(hookHeaders())
        ->postJson('/hooks/ensure-project', ['cwd' => '/tmp/acme-app']);

    $res->assertOk();
    expect(Project::where('id', 'acme-app')->count())->toBe(1);
});
