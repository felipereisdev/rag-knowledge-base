<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmbeddingModelState extends Model
{
    public $timestamps = false;

    protected $table = 'embedding_model_state';

    protected $fillable = ['id', 'model_name', 'model_dim', 'embedded_at'];
}