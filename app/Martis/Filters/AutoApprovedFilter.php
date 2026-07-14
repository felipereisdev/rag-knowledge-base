<?php

namespace App\Martis\Filters;

use App\Jobs\ClassifyKnowledgeEntryJob;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Martis\Filters\SelectFilter;

/**
 * Lets a human review what the classifier approved without them.
 *
 * Extends `SelectFilter` (not `Filter` directly) so `filterType()` is
 * inherited rather than needing to be re-implemented — it renders as the
 * same two-option dropdown as {@see StatusFilter} and {@see CategoryFilter}.
 *
 * `uriKey()` is pinned to a literal string rather than left to derive from
 * `name()` (the base `Filter::uriKey()` kebab-cases the name): the name is a
 * translated label, and a URL query key must stay identical across locales,
 * not change with whichever language the admin is using.
 */
class AutoApprovedFilter extends SelectFilter
{
    public function uriKey(): string
    {
        return 'auto-approved';
    }

    /**
     * Define the filter options.
     *
     * Keys are display labels, values are passed to apply().
     *
     * @return array<string, mixed>
     */
    public function options(Request $request): array
    {
        return [
            __('importance.filters.auto_approved_yes') => '1',
            __('importance.filters.auto_approved_no') => '0',
        ];
    }

    /**
     * Apply the filter to the given query.
     *
     * `metadata.importance.auto_approved` is only ever written by
     * {@see ClassifyKnowledgeEntryJob::decide()}; an entry never
     * classified (or classified before this key existed) has no such key, and
     * `coalesce(..., false)` treats that the same as "reviewed by a human".
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public function apply(Request $request, Builder $query, mixed $value): Builder
    {
        return $query->whereRaw(
            "coalesce((metadata->'importance'->>'auto_approved')::boolean, false) = ?",
            [(bool) $value],
        );
    }
}
