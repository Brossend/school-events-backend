<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('scenario_comparisons', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->restrictOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('scenario_request_id')->constrained('scenario_requests')->restrictOnDelete();
            $table->string('comparison_type', 32); // 'universal_vs_personalized', 'cultural_context'
            $table->string('status', 32)->default('completed'); // 'pending', 'completed', 'failed'
            $table->foreignUuid('variant_a_scenario_id')->nullable()->constrained('scenarios')->nullOnDelete();
            $table->foreignUuid('variant_a_revision_id')->nullable()->constrained('scenario_revisions')->nullOnDelete();
            $table->jsonb('variant_a_payload');
            $table->foreignUuid('variant_b_scenario_id')->nullable()->constrained('scenarios')->nullOnDelete();
            $table->foreignUuid('variant_b_revision_id')->nullable()->constrained('scenario_revisions')->nullOnDelete();
            $table->jsonb('variant_b_payload');
            $table->jsonb('differences_summary')->default('{}');
            $table->boolean('is_cultural_available')->default(true);
            $table->string('selected_variant', 8)->nullable(); // 'A', 'B'
            $table->text('selection_reason')->nullable();
            $table->smallInteger('cultural_usefulness_rating')->nullable();
            $table->timestampTz('selection_recorded_at')->nullable();
            $table->timestampsTz();

            $table->index('school_id', 'idx_scenario_comparisons_school');
            $table->index('scenario_request_id', 'idx_scenario_comparisons_request');
            $table->index('user_id', 'idx_scenario_comparisons_user');
        });

        DB::statement("ALTER TABLE scenario_comparisons ADD CONSTRAINT chk_scenario_comparisons_type CHECK (comparison_type IN ('universal_vs_personalized', 'cultural_context'))");
        DB::statement("ALTER TABLE scenario_comparisons ADD CONSTRAINT chk_scenario_comparisons_status CHECK (status IN ('pending', 'completed', 'failed'))");
        DB::statement("ALTER TABLE scenario_comparisons ADD CONSTRAINT chk_scenario_comparisons_selected CHECK (selected_variant IS NULL OR selected_variant IN ('A', 'B'))");
        DB::statement("ALTER TABLE scenario_comparisons ADD CONSTRAINT chk_scenario_comparisons_rating CHECK (cultural_usefulness_rating IS NULL OR (cultural_usefulness_rating >= 1 AND cultural_usefulness_rating <= 5))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scenario_comparisons');
    }
};
