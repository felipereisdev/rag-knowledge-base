<?php

namespace App\Martis\Resources;

use App\Martis\Actions\ApproveEntries;
use App\Martis\Actions\ApproveEntryInline;
use App\Martis\Actions\RejectEntries;
use App\Martis\Actions\RejectEntryInline;
use App\Models\KnowledgeEntry;
use Illuminate\Http\Request;
use Martis\Actions\Action;
use Martis\Contracts\OverrideContract;
use Martis\DrawerOverride;
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
        return KnowledgeEntry::class;
    }

    /**
     * Approve / Reject on two surfaces at once:
     *
     *  - Bulk "Actions" dropdown (select 1+ rows) via ApproveEntries / RejectEntries.
     *  - Per-row inline ✓/✗ buttons via ApproveEntryInline / RejectEntryInline.
     *
     * The Martis SPA (v1.28.2) splits the resource's action list client-side:
     * the bulk dropdown is built from `showOnIndex && !showInline`, while the
     * per-row buttons are built from `showInline`. A single action is therefore
     * EITHER a dropdown item OR an inline button — never both. Registering each
     * behaviour twice under distinct uriKeys (the `*Inline` subclasses) is what
     * lets Approve/Reject appear in BOTH places. The inline buttons render in
     * the trailing Actions column, after the default view/edit/delete icons.
     *
     * @return array<int, Action>
     */
    public function actions(Request $request): array
    {
        return [
            // Bulk dropdown (multi-select)
            ApproveEntries::make()
                ->icon('check-circle')
                ->iconColor('#16a34a'),
            RejectEntries::make()
                ->icon('x-circle')
                ->iconColor('#dc2626'),

            // Per-row inline buttons (onlyInline: excluded from the dropdown,
            // shown as a ✓/✗ button on every row).
            ApproveEntryInline::make()
                ->onlyInline()
                ->icon('check-circle')
                ->iconColor('#16a34a'),
            RejectEntryInline::make()
                ->onlyInline()
                ->icon('x-circle')
                ->iconColor('#dc2626'),
        ];
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
                ->hideFromIndex()
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
