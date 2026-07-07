<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entry_tags', function (Blueprint $table) {
            $table->uuid('entry_id');
            $table->foreign('entry_id')->references('id')->on('knowledge_entries')->cascadeOnDelete();
            $table->bigInteger('tag_id');
            $table->foreign('tag_id')->references('id')->on('tags')->cascadeOnDelete();
            $table->primary(['entry_id', 'tag_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entry_tags');
    }
};
