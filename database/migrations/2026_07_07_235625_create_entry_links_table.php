<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entry_links', function (Blueprint $table) {
            $table->bigInteger('from_entry');
            $table->foreign('from_entry')->references('id')->on('knowledge_entries')->cascadeOnDelete();
            $table->bigInteger('to_entry');
            $table->foreign('to_entry')->references('id')->on('knowledge_entries')->cascadeOnDelete();
            $table->string('relation')->default('related');
            $table->primary(['from_entry', 'to_entry', 'relation']);
        });

        DB::statement('ALTER TABLE entry_links ADD CONSTRAINT chk_no_self_link CHECK (from_entry <> to_entry)');
    }

    public function down(): void
    {
        Schema::dropIfExists('entry_links');
    }
};
