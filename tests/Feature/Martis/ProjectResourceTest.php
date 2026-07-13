<?php

use App\Martis\Resources\ProjectResource;
use App\Models\Project;

describe('ProjectResource', function () {
    it('can list projects', function () {
        Project::create(['id' => 'r1', 'name' => 'R1', 'root_path' => '/p']);

        $response = $this->get('/martis/api/resources/projects');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
    });

    it('can create a project', function () {
        $response = $this->post('/martis/api/resources/projects', [
            'id' => 'new-repo',
            'name' => 'New Repo',
            'root_path' => '/path/to/repo',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('projects', ['id' => 'new-repo']);
    });

    it('rejects an unsupported project language', function () {
        $response = $this->post('/martis/api/resources/projects', [
            'id' => 'new-repo',
            'name' => 'New Repo',
            'root_path' => '/path/to/repo',
            'language' => 'not-real',
        ]);

        $response->assertUnprocessable();
    });

    it('serializes translated language options with machine values', function () {
        $language = collect((new ProjectResource)->fields(request()))
            ->map(fn ($field): array => $field->toArray())
            ->firstWhere('attribute', 'language');

        expect(array_column($language['options'], 'value'))->toBe([
            'en',
            'pt',
            'pt-BR',
            'pt_PT',
            'es',
        ])->and($language['options'][2])->toBe([
            'label' => 'Brazilian Portuguese',
            'value' => 'pt-BR',
        ]);
    });

    it('creates and updates projects with supported Portuguese locale variants', function () {
        $createResponse = $this->postJson('/martis/api/resources/projects', [
            'id' => 'new-repo',
            'name' => 'New Repo',
            'root_path' => '/path/to/repo',
            'language' => 'pt-BR',
        ]);

        $createResponse->assertCreated();
        $this->assertDatabaseHas('projects', ['id' => 'new-repo', 'language' => 'pt-BR']);

        $project = Project::create(['id' => 'r1', 'name' => 'R1', 'root_path' => '/p']);
        $updateResponse = $this->putJson("/martis/api/resources/projects/{$project->id}", [
            'language' => 'pt-BR',
        ]);

        $updateResponse->assertOk();
        $this->assertDatabaseHas('projects', ['id' => 'r1', 'language' => 'pt-BR']);

        $variantUpdateResponse = $this->putJson("/martis/api/resources/projects/{$project->id}", [
            'language' => 'pt_PT',
        ]);

        $variantUpdateResponse->assertOk();
        $this->assertDatabaseHas('projects', ['id' => 'r1', 'language' => 'pt_PT']);
    });

    it('resolves every project field label and help text for the active locale', function () {
        app()->setLocale('en');
        $english = array_map(
            fn ($field): array => $field->toArray(),
            (new ProjectResource)->fields(request()),
        );

        app()->setLocale('pt_PT');
        $portuguese = array_map(
            fn ($field): array => $field->toArray(),
            (new ProjectResource)->fields(request()),
        );

        expect(array_column($english, 'label'))->toBe([
            'ID',
            'Slug',
            'Name',
            'Root Path',
            'Description',
            'Tech Stack',
            'Language',
        ])->and($english[1]['helpText'])->toBe('Used in URLs and as the project identifier.')
            ->and($english[3]['helpText'])->toBe('Absolute path to the repository root.')
            ->and($english[5]['helpText'])->toBe('Programming languages, frameworks, and databases used in the project.')
            ->and(array_column($portuguese, 'label'))->toBe([
                'ID',
                'Identificador',
                'Nome',
                'Caminho da Raiz',
                'Descrição',
                'Tecnologias',
                'Idioma',
            ])->and($portuguese[1]['helpText'])->toBe('Utilizado nos URL e como identificador do projeto.')
            ->and($portuguese[3]['helpText'])->toBe('Caminho absoluto para a raiz do repositório.')
            ->and($portuguese[5]['helpText'])->toBe('Linguagens de programação, frameworks e bases de dados utilizadas no projeto.');
    });

    it('can update a project', function () {
        $project = Project::create(['id' => 'r1', 'name' => 'R1', 'root_path' => '/p']);

        $response = $this->put("/martis/api/resources/projects/{$project->id}", [
            'name' => 'Updated Name',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('projects', ['id' => 'r1', 'name' => 'Updated Name']);
    });

    it('can update a project with empty optional fields (no not-null violation)', function () {
        $project = Project::create(['id' => 'r1', 'name' => 'R1', 'root_path' => '/p']);

        // The full drawer payload with description/project_type left empty — the
        // form sends these as null, which the NOT NULL columns must tolerate.
        $response = $this->putJson("/martis/api/resources/projects/{$project->id}", [
            'id' => 'r1',
            'name' => 'R1',
            'root_path' => '/tmp/r1',
            'description' => null,
            'project_type' => null,
            'language' => 'pt',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('projects', [
            'id' => 'r1',
            'root_path' => '/tmp/r1',
            'description' => '',
            'project_type' => '[]',
            'language' => 'pt',
        ]);
    });

    it('ignores slug (id) changes on update — the field is immutable', function () {
        Project::create(['id' => 'r1', 'name' => 'R1', 'root_path' => '/p']);

        $response = $this->putJson('/martis/api/resources/projects/r1', [
            'id' => 'renamed',
            'name' => 'R1',
            'root_path' => '/p',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('projects', ['id' => 'r1']);
        $this->assertDatabaseMissing('projects', ['id' => 'renamed']);
    });

    it('persists selected project_type languages as a JSON array', function () {
        $project = Project::create(['id' => 'r1', 'name' => 'R1', 'root_path' => '/p']);

        $response = $this->putJson("/martis/api/resources/projects/{$project->id}", [
            'name' => 'R1',
            'root_path' => '/p',
            'project_type' => ['python', 'go'],
            'language' => 'en',
        ]);

        $response->assertOk();
        // MultiSelect fill() encodes to a JSON string; the column stores it verbatim.
        expect($project->fresh()->project_type)->toBe('["python","go"]');
        $this->assertDatabaseHas('projects', ['id' => 'r1', 'project_type' => '["python","go"]']);
    });

    it('can delete a project', function () {
        $project = Project::create(['id' => 'r1', 'name' => 'R1', 'root_path' => '/p']);

        $response = $this->delete("/martis/api/resources/projects/{$project->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('projects', ['id' => 'r1']);
    });
});
