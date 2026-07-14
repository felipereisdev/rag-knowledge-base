<?php

use App\Enums\KnowledgeStatus;
use App\Martis\Actions\ApproveEntries;
use App\Martis\Actions\RejectEntries;
use App\Models\KnowledgeEntry;
use App\Models\Project;
use Illuminate\Support\Collection;
use Martis\Actions\Action;
use Martis\Actions\ActionFields;

function entryWithStatus(string $status): KnowledgeEntry
{
    Project::firstOrCreate(['id' => 'acme'], ['name' => 'Acme', 'root_path' => '/acme']);

    return KnowledgeEntry::create([
        'project_id' => 'acme',
        'title' => 'Entry '.$status.' '.uniqid(),
        'status' => $status,
    ]);
}

/** @param  Collection<int, KnowledgeEntry>|KnowledgeEntry  $models */
function runAction(Action $action, Collection|KnowledgeEntry $models): array
{
    $collection = $models instanceof KnowledgeEntry ? collect([$models]) : $models;

    return $action->handle(new ActionFields, $collection)->jsonSerialize();
}

describe('ApproveEntries', function () {
    it('approves a pending entry', function () {
        $entry = entryWithStatus(KnowledgeStatus::Pending->value);

        $response = runAction(new ApproveEntries, $entry);

        expect($response['type'])->toBe('message')
            ->and($entry->refresh()->status)->toBe(KnowledgeStatus::Approved->value);
    });

    it('refuses a classifying entry with a translated danger response and leaves it untouched', function () {
        $entry = entryWithStatus(KnowledgeStatus::Classifying->value);

        $response = runAction(new ApproveEntries, $entry);

        expect($response['type'])->toBe('danger')
            ->and($response['data']['message'])->toBe(
                trans_choice('importance.actions.approve_blocked', 1, ['count' => 1])
            )
            ->and($response['data']['message'])->not->toContain('importance.actions')
            ->and($entry->refresh()->status)->toBe(KnowledgeStatus::Classifying->value);
    });

    it('approves the actionable entries of a mixed batch and refuses only the classifying ones', function () {
        $pending = entryWithStatus(KnowledgeStatus::Pending->value);
        $rejected = entryWithStatus(KnowledgeStatus::Rejected->value);
        $classifying = entryWithStatus(KnowledgeStatus::Classifying->value);

        $response = runAction(new ApproveEntries, collect([$pending, $rejected, $classifying]));

        expect($response['type'])->toBe('danger')
            ->and($response['data']['message'])->toBe(
                trans_choice('importance.actions.approve_partial', 1, ['count' => 1, 'approved' => 2])
            )
            ->and($pending->refresh()->status)->toBe(KnowledgeStatus::Approved->value)
            ->and($rejected->refresh()->status)->toBe(KnowledgeStatus::Approved->value)
            ->and($classifying->refresh()->status)->toBe(KnowledgeStatus::Classifying->value);
    });

    it('names itself through the translator', function () {
        expect((new ApproveEntries)->name())->toBe(__('rag.actions.approve.name'))
            ->and((new ApproveEntries)->name())->not->toBe('rag.actions.approve.name');
    });
});

describe('RejectEntries', function () {
    it('rejects a pending entry', function () {
        $entry = entryWithStatus(KnowledgeStatus::Pending->value);

        $response = runAction(new RejectEntries, $entry);

        expect($response['type'])->toBe('message')
            ->and($entry->refresh()->status)->toBe(KnowledgeStatus::Rejected->value);
    });

    it('refuses a classifying entry with a translated danger response and leaves it untouched', function () {
        $entry = entryWithStatus(KnowledgeStatus::Classifying->value);

        $response = runAction(new RejectEntries, $entry);

        expect($response['type'])->toBe('danger')
            ->and($response['data']['message'])->toBe(
                trans_choice('importance.actions.reject_blocked', 1, ['count' => 1])
            )
            ->and($response['data']['message'])->not->toContain('importance.actions')
            ->and($entry->refresh()->status)->toBe(KnowledgeStatus::Classifying->value);
    });

    it('rejects the actionable entries of a mixed batch and refuses only the classifying ones', function () {
        $approved = entryWithStatus(KnowledgeStatus::Approved->value);
        $classifying = entryWithStatus(KnowledgeStatus::Classifying->value);
        $alsoClassifying = entryWithStatus(KnowledgeStatus::Classifying->value);

        $response = runAction(new RejectEntries, collect([$approved, $classifying, $alsoClassifying]));

        expect($response['type'])->toBe('danger')
            ->and($response['data']['message'])->toBe(
                trans_choice('importance.actions.reject_partial', 2, ['count' => 2, 'rejected' => 1])
            )
            ->and($approved->refresh()->status)->toBe(KnowledgeStatus::Rejected->value)
            ->and($classifying->refresh()->status)->toBe(KnowledgeStatus::Classifying->value)
            ->and($alsoClassifying->refresh()->status)->toBe(KnowledgeStatus::Classifying->value);
    });

    it('names itself through the translator', function () {
        expect((new RejectEntries)->name())->toBe(__('rag.actions.reject.name'))
            ->and((new RejectEntries)->name())->not->toBe('rag.actions.reject.name');
    });
});
