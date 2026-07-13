<?php

namespace App\Martis\Filters;

use App\Enums\KnowledgeStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Martis\Filters\SelectFilter;

class StatusFilter extends SelectFilter
{
    /**
     * Define the filter options.
     *
     * Keys are display labels, values are passed to apply().
     *
     * @return array<string, mixed>
     */
    public function options(Request $request): array
    {
        return array_flip(KnowledgeStatus::options());
    }

    /**
     * Apply the filter to the given query.
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public function apply(Request $request, Builder $query, mixed $value): Builder
    {
        return $query->where('status', $value);
    }
}
