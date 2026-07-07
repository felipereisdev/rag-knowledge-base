<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entities', function (Blueprint $table) {
            $table->id();
            $table->string('project_id');
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->string('name');
            $table->string('type')->default('');
            $table->unique(['project_id', 'name', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entities');
    }
};
