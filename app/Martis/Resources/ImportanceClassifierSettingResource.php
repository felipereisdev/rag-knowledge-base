<?php

namespace App\Martis\Resources;

use App\Enums\ImportanceClassifierMode;
use App\Models\ImportanceClassifierSetting;
use App\Services\Importance\DeterministicImportanceRules;
use App\Services\Importance\ImportancePrompt;
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
 * Only `mode` and `threshold` are editable. The model is code-owned via
 * `config('rag.importance.model')`; the prompt version and the rules version
 * are code-owned via the `VERSION` constants the prompt and the rule set
 * declare (never through config — see `config/rag.php` → `importance` for
 * why). All three are part of the assessment cache identity, so an
 * administrator changing them from a form would silently invalidate every
 * cached assessment. They are shown read-only.
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

            // Nullable: `null` disables auto-approval while rejection (in
            // `enforce`) keeps working — the escape hatch described on the
            // migration. `gte:threshold` refuses a value that would approve
            // something below the importance threshold, which is incoherent;
            // the drawer resubmits every scalar field on save, so `threshold`
            // is always present in the payload for `gte:` to compare against.
            Number::make('auto_approve_threshold', __('importance.fields.auto_approve_threshold'))
                ->rules(['nullable', 'integer', 'min:0', 'max:100', 'gte:threshold'])
                ->help(__('importance.help.auto_approve_threshold')),

            // Code-owned. `exceptOnForms()` keeps them out of every form context,
            // so the update endpoint neither validates nor fills them — there is
            // no editable copy of a config value to drift out of sync.
            Text::make('active_model', __('importance.fields.active_model'))
                ->exceptOnForms()
                ->resolveUsing(static fn (): string => (string) config('rag.importance.model'))
                ->help(__('importance.fields.active_model_help')),

            // Class constants, not `config('rag.importance.*_version')`: a stale
            // `config:cache` snapshot taken before a version bump would make this
            // read-only display disagree with the version the code actually stamps.
            Text::make('prompt_version', __('importance.fields.prompt_version'))
                ->exceptOnForms()
                ->resolveUsing(static fn (): string => ImportancePrompt::VERSION)
                ->help(__('importance.fields.prompt_version_help')),

            Text::make('rules_version', __('importance.fields.rules_version'))
                ->exceptOnForms()
                ->resolveUsing(static fn (): string => DeterministicImportanceRules::VERSION)
                ->help(__('importance.fields.rules_version_help')),
        ];
    }
}
