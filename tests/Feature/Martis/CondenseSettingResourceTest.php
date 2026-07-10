<?php

use App\Martis\Resources\CondenseSettingResource;
use App\Models\CondenseSetting;
use Illuminate\Http\Request;

it('targets the CondenseSetting model and is a singleton (no create/delete)', function () {
    expect(CondenseSettingResource::model())->toBe(CondenseSetting::class);

    $resource = new CondenseSettingResource(new CondenseSetting);
    $req = Request::create('/martis/resources/condense-settings', 'GET');

    expect($resource->authorizedToCreate($req))->toBeFalse();
    expect($resource->authorizedToDelete($req))->toBeFalse();
});

it('exposes the settings fields', function () {
    $resource = new CondenseSettingResource(new CondenseSetting);
    $names = collect($resource->fields(Request::create('/', 'GET')))
        ->map(fn ($f) => $f->attribute())
        ->filter()
        ->all();

    expect($names)->toContain(
        'enabled',
        'driver',
        'provider',
        'model',
        'min_dedup_score',
        'max_transcript_chars',
        'system_prompt_override',
    );
});
