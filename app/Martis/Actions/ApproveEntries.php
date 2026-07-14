<?php

namespace App\Martis\Actions;

use App\Enums\KnowledgeStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Martis\Actions\Action;
use Martis\Actions\ActionFields;
use Martis\Actions\ActionResponse;
use Martis\Contracts\FieldContract;

class ApproveEntries extends Action
{
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
     * transition guard would overwrite the approval moments later.
     *
     * @param  Collection<int, Model>  $models
     */
    public function handle(ActionFields $fields, Collection $models): ActionResponse|Action|null
    {
        [$classifying, $actionable] = $models->partition(
            static fn (Model $model): bool => $model->getAttribute('status') === KnowledgeStatus::Classifying->value,
        );

        foreach ($actionable as $model) {
            $model->update(['status' => KnowledgeStatus::Approved->value]);
        }

        $blocked = $classifying->count();
        $approved = $actionable->count();

        if ($blocked === 0) {
            return ActionResponse::message(
                trans_choice('rag.actions.approve.success', $approved, ['count' => $approved]),
            );
        }

        if ($approved === 0) {
            return ActionResponse::danger(
                trans_choice('importance.actions.approve_blocked', $blocked, ['count' => $blocked]),
            );
        }

        return ActionResponse::danger(
            trans_choice('importance.actions.approve_partial', $blocked, [
                'count' => $blocked,
                'approved' => $approved,
            ]),
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
