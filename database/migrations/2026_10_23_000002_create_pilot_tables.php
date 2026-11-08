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
        Schema::create('pilot_participants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->restrictOnDelete();
            $table->string('anonymous_code', 64)->unique();
            $table->string('invite_token_hash', 64);
            $table->string('subject_area', 100);
            $table->smallInteger('teaching_experience_years');
            $table->boolean('is_submitted')->default(false);
            $table->timestampTz('expires_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampTz('used_at')->nullable();
            $table->timestampsTz();

            $table->index('school_id', 'idx_pilot_participants_school');
            $table->index('invite_token_hash', 'idx_pilot_participants_token_hash');
        });

        Schema::create('pilot_responses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('participant_id')->unique()->constrained('pilot_participants')->cascadeOnDelete();
            $table->foreignUuid('scenario_id')->constrained('scenarios')->restrictOnDelete();
            $table->integer('prep_time_baseline_minutes');
            $table->integer('prep_time_ai_minutes');
            $table->decimal('relative_time_saving', 5, 4); // (baseline - ai) / baseline
            $table->boolean('personalization_selected');
            $table->decimal('manual_edit_percentage', 5, 2); // 0.00 to 100.00
            $table->boolean('cultural_context_used');
            $table->smallInteger('cultural_usefulness_score')->nullable();
            $table->text('feedback_text')->nullable();
            $table->timestampTz('submitted_at')->useCurrent();
            $table->timestampsTz();

            $table->index('scenario_id', 'idx_pilot_responses_scenario');
        });

        DB::statement("ALTER TABLE pilot_responses ADD CONSTRAINT chk_pilot_responses_baseline CHECK (prep_time_baseline_minutes > 0)");
        DB::statement("ALTER TABLE pilot_responses ADD CONSTRAINT chk_pilot_responses_ai CHECK (prep_time_ai_minutes >= 0)");
        DB::statement("ALTER TABLE pilot_responses ADD CONSTRAINT chk_pilot_responses_manual_edit CHECK (manual_edit_percentage >= 0 AND manual_edit_percentage <= 100)");
        DB::statement("ALTER TABLE pilot_responses ADD CONSTRAINT chk_pilot_responses_cultural_score CHECK (cultural_usefulness_score IS NULL OR (cultural_usefulness_score >= 1 AND cultural_usefulness_score <= 5))");

        Schema::create('pilot_review_findings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('pilot_response_id')->constrained('pilot_responses')->cascadeOnDelete();
            $table->foreignUuid('expert_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('expert_name', 255);
            $table->string('expert_audit_status', 32); // 'pending_audit', 'audited', 'rejected'
            $table->integer('critical_cultural_errors')->default(0);
            $table->text('methodological_remarks')->nullable();
            $table->timestampTz('reviewed_at')->nullable();
            $table->timestampsTz();

            $table->index('pilot_response_id', 'idx_pilot_review_findings_response');
            $table->index('expert_user_id', 'idx_pilot_review_findings_expert');
        });

        DB::statement("ALTER TABLE pilot_review_findings ADD CONSTRAINT chk_pilot_review_audit_status CHECK (expert_audit_status IN ('pending_audit', 'audited', 'rejected'))");
        DB::statement("ALTER TABLE pilot_review_findings ADD CONSTRAINT chk_pilot_review_critical_errors CHECK (critical_cultural_errors >= 0)");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pilot_review_findings');
        Schema::dropIfExists('pilot_responses');
        Schema::dropIfExists('pilot_participants');
    }
};
