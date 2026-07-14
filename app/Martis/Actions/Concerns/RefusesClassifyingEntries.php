<?php

namespace App\Martis\Actions\Concerns;

use App\Enums\KnowledgeStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Martis\Actions\ActionResponse;

/**
 * Shared guard for the KnowledgeEntry bulk actions: an entry still in
 * `classifying` is refused because the classifier job owns that status and
 * its transition guard would overwrite a human decision moments later — see
 * `KnowledgeStatus::adminEditable()`.
 *
 * `App\Martis\Actions\ApproveEntries` and `App\Martis\Actions\RejectEntries`
 * are otherwise identical: partition the selection into blocked/actionable,
 * apply their own transition to the actionable half, then report one of
 * three shapes depending on how many were blocked. This trait owns the
 * partition and the response shaping; the action supplies its own
 * transition and its verb-specific translation keys.
 */
trait RefusesClassifyingEntries
{
    /**
     * @param  Collection<int, Model>  $models
     * @return Collection<int<0, 1>, Collection<int, Model>> [classifying, actionable]
     */
    private function partitionClassifying(Collection $models): Collection
    {
        return $models->partition(
            static fn (Model $model): bool => $model->getAttribute('status') === KnowledgeStatus::Classifying->value,
        );
    }

    /**
     * @param  Collection<int, Model>  $classifying  entries refused because they are still classifying
     * @param  int  $actioned  how many entries the action actually applied to
     * @param  string  $successKey  translation key when nothing was blocked
     * @param  string  $blockedKey  translation key when everything was blocked
     * @param  string  $partialKey  translation key when some were blocked
     * @param  string  $partialActionedParam  the extra `:placeholder` name the partial message uses for $actioned
     */
    private function respondToClassifyingGuard(
        Collection $classifying,
        int $actioned,
        string $successKey,
        string $blockedKey,
        string $partialKey,
        string $partialActionedParam,
    ): ActionResponse {
        $blocked = $classifying->count();

        if ($blocked === 0) {
            return ActionResponse::message(
                trans_choice($successKey, $actioned, ['count' => $actioned]),
            );
        }

        if ($actioned === 0) {
            return ActionResponse::danger(
                trans_choice($blockedKey, $blocked, ['count' => $blocked]),
            );
        }

        return ActionResponse::danger(
            trans_choice($partialKey, $blocked, [
                'count' => $blocked,
                $partialActionedParam => $actioned,
            ]),
        );
    }
}
