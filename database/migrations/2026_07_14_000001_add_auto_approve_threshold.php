<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the auto-approval dial to the classifier singleton.
 *
 * Nullable on purpose: `null` means auto-approval is OFF while rejection
 * (in `enforce`) keeps working. It is the escape hatch — auto-approval can be
 * switched off without giving up the noise filter.
 *
 * The 90 default means flipping to `enforce` turns on rejection AND approval
 * together. That is only safe because the readiness report gates both.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('importance_classifier_settings', function (Blueprint $table): void {
            $table->smallInteger('auto_approve_threshold')->nullable()->default(90);
        });

        DB::table('importance_classifier_settings')
            ->where('id', 1)
            ->update(['auto_approve_threshold' => 90]);
    }

    public function down(): void
    {
        Schema::table('importance_classifier_settings', function (Blueprint $table): void {
            $table->dropColumn('auto_approve_threshold');
        });
    }
};
