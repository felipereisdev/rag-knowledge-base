<?php

use App\Enums\KnowledgeCategory;
use App\Enums\KnowledgeStatus;
use App\Enums\ProjectLanguage;

beforeEach(function () {
    app()->setLocale('en');
});

it('provides knowledge category values and translated options', function () {
    expect(KnowledgeCategory::values())->toBe([
        'business-rule',
        'design-decision',
        'architecture',
        'documentation',
        'insight',
        'convention',
        'constraint',
    ])->and(KnowledgeCategory::options()['business-rule'])->toBe('Business Rule');
});

it('provides knowledge status values and translated options', function () {
    expect(KnowledgeStatus::values())->toBe([
        'classifying',
        'pending',
        'approved',
        'rejected',
    ])->and(KnowledgeStatus::options()['pending'])->toBe('Pending');
});

it('provides project language values and translated options', function () {
    expect(ProjectLanguage::values())->toBe([
        'en',
        'pt',
        'pt-BR',
        'pt_PT',
        'es',
    ])->and(ProjectLanguage::options()['en'])->toBe('English');
});

it('keeps rag translation keys identical across supported locales', function () {
    $flattenKeys = function (array $translations, string $prefix = '') use (&$flattenKeys): array {
        $keys = [];

        foreach ($translations as $key => $value) {
            $qualifiedKey = $prefix === '' ? $key : $prefix.'.'.$key;
            $keys = [
                ...$keys,
                ...(is_array($value) ? $flattenKeys($value, $qualifiedKey) : [$qualifiedKey]),
            ];
        }

        return $keys;
    };

    $english = $flattenKeys(require lang_path('en/rag.php'));

    expect($flattenKeys(require lang_path('pt_PT/rag.php')))->toBe($english)
        ->and($flattenKeys(require lang_path('pt_BR/rag.php')))->toBe($english);
});
