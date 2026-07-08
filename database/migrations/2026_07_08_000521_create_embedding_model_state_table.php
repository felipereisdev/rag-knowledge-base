<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('embedding_model_state', function (Blueprint $table) {
            $table->integer('id')->primary();
            $table->string('model_name');
            $table->integer('model_dim');
            $table->timestampTz('embedded_at')->useCurrent();
        });

        DB::statement("ALTER TABLE embedding_model_state ADD CONSTRAINT chk_singleton CHECK (id = 1)");
    }

    public function down(): void
    {
        Schema::dropIfExists('embedding_model_state');
    }
};
