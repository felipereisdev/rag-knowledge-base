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

class RejectEntries extends Action
{
    public function name(): string
    {
        return __('rag.actions.reject.name');
    }

    /**
     * Reject the selected knowledge entries so they stay out of search.
     *
     * An entry still in `classifying` is refused, for the same reason approval
     * refuses it: the classifier job owns that status and its transition guard
     * would overwrite the human's decision when it lands.
     *
     * @param  Collection<int, Model>  $models
     */
    public function handle(ActionFields $fields, Collection $models): ActionResponse|Action|null
    {
        [$classifying, $actionable] = $models->partition(
            static fn (Model $model): bool => $model->getAttribute('status') === KnowledgeStatus::Classifying->value,
        );

        foreach ($actionable as $model) {
            $model->update(['status' => KnowledgeStatus::Rejected->value]);
        }

        $blocked = $classifying->count();
        $rejected = $actionable->count();

        if ($blocked === 0) {
            return ActionResponse::message(
                trans_choice('rag.actions.reject.success', $rejected, ['count' => $rejected]),
            );
        }

        if ($rejected === 0) {
            return ActionResponse::danger(
                trans_choice('importance.actions.reject_blocked', $blocked, ['count' => $blocked]),
            );
        }

        return ActionResponse::danger(
            trans_choice('importance.actions.reject_partial', $blocked, [
                'count' => $blocked,
                'rejected' => $rejected,
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
