<?php

namespace App\Providers;

use App\Models\Entity;
use App\Models\Relation;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * Routes for /martis/graph are registered here (not in boot()) because
     * the Martis package provider's boot() loads a SPA catch-all at
     * martis/{path} before user providers boot. Registering in register()
     * ensures these specific routes are in the router before the catch-all.
     */
    public function register(): void
    {
        Route::get('/martis/graph', function () {
            return view('martis.graph-explorer');
        })->name('martis.graph');

        Route::get('/martis/graph/data', function (Request $request) {
            $projectId = $request->get('project_id');

            $entityQuery = Entity::query()->select('id', 'name', 'type', 'project_id');
            if ($projectId) {
                $entityQuery->where('project_id', $projectId);
            }
            $entities = $entityQuery->get();

            $entityIds = $entities->pluck('id')->all();

            $relations = Relation::query()
                ->whereIn('subject_id', $entityIds)
                ->whereIn('object_id', $entityIds)
                ->get();

            return response()->json([
                'nodes' => $entities->map(fn ($e) => [
                    'id' => $e->id,
                    'label' => $e->name,
                    'type' => $e->type,
                    'project_id' => $e->project_id,
                ])->all(),
                'edges' => $relations->map(fn ($r) => [
                    'from' => $r->subject_id,
                    'to' => $r->object_id,
                    'label' => $r->predicate,
                ])->all(),
            ]);
        })->name('martis.graph.data');
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

        try {
            $state = DB::table('embedding_model_state')->where('id', 1)->first();
        } catch (QueryException $e) {
            return;
        }

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
