<?php

namespace App\Models;

use App\Enums\ImportanceClassifierMode;
use Illuminate\Database\Eloquent\Model;

/**
 * The administrator-editable singleton (row id 1) that governs the classifier.
 *
 * @property int $id
 * @property ImportanceClassifierMode $mode
 * @property int $threshold
 * @property ?int $auto_approve_threshold
 */
class ImportanceClassifierSetting extends Model
{
    protected $fillable = ['mode', 'threshold', 'auto_approve_threshold'];

    protected $attributes = [
        'mode' => 'shadow',
        'threshold' => 70,
        'auto_approve_threshold' => 90,
    ];

    protected function casts(): array
    {
        return [
            'mode' => ImportanceClassifierMode::class,
            'threshold' => 'integer',
            'auto_approve_threshold' => 'integer',
        ];
    }

    /**
     * The singleton, or an unsaved instance carrying the code defaults when the
     * row is missing. Read-only by design: nothing on the ingestion path should
     * create administrator settings as a side effect of storing an entry.
     */
    public static function current(): self
    {
        return static::query()->find(1) ?? new self;
    }
}
