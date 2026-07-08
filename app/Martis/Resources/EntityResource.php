<?php

namespace App\Martis\Resources;

use App\Models\Entity;
use Illuminate\Http\Request;
use Martis\Fields\BelongsTo;
use Martis\Fields\Id;
use Martis\Fields\Text;
use Martis\Resource;

class EntityResource extends Resource
{
    public static function model(): string
    {
        return Entity::class;
    }

    public function fields(Request $request): array
    {
        return [
            Id::make('id'),
            BelongsTo::make('project', 'Project')->searchable(),
            Text::make('name')->required(),
            Text::make('type'),
        ];
    }
}