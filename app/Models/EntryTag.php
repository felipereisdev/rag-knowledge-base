<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class EntryTag extends Pivot
{
    protected $fillable = ['entry_id', 'tag_id'];
}