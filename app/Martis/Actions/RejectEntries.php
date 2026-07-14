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

class RejectEntries extends Action
{
    use RefusesClassifyingEntries;

    public function name(): string
    {
        return __('rag.actions.reject.name');
    }

    /**
     * Reject the selected knowledge entries so they stay out of search.
     *
     * An entry still in `classifying` is refused, for the same reason approval
     * refuses it: the classifier job owns that status and its transition guard
     * would overwrite the human's decision when it lands. See
     * {@see RefusesClassifyingEntries}.
     *
     * @param  Collection<int, Model>  $models
     */
    public function handle(ActionFields $fields, Collection $models): ActionResponse|Action|null
    {
        [$classifying, $actionable] = $this->partitionClassifying($models);

        foreach ($actionable as $model) {
            $model->update(['status' => KnowledgeStatus::Rejected->value]);
        }

        return $this->respondToClassifyingGuard(
            $classifying,
            $actionable->count(),
            successKey: 'rag.actions.reject.success',
            blockedKey: 'importance.actions.reject_blocked',
            partialKey: 'importance.actions.reject_partial',
            partialActionedParam: 'rejected',
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
