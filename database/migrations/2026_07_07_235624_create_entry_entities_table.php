<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entry_entities', function (Blueprint $table) {
            $table->bigInteger('entry_id');
            $table->foreign('entry_id')->references('id')->on('knowledge_entries')->cascadeOnDelete();
            $table->bigInteger('entity_id');
            $table->foreign('entity_id')->references('id')->on('entities')->cascadeOnDelete();
            $table->primary(['entry_id', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entry_entities');
    }
};
