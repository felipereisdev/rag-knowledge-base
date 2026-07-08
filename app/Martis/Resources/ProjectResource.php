<?php

namespace App\Martis\Resources;

use App\Models\Project;
use Illuminate\Http\Request;
use Martis\Fields\Id;
use Martis\Fields\Select;
use Martis\Fields\Text;
use Martis\Fields\Textarea;
use Martis\Resource;

class ProjectResource extends Resource
{
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
                ->rules('required', 'alpha_dash')
                ->help('Used in URLs and as project identifier.'),

            Text::make('name')
                ->sortable()
                ->searchable()
                ->required(),

            Text::make('root_path')
                ->required()
                ->help('Absolute path to repo root.'),

            Textarea::make('description'),

            Select::make('project_type')
                ->options([
                    'python' => 'Python',
                    'typescript' => 'TypeScript',
                    'go' => 'Go',
                    'rust' => 'Rust',
                    'other' => 'Other',
                ]),

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