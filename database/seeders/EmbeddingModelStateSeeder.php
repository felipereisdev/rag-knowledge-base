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
                'provider_name' => (string) config('rag.embeddings.provider', 'local-embedder'),
                'model_name' => (string) config('rag.embeddings.model', 'paraphrase-multilingual-mpnet-base-v2'),
                'model_dim' => (int) config('rag.embeddings.dimension', 768),
                'embedded_at' => now(),
            ]
        );
    }
}
