<?php

use App\Enums\KnowledgeStatus;
use App\Martis\Dashboards\MainDashboard;
use App\Models\KnowledgeEntry;
use App\Models\Project;
use Martis\Metrics\ValueMetric;

/** @return array<string, ValueMetric> */
function dashboardCards(): array
{
    return collect((new MainDashboard)->cards(request()))
        ->keyBy(fn (ValueMetric $metric): string => $metric->uriKey())
        ->all();
}

it('uses the chart icon in its serialized schema', function () {
    expect((new MainDashboard)->toArray()['icon'])->toBe('chart-line-up');
});

it('counts classifying entries separately from the human approval queue', function () {
    Project::create(['id' => 'acme', 'name' => 'Acme', 'root_path' => '/acme']);

    foreach ([KnowledgeStatus::Pending, KnowledgeStatus::Pending, KnowledgeStatus::Classifying] as $i => $status) {
        KnowledgeEntry::create([
            'project_id' => 'acme',
            'title' => "Entry {$i}",
            'status' => $status->value,
        ]);
    }

    $cards = dashboardCards();

    expect($cards)->toHaveKey('classifying-count')
        ->and($cards['classifying-count']->calculate(request())->toArray()['value'])->toBe(1)
        ->and($cards['pending-approvals']->calculate(request())->toArray()['value'])->toBe(2);
});

it('names the classifying card through the translator', function () {
    $card = dashboardCards()['classifying-count'];

    expect($card->name())->toBe(__('importance.dashboard.classifying'))
        ->and($card->name())->not->toBe('importance.dashboard.classifying');
});
