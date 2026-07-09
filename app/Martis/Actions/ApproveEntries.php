<?php

namespace App\Martis\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Martis\Actions\Action;
use Martis\Actions\ActionFields;
use Martis\Actions\ActionResponse;
use Martis\Contracts\FieldContract;

class ApproveEntries extends Action
{
    public ?string $name = 'Approve';

    /**
     * Approve the selected knowledge entries so they become searchable.
     *
     * Setting the status to "approved" triggers the entry's observer, which
     * embeds and indexes it (vector + full-text) via the queue.
     *
     * @param  Collection<int, Model>  $models
     */
    public function handle(ActionFields $fields, Collection $models): ActionResponse|Action|null
    {
        foreach ($models as $model) {
            $model->update(['status' => 'approved']);
        }

        return ActionResponse::message("Approved {$models->count()} entr".($models->count() === 1 ? 'y' : 'ies').'.');
    }

    /**
     * @return list<FieldContract>
     */
    public function fields(Request $request): array
    {
        return [];
    }
}
