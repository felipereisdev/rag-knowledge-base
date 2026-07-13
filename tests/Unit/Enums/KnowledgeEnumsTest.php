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
        'pending',
        'approved',
        'rejected',
    ])->and(KnowledgeStatus::options()['pending'])->toBe('Pending');
});

it('provides project language values and translated options', function () {
    expect(ProjectLanguage::values())->toBe([
        'en',
        'pt',
        'es',
    ])->and(ProjectLanguage::options()['en'])->toBe('English');
});
