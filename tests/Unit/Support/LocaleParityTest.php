<?php

/**
 * Every locale must expose exactly the same key set: a key present in `en` but
 * missing from `pt_PT` silently renders the raw key ("importance.fields.mode")
 * to a Portuguese administrator.
 */
function flattenKeys(array $translations, string $prefix = ''): array
{
    $keys = [];

    foreach ($translations as $key => $value) {
        $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

        if (is_array($value) && $value !== [] && ! array_is_list($value)) {
            $keys = array_merge($keys, flattenKeys($value, $path));

            continue;
        }

        $keys[] = $path;
    }

    sort($keys);

    return $keys;
}

it('keeps the same key set in every locale', function (string $file) {
    $locales = ['en', 'pt_PT', 'pt_BR'];
    $keySets = [];

    foreach ($locales as $locale) {
        $path = lang_path("{$locale}/{$file}.php");

        expect(file_exists($path))->toBeTrue("Missing lang file: {$locale}/{$file}.php");

        $keySets[$locale] = flattenKeys(require $path);
    }

    expect($keySets['pt_PT'])->toBe($keySets['en'])
        ->and($keySets['pt_BR'])->toBe($keySets['en'])
        ->and($keySets['en'])->not->toBeEmpty();
})->with(['importance', 'rag', 'condense']);
