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
                'model_name' => config('app.rag_embedding_model', 'paraphrase-multilingual-mpnet-base-v2'),
                'model_dim' => (int) config('app.rag_embedding_dim', 768),
                'embedded_at' => now(),
            ]
        );
    }
}
