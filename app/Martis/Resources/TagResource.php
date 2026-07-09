<?php

namespace App\Martis\Resources;

use App\Models\Tag;
use Illuminate\Http\Request;
use Martis\Contracts\OverrideContract;
use Martis\DrawerOverride;
use Martis\Fields\BelongsTo;
use Martis\Fields\Id;
use Martis\Fields\Text;
use Martis\Resource;

class TagResource extends Resource
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
        return Tag::class;
    }

    public function fields(Request $request): array
    {
        return [
            Id::make('id'),
            BelongsTo::make('project', 'Project')->searchable(),
            Text::make('name')->required(),
        ];
    }
}
