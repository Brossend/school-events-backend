<?php

declare(strict_types=1);

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
        // 1. scenario_requests
        Schema::create('scenario_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->restrictOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('calendar_event_id')->nullable()->constrained('calendar_events')->nullOnDelete();
            $table->foreignUuid('tradition_version_id')->nullable()->constrained('tradition_versions')->nullOnDelete();
            $table->string('custom_event_title', 255)->nullable();
            $table->string('scale', 32);
            $table->string('format_type', 64);
            $table->jsonb('target_grades');
            $table->integer('participants_count');
            $table->integer('duration_minutes');
            $table->string('value_direction', 32);
            $table->boolean('family_involvement')->default(false);
            $table->jsonb('available_resources')->default('[]');
            $table->text('pedagogical_constraints')->nullable();
            $table->jsonb('context_snapshot');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['school_id', 'user_id', 'created_at'], 'idx_requests_school');
        });

        DB::statement("ALTER TABLE scenario_requests ADD CONSTRAINT chk_request_scale CHECK (scale IN ('class_level', 'all_school', 'family_community'))");
        DB::statement("ALTER TABLE scenario_requests ADD CONSTRAINT chk_request_format CHECK (format_type IN ('class_hour', 'quest', 'quiz', 'workshop', 'festival', 'roundtable'))");
        DB::statement("ALTER TABLE scenario_requests ADD CONSTRAINT chk_request_value_direction CHECK (value_direction IN ('family', 'mutual_aid', 'historical_memory', 'labor_and_creation', 'cultural_diversity', 'civic_responsibility'))");
        DB::statement('ALTER TABLE scenario_requests ADD CONSTRAINT chk_request_participants CHECK (participants_count >= 5 AND participants_count <= 500)');
        DB::statement('ALTER TABLE scenario_requests ADD CONSTRAINT chk_request_duration CHECK (duration_minutes >= 20 AND duration_minutes <= 240)');
        DB::statement('ALTER TABLE scenario_requests ADD CONSTRAINT chk_request_single_occasion CHECK ((CASE WHEN calendar_event_id IS NOT NULL THEN 1 ELSE 0 END + CASE WHEN tradition_version_id IS NOT NULL THEN 1 ELSE 0 END + CASE WHEN custom_event_title IS NOT NULL THEN 1 ELSE 0 END) = 1)');

        // 2. generation_runs
        Schema::create('generation_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('scenario_request_id')->constrained('scenario_requests')->cascadeOnDelete();
            $table->string('provider_name', 64);
            $table->string('model_identifier', 64);
            $table->string('prompt_version', 32);
            $table->string('schema_version', 32);
            $table->string('status', 32)->default('queued');
            $table->smallInteger('attempts')->default(1);
            $table->integer('latency_ms')->nullable();
            $table->integer('prompt_tokens')->nullable();
            $table->integer('completion_tokens')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->text('sanitized_prompt')->nullable();
            $table->jsonb('sanitized_response')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('completed_at')->nullable();

            $table->index(['scenario_request_id', 'created_at'], 'idx_generation_runs_request');
        });

        DB::statement("ALTER TABLE generation_runs ADD CONSTRAINT chk_generation_status CHECK (status IN ('queued', 'running', 'completed', 'failed', 'timed_out'))");
        DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS uq_generation_runs_single_active ON generation_runs (scenario_request_id) WHERE status IN ('queued', 'running')");

        // 3. idempotency_keys
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key', 64);
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('endpoint', 255);
            $table->string('payload_sha256', 64);
            $table->string('status', 32)->default('processing');
            $table->integer('response_code')->nullable();
            $table->jsonb('response_body')->nullable();
            $table->timestampTz('expires_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['user_id', 'endpoint', 'key'], 'uq_idempotency_user_endpoint_key');
        });

        DB::statement("ALTER TABLE idempotency_keys ADD CONSTRAINT chk_idempotency_status CHECK (status IN ('processing', 'completed', 'failed'))");

        // 4. scenarios
        Schema::create('scenarios', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->restrictOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('scenario_request_id')->unique()->constrained('scenario_requests')->restrictOnDelete();
            $table->string('title', 255);
            $table->uuid('current_revision_id')->nullable();
            $table->integer('optimistic_lock_version')->default(1);
            $table->boolean('is_archived')->default(false);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->index(['school_id', 'created_at'], 'idx_scenarios_school');
        });

        // 5. scenario_revisions
        Schema::create('scenario_revisions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('scenario_id')->constrained('scenarios')->cascadeOnDelete();
            $table->integer('version_number');
            $table->foreignUuid('source_generation_run_id')->nullable()->constrained('generation_runs')->nullOnDelete();
            $table->foreignUuid('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->jsonb('content_payload');
            $table->text('concept');
            $table->text('pedagogical_goal');
            $table->jsonb('pedagogical_mechanism');
            $table->jsonb('pedagogical_tasks');
            $table->jsonb('expected_outcomes');
            $table->jsonb('target_audience');
            $table->jsonb('format');
            $table->integer('total_duration_minutes');
            $table->jsonb('teacher_instructions');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['scenario_id', 'version_number'], 'uq_scenario_revision_version');
        });

        // Add deferred cyclic foreign key from scenarios.current_revision_id -> scenario_revisions.id
        DB::statement('ALTER TABLE scenarios ADD CONSTRAINT fk_scenarios_current_revision FOREIGN KEY (current_revision_id) REFERENCES scenario_revisions (id) ON DELETE SET NULL DEFERRABLE INITIALLY DEFERRED');

        // 6. scenario_stages
        Schema::create('scenario_stages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('revision_id')->constrained('scenario_revisions')->cascadeOnDelete();
            $table->smallInteger('order_index');
            $table->string('stage_identifier', 64);
            $table->string('title', 255);
            $table->integer('duration_minutes');
            $table->string('stage_type', 64);
            $table->text('teacher_actions');
            $table->text('student_actions');
            $table->jsonb('dialogue_prompts');
            $table->text('teacher_tips')->nullable();
            $table->text('transition_text')->nullable();

            $table->unique(['revision_id', 'order_index'], 'uq_stage_revision_order');
        });

        // 7. scenario_materials
        Schema::create('scenario_materials', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('revision_id')->constrained('scenario_revisions')->cascadeOnDelete();
            $table->string('material_type', 32);
            $table->string('title', 255);
            $table->smallInteger('order_index');
            $table->jsonb('content');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['revision_id', 'order_index'], 'idx_scenario_materials_revision');
        });

        DB::statement("ALTER TABLE scenario_materials ADD CONSTRAINT chk_material_type CHECK (material_type IN ('presentation_slide', 'printable_card', 'handout_sheet', 'props_checklist'))");

        // 8. revision_moderation_states
        Schema::create('revision_moderation_states', function (Blueprint $table) {
            $table->uuid('revision_id')->primary();
            $table->foreign('revision_id')->references('id')->on('scenario_revisions')->cascadeOnDelete();
            $table->string('status', 32)->default('pending_verification');
            $table->integer('p0_violations_count')->default(0);
            $table->integer('p1_violations_count')->default(0);
            $table->uuid('latest_verification_run_id')->nullable();
            $table->timestampTz('updated_at')->useCurrent();
        });

        DB::statement("ALTER TABLE revision_moderation_states ADD CONSTRAINT chk_moderation_status CHECK (status IN ('pending_verification', 'running', 'verification_blocked', 'verification_passed_with_warnings', 'verification_passed', 'failed_engine'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop circular FK before dropping tables
        if (Schema::hasTable('scenarios')) {
            DB::statement('ALTER TABLE scenarios DROP CONSTRAINT IF EXISTS fk_scenarios_current_revision');
        }

        Schema::dropIfExists('revision_moderation_states');
        Schema::dropIfExists('scenario_materials');
        Schema::dropIfExists('scenario_stages');
        Schema::dropIfExists('scenario_revisions');
        Schema::dropIfExists('scenarios');
        Schema::dropIfExists('idempotency_keys');
        Schema::dropIfExists('generation_runs');
        Schema::dropIfExists('scenario_requests');
    }
};
