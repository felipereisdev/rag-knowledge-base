<?php

namespace App\Martis\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Martis\Actions\Action;
use Martis\Actions\ActionFields;
use Martis\Actions\ActionResponse;
use Martis\Contracts\FieldContract;

class RejectEntries extends Action
{
    public ?string $name = 'Reject';

    /**
     * Reject the selected knowledge entries so they stay out of search.
     *
     * @param  Collection<int, Model>  $models
     */
    public function handle(ActionFields $fields, Collection $models): ActionResponse|Action|null
    {
        foreach ($models as $model) {
            $model->update(['status' => 'rejected']);
        }

        return ActionResponse::message("Rejected {$models->count()} entr".($models->count() === 1 ? 'y' : 'ies').'.');
    }

    /**
     * @return list<FieldContract>
     */
    public function fields(Request $request): array
    {
        return [];
    }
}
