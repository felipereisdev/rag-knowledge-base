<?php

namespace App\Martis\Resources;

use App\Models\Relation;
use Illuminate\Http\Request;
use Martis\Fields\BelongsTo;
use Martis\Fields\Id;
use Martis\Fields\Text;
use Martis\Fields\DateTime;
use Martis\Resource;

class RelationResource extends Resource
{
    public static function model(): string
    {
        return Relation::class;
    }

    public function fields(Request $request): array
    {
        return [
            Id::make('id'),
            BelongsTo::make('project', 'Project')->searchable(),
            BelongsTo::make('subject', 'Subject')->searchable(),
            Text::make('predicate')->required(),
            BelongsTo::make('object', 'Object')->searchable(),
            BelongsTo::make('entry', 'Entry')->nullable(),
            DateTime::make('created_at')->sortable(),
        ];
    }
}