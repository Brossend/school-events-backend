<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('scenario_comparisons', function (Blueprint $table) {
            $table->foreignUuid('selected_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_synthetic')->default(false)->index('idx_scenario_comparisons_synthetic');
        });

        Schema::table('pilot_participants', function (Blueprint $table) {
            $table->boolean('is_synthetic')->default(false)->index('idx_pilot_participants_synthetic');
        });

        Schema::table('pilot_responses', function (Blueprint $table) {
            $table->foreignUuid('comparison_id')->nullable()->constrained('scenario_comparisons')->nullOnDelete();
            $table->boolean('is_synthetic')->default(false)->index('idx_pilot_responses_synthetic');
        });

        Schema::table('pilot_review_findings', function (Blueprint $table) {
            $table->integer('finding_version')->default(1);
            $table->boolean('is_effective')->default(true)->index('idx_pilot_review_findings_effective');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pilot_review_findings', function (Blueprint $table) {
            $table->dropIndex('idx_pilot_review_findings_effective');
            $table->dropColumn(['finding_version', 'is_effective']);
        });

        Schema::table('pilot_responses', function (Blueprint $table) {
            $table->dropForeign(['comparison_id']);
            $table->dropIndex('idx_pilot_responses_synthetic');
            $table->dropColumn(['comparison_id', 'is_synthetic']);
        });

        Schema::table('pilot_participants', function (Blueprint $table) {
            $table->dropIndex('idx_pilot_participants_synthetic');
            $table->dropColumn(['is_synthetic']);
        });

        Schema::table('scenario_comparisons', function (Blueprint $table) {
            $table->dropForeign(['selected_by_user_id']);
            $table->dropIndex('idx_scenario_comparisons_synthetic');
            $table->dropColumn(['selected_by_user_id', 'is_synthetic']);
        });
    }
};
