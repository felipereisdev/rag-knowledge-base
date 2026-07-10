<?php

namespace App\Martis\Resources;

use App\Enums\ExtractorDriver;
use App\Enums\ExtractorProvider;
use App\Models\CondenseSetting;
use Illuminate\Http\Request;
use Martis\Fields\Boolean;
use Martis\Fields\Id;
use Martis\Fields\Number;
use Martis\Fields\Select;
use Martis\Fields\Text;
use Martis\Fields\Textarea;
use Martis\Resource;

class CondenseSettingResource extends Resource
{
    public static function model(): string
    {
        return CondenseSetting::class;
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

            Boolean::make('enabled', __('condense.fields.enabled'))
                ->help(__('condense.fields.enabled_help')),

            Select::make('driver', __('condense.fields.driver'))
                ->optionsFromMap(ExtractorDriver::options())
                ->rules(['required', 'in:'.implode(',', array_keys(ExtractorDriver::options()))])
                ->help(__('condense.fields.driver_help')),

            Select::make('provider', __('condense.fields.provider'))
                ->optionsFromMap(ExtractorProvider::options())
                ->rules(['nullable', 'in:'.implode(',', array_keys(ExtractorProvider::options()))])
                ->help(__('condense.fields.provider_help')),

            Text::make('model', __('condense.fields.model'))
                ->rules(['required', 'string', 'max:255'])
                ->help(__('condense.fields.model_help')),

            Number::make('min_dedup_score', __('condense.fields.min_dedup_score'))
                ->rules(['required', 'numeric', 'between:0,1'])
                ->help(__('condense.fields.min_dedup_score_help')),

            Number::make('max_transcript_chars', __('condense.fields.max_transcript_chars'))
                ->rules(['required', 'integer', 'min:1000'])
                ->help(__('condense.fields.max_transcript_chars_help')),

            Textarea::make('system_prompt_override', __('condense.fields.system_prompt_override'))
                ->rules(['nullable', 'string'])
                ->help(__('condense.fields.system_prompt_override_help')),
        ];
    }
}
