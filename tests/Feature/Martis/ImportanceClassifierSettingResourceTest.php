<?php

use App\Enums\ImportanceClassifierMode;
use App\Martis\Resources\ImportanceClassifierSettingResource;
use App\Models\ImportanceClassifierSetting;
use App\Services\Importance\DeterministicImportanceRules;
use App\Services\Importance\ImportancePrompt;
use Illuminate\Http\Request;
use Martis\FieldContext;
use Martis\Fields\Field;

/**
 * The fields the resource exposes in one context, keyed by attribute — filtered
 * exactly the way the Martis controllers filter them, so "editable" here means
 * what the update endpoint would actually accept.
 *
 * @return array<string, Field>
 */
function settingFields(FieldContext $context = FieldContext::DETAIL): array
{
    $resource = new ImportanceClassifierSettingResource(new ImportanceClassifierSetting);
    $request = Request::create('/martis/resources/importance-classifier-settings', 'GET');

    $fields = match ($context) {
        FieldContext::UPDATE, FieldContext::CREATE => $resource->fieldsForUpdate($request),
        FieldContext::INDEX => $resource->fieldsForIndex($request),
        default => $resource->fieldsForDetail($request),
    };

    /** @var list<Field> $visible */
    $visible = Field::filterForContext($fields, $context, $request);

    return collect($visible)->keyBy(fn (Field $field): string => $field->attribute())->all();
}

describe('ImportanceClassifierSettingResource', function () {
    it('targets the ImportanceClassifierSetting model as a singleton that cannot be created or deleted', function () {
        expect(ImportanceClassifierSettingResource::model())->toBe(ImportanceClassifierSetting::class);

        $resource = new ImportanceClassifierSettingResource(ImportanceClassifierSetting::current());
        $request = Request::create('/martis/resources/importance-classifier-settings', 'GET');

        expect($resource->authorizedToCreate($request))->toBeFalse()
            ->and($resource->authorizedToDelete($request))->toBeFalse()
            ->and($resource->authorizedToUpdate($request))->toBeTrue();
    });

    it('exposes only mode, threshold and auto_approve_threshold as editable fields', function () {
        expect(array_keys(settingFields(FieldContext::UPDATE)))->toBe(['mode', 'threshold', 'auto_approve_threshold']);
    });

    it('shows the code-owned model and versions read-only, resolved from config and the class constants', function () {
        $fields = settingFields();
        $setting = ImportanceClassifierSetting::current();

        expect($fields)->toHaveKeys(['active_model', 'prompt_version', 'rules_version'])
            ->and($fields['active_model']->resolve($setting))->toBe(config('rag.importance.model'))
            ->and($fields['prompt_version']->resolve($setting))->toBe(ImportancePrompt::VERSION)
            ->and($fields['rules_version']->resolve($setting))->toBe(DeterministicImportanceRules::VERSION);
    });

    it('feeds the mode select from the enum with translated labels', function () {
        $options = settingFields()['mode']->toArray()['options'];

        expect($options)->toBe([
            ['label' => __('importance.modes.off'), 'value' => 'off'],
            ['label' => __('importance.modes.shadow'), 'value' => 'shadow'],
            ['label' => __('importance.modes.enforce'), 'value' => 'enforce'],
        ])->and(ImportanceClassifierMode::values())->toBe(['off', 'shadow', 'enforce']);
    });

    it('labels every field through the translator', function () {
        foreach (settingFields() as $attribute => $field) {
            if ($attribute === 'id') {
                continue;
            }

            $label = $field->toArray()['label'];

            expect($label)->toBe(__('importance.fields.'.$attribute))
                ->and($label)->not->toBe('importance.fields.'.$attribute);
        }
    });

    it('updates the singleton row through the API', function () {
        $this->putJson('/martis/api/resources/importance-classifier-settings/1', [
            'mode' => 'enforce',
            'threshold' => 80,
        ])->assertOk();

        $this->assertDatabaseHas('importance_classifier_settings', [
            'id' => 1,
            'mode' => 'enforce',
            'threshold' => 80,
        ]);
        expect(ImportanceClassifierSetting::current()->mode)->toBe(ImportanceClassifierMode::Enforce);
    });

    it('rejects a mode outside the enum', function () {
        $this->putJson('/martis/api/resources/importance-classifier-settings/1', [
            'mode' => 'aggressive',
            'threshold' => 70,
        ])->assertUnprocessable();

        $this->assertDatabaseHas('importance_classifier_settings', ['id' => 1, 'mode' => 'shadow']);
    });

    it('rejects a threshold outside 0..100 or not an integer', function (mixed $threshold) {
        $this->putJson('/martis/api/resources/importance-classifier-settings/1', [
            'mode' => 'shadow',
            'threshold' => $threshold,
        ])->assertUnprocessable();

        $this->assertDatabaseHas('importance_classifier_settings', ['id' => 1, 'threshold' => 70]);
    })->with([-1, 101, 'high', 12.5]);

    it('accepts the boundary thresholds', function (int $threshold) {
        $this->putJson('/martis/api/resources/importance-classifier-settings/1', [
            'mode' => 'shadow',
            'threshold' => $threshold,
        ])->assertOk();

        $this->assertDatabaseHas('importance_classifier_settings', ['id' => 1, 'threshold' => $threshold]);
    })->with([0, 100]);

    it('never persists the code-owned model or versions, even when they are posted', function () {
        $this->putJson('/martis/api/resources/importance-classifier-settings/1', [
            'mode' => 'shadow',
            'threshold' => 70,
            'active_model' => 'evil-model',
            'prompt_version' => 'v999',
            'rules_version' => 'v999',
        ])->assertOk();

        $setting = ImportanceClassifierSetting::current();

        expect($setting->getAttributes())->not->toHaveKeys(['active_model', 'prompt_version', 'rules_version'])
            ->and(config('rag.importance.model'))->not->toBe('evil-model');
    });

    it('refuses to create a second settings row through the API', function () {
        $this->postJson('/martis/api/resources/importance-classifier-settings', [
            'mode' => 'off',
            'threshold' => 10,
        ])->assertForbidden();

        expect(ImportanceClassifierSetting::query()->count())->toBe(1);
    });

    it('refuses to delete the settings singleton through the API', function () {
        $this->deleteJson('/martis/api/resources/importance-classifier-settings/1')->assertForbidden();

        expect(ImportanceClassifierSetting::query()->count())->toBe(1);
    });

    it('accepts an auto-approve threshold at or above the importance threshold', function () {
        $response = $this->putJson('/martis/api/resources/importance-classifier-settings/1', [
            'mode' => 'shadow',
            'threshold' => 70,
            'auto_approve_threshold' => 90,
        ]);

        $response->assertSuccessful();

        expect(ImportanceClassifierSetting::current()->auto_approve_threshold)->toBe(90);
    });

    it('refuses an auto-approve threshold below the importance threshold', function () {
        $response = $this->putJson('/martis/api/resources/importance-classifier-settings/1', [
            'mode' => 'shadow',
            'threshold' => 70,
            'auto_approve_threshold' => 60,
        ]);

        // Martis renders validation failures as a list of {field, message, code}
        // objects rather than Laravel's default field-keyed map. `code` is
        // derived by substring-scanning the English validation message
        // (see JsonErrorResponse::inferCode()), so it is not asserted here —
        // if Laravel's wording for `gte` ever picks up "minimum"/"maximum"
        // the inferred code would flip and this assertion would break for a
        // reason unrelated to what the test actually checks. Asserting on
        // the flattened list of `field`s (rather than assertJsonFragment,
        // which matches key/value pairs independently of which error object
        // they came from) ties the assertion to the one thing that matters:
        // the `auto_approve_threshold` field itself failed.
        $response->assertStatus(422);
        expect(collect($response->json('errors'))->pluck('field')->all())
            ->toContain('auto_approve_threshold');
    })->note('Approving below the importance threshold is incoherent.');

    it('accepts a null auto-approve threshold to disable auto-approval', function () {
        $response = $this->putJson('/martis/api/resources/importance-classifier-settings/1', [
            'mode' => 'enforce',
            'threshold' => 70,
            'auto_approve_threshold' => null,
        ]);

        $response->assertSuccessful();

        expect(ImportanceClassifierSetting::current()->auto_approve_threshold)->toBeNull();
    });

    it('refuses an auto-approve threshold outside 0..100', function () {
        $response = $this->putJson('/martis/api/resources/importance-classifier-settings/1', [
            'mode' => 'shadow',
            'threshold' => 70,
            'auto_approve_threshold' => 101,
        ]);

        // See the note above: `code` is a heuristic derived from message
        // wording and is not what this test cares about.
        $response->assertStatus(422);
        expect(collect($response->json('errors'))->pluck('field')->all())
            ->toContain('auto_approve_threshold');
    });
});
