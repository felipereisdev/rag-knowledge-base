<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_entries', function (Blueprint $table) {
            $table->id();
            $table->string('project_id');
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->string('title');
            $table->text('content')->default('');
            $table->string('category')->default('insight');
            $table->string('source')->default('manual');
            $table->string('author')->default('');
            $table->string('status')->default('pending');
            $table->jsonb('metadata')->default('{}');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        DB::statement("ALTER TABLE knowledge_entries ADD CONSTRAINT chk_category CHECK (category IN ('business-rule','design-decision','architecture','documentation','insight','convention','constraint'))");
        DB::statement("ALTER TABLE knowledge_entries ADD CONSTRAINT chk_status CHECK (status IN ('pending','approved','rejected'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_entries');
    }
};
