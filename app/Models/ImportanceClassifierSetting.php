<?php

namespace App\Models;

use App\Enums\ImportanceClassifierMode;
use Illuminate\Database\Eloquent\Model;

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
