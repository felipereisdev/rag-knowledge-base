<?php

namespace App\Martis\Filters;

use App\Models\Project;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Martis\Filters\SelectFilter;

class ProjectFilter extends SelectFilter
{
    /**
     * Projects to filter by, as label => value (name => id slug). Searchable
     * because the base can accumulate many projects over time.
     *
     * @return array<string, mixed>
     */
    public function options(Request $request): array
    {
        return Project::query()
            ->orderBy('name')
            ->pluck('id', 'name')
            ->all();
    }

    /**
     * Apply the filter to the given query.
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public function apply(Request $request, Builder $query, mixed $value): Builder
    {
        return $query->where('project_id', $value);
    }
}
