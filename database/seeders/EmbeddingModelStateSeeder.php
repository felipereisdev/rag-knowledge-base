<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class EmbeddingModelStateSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('embedding_model_state')->updateOrInsert(
            ['id' => 1],
            [
                'model_name' => env('RAG_EMBEDDING_MODEL', 'paraphrase-multilingual-mpnet-base-v2'),
                'model_dim' => (int) env('RAG_EMBEDDING_DIM', 768),
                'embedded_at' => now(),
            ]
        );
    }
}
