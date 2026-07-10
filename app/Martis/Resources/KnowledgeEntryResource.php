<?php

namespace App\Martis\Resources;

use App\Martis\Actions\ApproveEntries;
use App\Martis\Actions\RejectEntries;
use App\Martis\Filters\CategoryFilter;
use App\Martis\Filters\ProjectFilter;
use App\Martis\Filters\StatusFilter;
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
use Martis\Fields\Markdown;
use Martis\Fields\Select;
use Martis\Fields\Text;
use Martis\Filters\DateRangeFilter;
use Martis\Filters\Filter;
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
     * Per-row inline ✓/✗ buttons AND the bulk "Actions" dropdown from a single
     * action each. Martis v1.28.3 made ->showInline() additive (it keeps
     * showOnIndex=true), so one showInline action appears in both surfaces — no
     * need to register the operation twice.
     *
     * NOTE: Martis caches the resource schema (forever TTL); the container
     * entrypoint runs `martis:cache:clear` on boot so changes here take effect.
     *
     * @return array<int, Action>
     */
    public function actions(Request $request): array
    {
        return [
            ApproveEntries::make()
                ->showInline()
                ->icon('check-circle')
                ->iconColor('#16a34a'),
            RejectEntries::make()
                ->showInline()
                ->icon('x-circle')
                ->iconColor('#dc2626'),
        ];
    }

    /**
     * Index filters: narrow entries by project, category, status and creation
     * date. Category/Status options mirror the form field enumerations flipped
     * to the SelectFilter label => value shape; Project lists live projects.
     * "Created Between" is a from/to range (either bound optional).
     *
     * @return array<int, Filter>
     */
    public function filters(Request $request): array
    {
        return [
            ProjectFilter::make('Project')
                ->searchable()
                ->placeholder('Select…'),
            CategoryFilter::make('Category'),
            StatusFilter::make('Status'),
            DateRangeFilter::make('Created Between')
                ->column('created_at'),
        ];
    }

    /**
     * Drawer form (create / update / detail): a 12-column grid grouped into
     * Sections, each field sized with ->span(N). Returning Sections from
     * fields() is phpstan-clean since Martis v1.28.4 (field methods accept
     * list<FieldContract|LayoutContract>). The index table is defined
     * separately in fieldsForIndex() so it stays a flat sortable table.
     */
    public function fields(Request $request): array
    {
        return [
            BelongsTo::make('project', 'Project')
                ->searchable()
                ->span(6),
            Select::make('category')
                ->options($this->categoryOptions())
                ->span(6),
            Text::make('title')
                ->searchable()
                ->required()
                ->span(12),
            Markdown::make('content')
                ->alwaysShow()
                ->span(12),

            Select::make('status')
                ->options($this->statusOptions())
                ->default('pending')
                ->span(4),
            Text::make('source')
                ->help('manual, mcp, import, or cli.')
                ->span(4),
            Text::make('author')
                ->span(4),
            BelongsToMany::make('Tags', 'tags', TagResource::class)
                ->searchable()
                ->span(6),
            BelongsToMany::make('Entities', 'entities', EntityResource::class)
                ->searchable()
                ->span(6),
            KeyValue::make('metadata')
                ->span(12),
        ];
    }

    /**
     * Flat columns for the index table.
     */
    public function fieldsForIndex(Request $request): array
    {
        return [
            Id::make('id'),
            BelongsTo::make('project', 'Project')
                ->sortable()
                ->searchable(),
            Text::make('title')
                ->sortable()
                ->searchable(),
            Select::make('category')
                ->options($this->categoryOptions()),
            Select::make('status')
                ->options($this->statusOptions()),
            DateTime::make('created_at')
                ->sortable(),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function categoryOptions(): array
    {
        return [
            'business-rule' => 'Business Rule',
            'design-decision' => 'Design Decision',
            'architecture' => 'Architecture',
            'documentation' => 'Documentation',
            'insight' => 'Insight',
            'convention' => 'Convention',
            'constraint' => 'Constraint',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function statusOptions(): array
    {
        return [
            'pending' => 'Pending',
            'approved' => 'Approved',
            'rejected' => 'Rejected',
        ];
    }
}
