<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('condense_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('enabled')->default(true);
            $table->string('driver')->default('claude_sdk');
            $table->string('provider')->nullable();
            $table->string('model')->default('claude-haiku-4-5-20251001');
            $table->float('min_dedup_score')->default(0.85);
            $table->integer('max_transcript_chars')->default(24000);
            $table->text('system_prompt_override')->nullable();
            $table->timestamps();
        });

        DB::table('condense_settings')->insert([
            'enabled' => true,
            'driver' => 'claude_sdk',
            'model' => 'claude-haiku-4-5-20251001',
            'min_dedup_score' => 0.85,
            'max_transcript_chars' => 24000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('condense_settings');
    }
};
