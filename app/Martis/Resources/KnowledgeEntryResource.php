<?php

namespace App\Martis\Resources;

use App\Models\KnowledgeEntry;
use Illuminate\Http\Request;
use Martis\Fields\BelongsTo;
use Martis\Fields\BelongsToMany;
use Martis\Fields\DateTime;
use Martis\Fields\Id;
use Martis\Fields\KeyValue;
use Martis\Fields\Select;
use Martis\Fields\Text;
use Martis\Fields\Textarea;
use Martis\Resource;

class KnowledgeEntryResource extends Resource
{
    public static function model(): string
    {
        return KnowledgeEntry::class;
    }

    public function fields(Request $request): array
    {
        return [
            Id::make('id'),

            BelongsTo::make('project', 'Project')
                ->sortable()
                ->searchable(),

            Text::make('title')
                ->sortable()
                ->searchable()
                ->required(),

            Textarea::make('content')
                ->help('Markdown supported.'),

            Select::make('category')
                ->options([
                    'business-rule' => 'Business Rule',
                    'design-decision' => 'Design Decision',
                    'architecture' => 'Architecture',
                    'documentation' => 'Documentation',
                    'insight' => 'Insight',
                    'convention' => 'Convention',
                    'constraint' => 'Constraint',
                ]),

            Select::make('status')
                ->options([
                    'pending' => 'Pending',
                    'approved' => 'Approved',
                    'rejected' => 'Rejected',
                ])
                ->default('pending'),

            Text::make('source')
                ->help('manual, mcp, import, or cli.'),

            Text::make('author'),

            KeyValue::make('metadata'),

            BelongsToMany::make('tags', 'Tags')
                ->searchable(),

            BelongsToMany::make('entities', 'Entities')
                ->searchable(),

            DateTime::make('created_at')
                ->sortable(),
        ];
    }
}
