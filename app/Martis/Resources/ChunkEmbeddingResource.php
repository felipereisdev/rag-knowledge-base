<?php

namespace App\Martis\Resources;

use App\Models\ChunkEmbedding;
use Illuminate\Http\Request;
use Martis\Fields\BelongsTo;
use Martis\Fields\Id;
use Martis\Fields\Number;
use Martis\Fields\Text;
use Martis\Fields\Textarea;
use Martis\Resource;

class ChunkEmbeddingResource extends Resource
{
    public static function model(): string
    {
        return ChunkEmbedding::class;
    }

    public function fields(Request $request): array
    {
        return [
            Id::make('id'),
            BelongsTo::make('entry', 'Entry')->searchable(),
            BelongsTo::make('project', 'Project')->searchable(),
            Number::make('chunk_index')->sortable(),
            Textarea::make('content')
                ->help('Text content of this chunk. Editable only on create.'),
            Text::make('embedding')
                ->help('Vector(768). Pass a JSON array, e.g. [0.1,0.2,...].'),
        ];
    }
}
