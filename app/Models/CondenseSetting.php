<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CondenseSetting extends Model
{
    protected $fillable = [
        'enabled', 'driver', 'provider', 'model',
        'min_dedup_score', 'max_transcript_chars', 'system_prompt_override',
    ];

    protected $attributes = [
        'enabled' => true,
        'driver' => 'claude_sdk',
        'provider' => null,
        'model' => 'claude-haiku-4-5-20251001',
        'min_dedup_score' => 0.85,
        'max_transcript_chars' => 24000,
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'min_dedup_score' => 'float',
            'max_transcript_chars' => 'integer',
        ];
    }

    public static function current(): self
    {
        return static::query()->first() ?? static::create([]);
    }
}
