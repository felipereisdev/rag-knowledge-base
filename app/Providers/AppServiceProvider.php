<?php

namespace App\Providers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->checkEmbeddingModelVersion();
    }

    private function checkEmbeddingModelVersion(): void
    {
        if (! app()->runningInConsole()) {
            return;
        }

        $configuredModel = config('app.rag_embedding_model', 'paraphrase-multilingual-mpnet-base-v2');
        $configuredDim = (int) config('app.rag_embedding_dim', 768);

        $state = DB::table('embedding_model_state')->where('id', 1)->first();

        if (! $state) {
            DB::table('embedding_model_state')->insert([
                'id' => 1,
                'model_name' => $configuredModel,
                'model_dim' => $configuredDim,
                'embedded_at' => now(),
            ]);

            return;
        }

        if ($state->model_name !== $configuredModel || $state->model_dim !== $configuredDim) {
            Log::warning('Embedding model changed, marking all chunks for re-embedding', [
                'old' => $state->model_name,
                'new' => $configuredModel,
            ]);

            DB::table('chunk_embeddings')->truncate();
            DB::table('embedding_model_state')
                ->where('id', 1)
                ->update([
                    'model_name' => $configuredModel,
                    'model_dim' => $configuredDim,
                    'embedded_at' => now(),
                ]);
        }
    }
}
