<?php

use App\Models\CondenseSetting;

it('returns a singleton row with sane defaults', function () {
    $s = CondenseSetting::current();

    expect($s->enabled)->toBeTrue();
    expect($s->driver)->toBe('claude_sdk');
    expect($s->provider)->toBeNull();
    expect($s->model)->toBe('claude-haiku-4-5-20251001');
    expect($s->min_dedup_score)->toBe(0.85);
    expect($s->max_transcript_chars)->toBe(24000);

    // idempotent: no second row is created
    CondenseSetting::current();
    expect(CondenseSetting::count())->toBe(1);
});
