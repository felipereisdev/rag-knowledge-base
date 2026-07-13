<?php

namespace App\Martis\Resources;

use App\Enums\ProjectLanguage;
use App\Enums\ProjectTechnology;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Martis\Contracts\OverrideContract;
use Martis\DrawerOverride;
use Martis\Fields\Id;
use Martis\Fields\MultiSelect;
use Martis\Fields\Select;
use Martis\Fields\Text;
use Martis\Fields\Textarea;
use Martis\Resource;

class ProjectResource extends Resource
{
    public function overrideCreate(): ?OverrideContract
    {
        return DrawerOverride::create();
    }

    public function overrideUpdate(): ?OverrideContract
    {
        return DrawerOverride::update();
    }

    public function overrideDetail(): ?OverrideContract
    {
        return DrawerOverride::detail();
    }

    public static function model(): string
    {
        return Project::class;
    }

    public function fields(Request $request): array
    {
        return [
            Id::make('id', __('rag.fields.id')),

            Text::make('id', __('rag.fields.slug'))
                ->sortable()
                ->immutable()
                ->rules(['required', 'alpha_dash'])
                ->help(__('rag.fields.slug_help')),

            Text::make('name', __('rag.fields.name'))
                ->sortable()
                ->searchable()
                ->required()
                ->rules(['required', 'string', 'max:255']),

            Text::make('root_path', __('rag.fields.root_path'))
                ->required()
                ->rules(['required', 'string', 'max:255'])
                ->help(__('rag.fields.root_path_help')),

            Textarea::make('description', __('rag.fields.description'))
                ->nullable()
                ->rules(['nullable', 'string']),

            MultiSelect::make('project_type', __('rag.fields.tech_stack'))
                ->options(ProjectTechnology::options())
                ->displayUsingLabels()
                ->nullable()
                ->rules(['nullable', 'array'])
                ->help(__('rag.fields.tech_stack_help')),

            Select::make('language', __('rag.fields.language'))
                ->optionsFromMap(ProjectLanguage::options())
                ->rules(['sometimes', Rule::in(ProjectLanguage::values())])
                ->help(__('rag.fields.language_help')),
        ];
    }
}
