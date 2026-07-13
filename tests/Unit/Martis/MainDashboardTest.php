<?php

use App\Martis\Dashboards\MainDashboard;

it('uses the chart icon in its serialized schema', function () {
    expect((new MainDashboard)->toArray()['icon'])->toBe('chart-line-up');
});
