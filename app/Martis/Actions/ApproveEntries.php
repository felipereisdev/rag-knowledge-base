<?php

namespace App\Martis\Actions;

use App\Enums\KnowledgeStatus;
use App\Martis\Actions\Concerns\RefusesClassifyingEntries;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Martis\Actions\Action;
use Martis\Actions\ActionFields;
use Martis\Actions\ActionResponse;
use Martis\Contracts\FieldContract;

class ApproveEntries extends Action
{
    use RefusesClassifyingEntries;

    public function name(): string
    {
        return __('rag.actions.approve.name');
    }

    /**
     * Approve the selected knowledge entries so they become searchable.
     *
     * Approval preserves indexing already completed or queued while pending.
     * Entries recovering from rejection request fresh indexing via the observer.
     *
     * An entry still in `classifying` is refused: the classifier job owns that
     * status and will drive the entry to `pending` or `rejected` itself. Letting
     * a human approve it would race the job — and lose, because the job's
     * transition guard would overwrite the approval moments later. See
     * {@see RefusesClassifyingEntries}.
     *
     * @param  Collection<int, Model>  $models
     */
    public function handle(ActionFields $fields, Collection $models): ActionResponse|Action|null
    {
        [$classifying, $actionable] = $this->partitionClassifying($models);

        foreach ($actionable as $model) {
            $model->update(['status' => KnowledgeStatus::Approved->value]);
        }

        return $this->respondToClassifyingGuard(
            $classifying,
            $actionable->count(),
            successKey: 'rag.actions.approve.success',
            blockedKey: 'importance.actions.approve_blocked',
            partialKey: 'importance.actions.approve_partial',
            partialActionedParam: 'approved',
        );
    }

    /**
     * @return list<FieldContract>
     */
    public function fields(Request $request): array
    {
        return [];
    }
}
