<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CondenseRun extends Model
{
    protected $fillable = ['session_id', 'project_id', 'status', 'entries_created'];

    protected function casts(): array
    {
        return ['entries_created' => 'integer'];
    }
}
