<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('relations', function (Blueprint $table) {
            $table->id();
            $table->string('project_id');
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->bigInteger('subject_id');
            $table->foreign('subject_id')->references('id')->on('entities')->cascadeOnDelete();
            $table->string('predicate');
            $table->bigInteger('object_id');
            $table->foreign('object_id')->references('id')->on('entities')->cascadeOnDelete();
            $table->uuid('entry_id')->nullable();
            $table->foreign('entry_id')->references('id')->on('knowledge_entries')->cascadeOnDelete();
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::statement("CREATE UNIQUE INDEX idx_relations_unique ON relations (project_id, subject_id, predicate, object_id, COALESCE(entry_id, '00000000-0000-0000-0000-000000000000'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('relations');
    }
};
