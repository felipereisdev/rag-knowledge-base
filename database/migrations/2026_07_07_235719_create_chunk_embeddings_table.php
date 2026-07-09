<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Enable pgvector extension
        DB::statement('CREATE EXTENSION IF NOT EXISTS vector');

        Schema::create('chunk_embeddings', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('entry_id');
            $table->foreign('entry_id')->references('id')->on('knowledge_entries')->cascadeOnDelete();
            $table->string('project_id');
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->integer('chunk_index')->default(0);
            $table->text('content');
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['entry_id', 'chunk_index']);
        });

        // Add vector column via raw SQL (Laravel schema builder doesn't support pgvector natively)
        DB::statement('ALTER TABLE chunk_embeddings ADD COLUMN embedding vector(768) NOT NULL');

        // HNSW index for cosine similarity
        DB::statement('CREATE INDEX idx_chunk_embeddings_vector ON chunk_embeddings USING hnsw (embedding vector_cosine_ops)');

        Schema::table('chunk_embeddings', function (Blueprint $table) {
            $table->index('project_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chunk_embeddings');
    }
};
