<?php

namespace App\Martis\Resources;

use App\Models\ProjectPath;
use Illuminate\Http\Request;
use Martis\Contracts\OverrideContract;
use Martis\DrawerOverride;
use Martis\Fields\BelongsTo;
use Martis\Fields\Id;
use Martis\Fields\Text;
use Martis\Resource;

class ProjectPathResource extends Resource
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
        return ProjectPath::class;
    }

    public function fields(Request $request): array
    {
        return [
            Id::make('id'),
            BelongsTo::make('project', 'Project')->searchable(),
            Text::make('path')->required(),
        ];
    }
}
