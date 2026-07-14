<?php

use App\Jobs\IndexEntryJob;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('importance_classifier_settings', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->string('mode')->default('shadow');
            $table->unsignedTinyInteger('threshold')->default(70);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        DB::statement('ALTER TABLE importance_classifier_settings ADD CONSTRAINT chk_importance_classifier_settings_singleton CHECK (id = 1)');
        DB::statement("ALTER TABLE importance_classifier_settings ADD CONSTRAINT chk_importance_classifier_settings_mode CHECK (mode IN ('off','shadow','enforce'))");
        DB::statement('ALTER TABLE importance_classifier_settings ADD CONSTRAINT chk_importance_classifier_settings_threshold CHECK (threshold BETWEEN 0 AND 100)');

        Schema::create('importance_assessments', function (Blueprint $table) {
            $table->id();
            $table->string('project_id');
            $table->foreign('project_id', 'importance_assessments_project_id_foreign')
                ->references('id')
                ->on('projects')
                ->cascadeOnDelete();
            $table->string('candidate_hash', 64);
            $table->jsonb('normalized_candidate');
            $table->string('model');
            $table->string('prompt_version');
            $table->string('rules_version');
            $table->string('status')->default('running');
            $table->unsignedTinyInteger('durability_score')->nullable();
            $table->unsignedTinyInteger('actionability_score')->nullable();
            $table->unsignedTinyInteger('specificity_score')->nullable();
            $table->unsignedTinyInteger('non_obviousness_score')->nullable();
            $table->unsignedTinyInteger('future_value_score')->nullable();
            $table->unsignedTinyInteger('semantic_score')->nullable();
            $table->unsignedTinyInteger('final_score')->nullable();
            $table->string('verdict')->nullable();
            $table->jsonb('reasons')->default('[]');
            $table->jsonb('rules')->default('[]');
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('error_code')->nullable();
            $table->string('error_message', 500)->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->unique(
                ['project_id', 'candidate_hash', 'model', 'prompt_version', 'rules_version'],
                'importance_assessments_cache_identity_unique',
            );
        });

        DB::statement("ALTER TABLE importance_assessments ADD CONSTRAINT chk_importance_assessments_status CHECK (status IN ('running','succeeded','failed'))");
        DB::statement("ALTER TABLE importance_assessments ADD CONSTRAINT chk_importance_assessments_verdict CHECK (verdict IS NULL OR verdict IN ('important','not_important'))");

        Schema::table('knowledge_entries', function (Blueprint $table) {
            $table->unsignedBigInteger('importance_assessment_id')->nullable();
            $table->index('importance_assessment_id', 'knowledge_entries_importance_assessment_id_index');
            $table->foreign('importance_assessment_id', 'knowledge_entries_importance_assessment_id_foreign')
                ->references('id')
                ->on('importance_assessments')
                ->nullOnDelete();
        });

        DB::statement('ALTER TABLE knowledge_entries DROP CONSTRAINT chk_status');
        DB::statement("ALTER TABLE knowledge_entries ADD CONSTRAINT chk_status CHECK (status IN ('classifying','pending','approved','rejected'))");

        DB::table('importance_classifier_settings')->insert([
            'id' => 1,
            'mode' => 'shadow',
            'threshold' => 70,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Rolling the classifier back removes `classifying` from the allowed
        // status set, but entries legitimately sit there whenever the pipeline
        // is mid-flight -- which is exactly the state an operator is most
        // likely to be rolling back *from* (a stuck or unsupervised
        // `classification` worker). Narrowing the constraint first would abort
        // the whole rollback with a check violation and strand the deployment.
        //
        // Fail open, consistent with the rest of the feature: an entry whose
        // classification never completed is unjudged, not unwanted. Demote it
        // to `pending` so it lands back in the normal human review queue with
        // its content intact. Never delete it.
        //
        // A `classifying` entry has NO chunks (KnowledgeEntryObserver keeps it
        // out of the index until it is released), and this raw update bypasses
        // that observer, so the demotion has to schedule the first indexing
        // pass itself. Without it the entry would sit in `pending` unindexed
        // forever: approving it later does not rescue it either, because the
        // observer's recovery path only fires for a `rejected`/`classifying`
        // predecessor -- so a human approving it would publish an entry that is
        // permanently unsearchable.
        $stranded = DB::table('knowledge_entries')
            ->where('status', 'classifying')
            ->pluck('id');

        DB::table('knowledge_entries')
            ->where('status', 'classifying')
            ->update(['status' => 'pending']);

        foreach ($stranded as $id) {
            IndexEntryJob::dispatch((int) $id)->afterCommit();
        }

        Schema::table('knowledge_entries', function (Blueprint $table) {
            $table->dropForeign('knowledge_entries_importance_assessment_id_foreign');
            $table->dropIndex('knowledge_entries_importance_assessment_id_index');
            $table->dropColumn('importance_assessment_id');
        });

        DB::statement('ALTER TABLE knowledge_entries DROP CONSTRAINT chk_status');
        DB::statement("ALTER TABLE knowledge_entries ADD CONSTRAINT chk_status CHECK (status IN ('pending','approved','rejected'))");

        Schema::dropIfExists('importance_assessments');
        Schema::dropIfExists('importance_classifier_settings');
    }
};
