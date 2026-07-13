<?php

namespace App\Providers;

use App\Models\Entity;
use App\Models\Relation;
use App\Services\Importance\ClaudeImportanceJudge;
use App\Services\Importance\DeterministicImportanceRules;
use App\Services\Importance\HybridImportanceClassifier;
use App\Services\Importance\ImportanceCandidateNormalizer;
use App\Services\Importance\ImportancePrompt;
use App\Services\Importance\ImportanceResponseParser;
use App\Services\Importance\SemanticImportanceJudge;
use App\Services\Install\ClientInstaller;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
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
        $this->app->bind(ClientInstaller::class, fn () => new ClientInstaller(base_path('stubs/client')));

        $this->app->bind(SemanticImportanceJudge::class, fn () => new ClaudeImportanceJudge(
            new ImportancePrompt,
            new ImportanceResponseParser(
                maxReasonCount: (int) config('rag.importance.max_reason_count'),
                maxReasonLength: (int) config('rag.importance.max_reason_length'),
            ),
            model: (string) config('rag.importance.model'),
            timeoutSeconds: (int) config('rag.importance.timeout'),
        ));

        $this->app->bind(HybridImportanceClassifier::class, fn ($app) => new HybridImportanceClassifier(
            $app->make(ImportanceCandidateNormalizer::class),
            new DeterministicImportanceRules((string) config('rag.importance.rules_version')),
            $app->make(SemanticImportanceJudge::class),
            model: (string) config('rag.importance.model'),
            promptVersion: (string) config('rag.importance.prompt_version'),
        ));

        $embeddingProvider = (string) config('rag.embeddings.provider', 'local-embedder');
        $embeddingDimension = (int) config('rag.embeddings.dimension', 768);

        if ($embeddingDimension !== 768) {
            throw new \InvalidArgumentException(
                'RAG_EMBEDDING_DIM must be 768 because chunk_embeddings.embedding is vector(768); variable embedding dimensions are not supported.',
            );
        }

        config([
            "ai.providers.{$embeddingProvider}.models.embeddings.default" => (string) config('rag.embeddings.model', 'paraphrase-multilingual-mpnet-base-v2'),
            "ai.providers.{$embeddingProvider}.models.embeddings.dimensions" => $embeddingDimension,
        ]);

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

        $configuredProvider = (string) config('rag.embeddings.provider', 'local-embedder');
        $configuredModel = (string) config('rag.embeddings.model', 'paraphrase-multilingual-mpnet-base-v2');
        $configuredDim = (int) config('rag.embeddings.dimension', 768);

        try {
            $state = DB::table('embedding_model_state')->where('id', 1)->first();
        } catch (QueryException $e) {
            return;
        }

        if (! Schema::hasColumn('embedding_model_state', 'provider_name')) {
            return;
        }

        if (! $state) {
            DB::table('embedding_model_state')->insert([
                'id' => 1,
                'provider_name' => $configuredProvider,
                'model_name' => $configuredModel,
                'model_dim' => $configuredDim,
                'embedded_at' => now(),
            ]);

            return;
        }

        if ($state->provider_name !== $configuredProvider || $state->model_name !== $configuredModel || $state->model_dim !== $configuredDim) {
            Log::warning('Embedding configuration changed, marking all chunks for re-embedding', [
                'old' => [
                    'provider' => $state->provider_name,
                    'model' => $state->model_name,
                    'dimension' => $state->model_dim,
                ],
                'new' => [
                    'provider' => $configuredProvider,
                    'model' => $configuredModel,
                    'dimension' => $configuredDim,
                ],
            ]);

            DB::table('chunk_embeddings')->truncate();
            DB::table('embedding_model_state')
                ->where('id', 1)
                ->update([
                    'provider_name' => $configuredProvider,
                    'model_name' => $configuredModel,
                    'model_dim' => $configuredDim,
                    'embedded_at' => now(),
                ]);
        }
    }
}
