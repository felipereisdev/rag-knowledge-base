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
 */
class ImportanceClassifierSetting extends Model
{
    protected $fillable = ['mode', 'threshold'];

    protected $attributes = [
        'mode' => 'shadow',
        'threshold' => 70,
    ];

    protected function casts(): array
    {
        return [
            'mode' => ImportanceClassifierMode::class,
            'threshold' => 'integer',
        ];
    }
}
