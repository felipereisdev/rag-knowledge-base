<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('condense_runs', function (Blueprint $table) {
            $table->id();
            $table->string('session_id')->unique();
            $table->string('project_id');
            $table->string('status')->default('running');
            $table->integer('entries_created')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('condense_runs');
    }
};
