<?php

namespace App\Martis\Resources;

use App\Enums\ImportanceClassifierMode;
use App\Models\ImportanceClassifierSetting;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Martis\Fields\Id;
use Martis\Fields\Number;
use Martis\Fields\Select;
use Martis\Fields\Text;
use Martis\Resource;

/**
 * The classifier's administration surface — a singleton.
 *
 * The migration seeded exactly one row (id 1, enforced by a CHECK constraint),
 * and {@see ImportanceClassifierSetting::current()} never creates one. So this
 * resource only ever *edits* that row: creation and deletion are refused, which
 * is what keeps a second, silently ignored settings row from ever existing.
 *
 * Only `mode` and `threshold` are editable. The model, the prompt version and
 * the rules version are code-owned (`config/rag.php` → `importance`, and the
 * VERSION constants the prompt and the rule set declare): they are part of the
 * assessment cache identity, so an administrator changing them from a form
 * would silently invalidate every cached assessment. They are shown read-only.
 */
class ImportanceClassifierSettingResource extends Resource
{
    public static function model(): string
    {
        return ImportanceClassifierSetting::class;
    }

    public static function label(): string
    {
        return __('importance.resource.label');
    }

    public static function singularLabel(): string
    {
        return __('importance.resource.singular_label');
    }

    public static function subtitle(): ?string
    {
        return __('importance.resource.subtitle');
    }

    public function authorizedToCreate(Request $request): bool
    {
        return false;
    }

    public function authorizedToDelete(Request $request): bool
    {
        return false;
    }

    public function fields(Request $request): array
    {
        return [
            Id::make('id'),

            Select::make('mode', __('importance.fields.mode'))
                ->optionsFromMap(ImportanceClassifierMode::options())
                ->default(ImportanceClassifierMode::Shadow->value)
                ->rules(['required', Rule::in(ImportanceClassifierMode::values())])
                ->help(__('importance.fields.mode_help')),

            Number::make('threshold', __('importance.fields.threshold'))
                ->rules(['required', 'integer', 'between:0,100'])
                ->help(__('importance.fields.threshold_help')),

            // Code-owned. `exceptOnForms()` keeps them out of every form context,
            // so the update endpoint neither validates nor fills them — there is
            // no editable copy of a config value to drift out of sync.
            Text::make('active_model', __('importance.fields.active_model'))
                ->exceptOnForms()
                ->resolveUsing(static fn (): string => (string) config('rag.importance.model'))
                ->help(__('importance.fields.active_model_help')),

            Text::make('prompt_version', __('importance.fields.prompt_version'))
                ->exceptOnForms()
                ->resolveUsing(static fn (): string => (string) config('rag.importance.prompt_version'))
                ->help(__('importance.fields.prompt_version_help')),

            Text::make('rules_version', __('importance.fields.rules_version'))
                ->exceptOnForms()
                ->resolveUsing(static fn (): string => (string) config('rag.importance.rules_version'))
                ->help(__('importance.fields.rules_version_help')),
        ];
    }
}
