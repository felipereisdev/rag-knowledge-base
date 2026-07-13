<?php

namespace App\Martis\Resources;

use App\Enums\KnowledgeCategory;
use App\Enums\KnowledgeStatus;
use App\Martis\Actions\ApproveEntries;
use App\Martis\Actions\RejectEntries;
use App\Martis\Filters\CategoryFilter;
use App\Martis\Filters\ProjectFilter;
use App\Martis\Filters\StatusFilter;
use App\Models\KnowledgeEntry;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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
            ProjectFilter::make(__('rag.filters.project'))
                ->searchable()
                ->placeholder(__('rag.filters.select')),
            CategoryFilter::make(__('rag.filters.category')),
            StatusFilter::make(__('rag.filters.status')),
            DateRangeFilter::make(__('rag.filters.created_between'))
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
            BelongsTo::make('project', __('rag.fields.project'))
                ->searchable()
                ->rules(['required', 'exists:projects,id'])
                ->span(6),
            Select::make('category', __('rag.fields.category'))
                ->options(KnowledgeCategory::options())
                ->default(KnowledgeCategory::Insight->value)
                ->required()
                ->rules(['sometimes', Rule::in(KnowledgeCategory::values())])
                ->span(6),
            Text::make('title', __('rag.fields.title'))
                ->searchable()
                ->required()
                ->rules(['required', 'string', 'max:255'])
                ->span(12),
            Markdown::make('content', __('rag.fields.content'))
                ->alwaysShow()
                ->rules(['sometimes', 'string'])
                ->span(12),

            Select::make('status', __('rag.fields.status'))
                ->options(KnowledgeStatus::options())
                ->default(KnowledgeStatus::Pending->value)
                ->required()
                ->rules(['sometimes', Rule::in(KnowledgeStatus::values())])
                ->span(4),
            Text::make('source', __('rag.fields.source'))
                ->help(__('rag.fields.source_help'))
                ->rules(['sometimes', 'string', 'max:255'])
                ->span(4),
            Text::make('author', __('rag.fields.author'))
                ->rules(['sometimes', 'string', 'max:255'])
                ->span(4),
            BelongsToMany::make(__('rag.fields.tags'), 'tags', TagResource::class)
                ->searchable()
                ->rules(['sometimes', 'array'])
                ->span(6),
            BelongsToMany::make(__('rag.fields.entities'), 'entities', EntityResource::class)
                ->searchable()
                ->rules(['sometimes', 'array'])
                ->span(6),
            KeyValue::make('metadata', __('rag.fields.metadata'))
                ->rules(['sometimes', 'array'])
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
            BelongsTo::make('project', __('rag.fields.project'))
                ->sortable()
                ->searchable(),
            Text::make('title', __('rag.fields.title'))
                ->sortable()
                ->searchable(),
            Select::make('category', __('rag.fields.category'))
                ->options(KnowledgeCategory::options()),
            Select::make('status', __('rag.fields.status'))
                ->options(KnowledgeStatus::options()),
            DateTime::make('created_at', __('rag.fields.created_at'))
                ->sortable(),
        ];
    }
}
