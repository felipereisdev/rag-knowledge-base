<?php

use App\Models\CondenseRun;
use Illuminate\Database\QueryException;

it('enforces a unique session_id', function () {
    CondenseRun::create(['session_id' => 's1', 'project_id' => 'p1', 'status' => 'running']);

    expect(fn () => CondenseRun::create(['session_id' => 's1', 'project_id' => 'p1', 'status' => 'running']))
        ->toThrow(QueryException::class);
});
