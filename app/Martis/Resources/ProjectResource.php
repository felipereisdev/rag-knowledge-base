<?php

namespace App\Martis\Resources;

use App\Enums\ProjectTechnology;
use App\Models\Project;
use Illuminate\Http\Request;
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
            Id::make('id'),

            Text::make('id', 'Slug')
                ->sortable()
                ->immutable()
                ->rules(['required', 'alpha_dash'])
                ->help('Used in URLs and as project identifier.'),

            Text::make('name')
                ->sortable()
                ->searchable()
                ->required(),

            Text::make('root_path')
                ->required()
                ->help('Absolute path to repo root.'),

            Textarea::make('description'),

            MultiSelect::make('project_type', 'Tech Stack')
                ->options(ProjectTechnology::options())
                ->displayUsingLabels()
                ->help('Programming languages, frameworks and databases used in the project.'),

            Select::make('language')
                ->options([
                    'en' => 'English',
                    'pt' => 'Portuguese',
                    'es' => 'Spanish',
                ])
                ->help('Affects FTS stemming.'),
        ];
    }
}
