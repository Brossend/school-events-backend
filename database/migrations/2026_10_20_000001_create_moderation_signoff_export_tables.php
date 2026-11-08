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
        // 1. verification_runs (Append-only quality check runs)
        Schema::create('verification_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('revision_id')
                ->constrained('scenario_revisions')
                ->cascadeOnDelete();
            $table->string('rule_engine_version', 32)->default('1.0.0');
            $table->string('status', 32);
            $table->integer('p0_violations_count')->default(0);
            $table->integer('p1_violations_count')->default(0);
            $table->integer('execution_time_ms')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['revision_id', 'created_at'], 'idx_vr_revision_created');
        });

        DB::statement("ALTER TABLE verification_runs ADD CONSTRAINT chk_vr_status CHECK (status IN ('running', 'passed', 'passed_with_warnings', 'blocked', 'failed_engine'))");

        // 2. verification_findings (Detailed violations P0 / P1)
        Schema::create('verification_findings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('verification_run_id')
                ->constrained('verification_runs')
                ->cascadeOnDelete();
            $table->string('severity', 8);
            $table->string('rule_code', 64);
            $table->string('target_path', 255);
            $table->text('message');
            $table->text('suggested_fix')->nullable();
            $table->string('status', 32)->default('open');
            $table->timestampTz('created_at')->useCurrent();

            $table->index('verification_run_id', 'idx_vf_run_id');
        });

        DB::statement("ALTER TABLE verification_findings ADD CONSTRAINT chk_vf_severity CHECK (severity IN ('P0', 'P1'))");
        DB::statement("ALTER TABLE verification_findings ADD CONSTRAINT chk_vf_status CHECK (status IN ('open', 'resolved', 'acknowledged_warning'))");

        // 3. Link revision_moderation_states.latest_verification_run_id FK and expand status length
        DB::statement("ALTER TABLE revision_moderation_states ALTER COLUMN status TYPE varchar(48)");

        Schema::table('revision_moderation_states', function (Blueprint $table) {
            $table->foreign('latest_verification_run_id')
                ->references('id')
                ->on('verification_runs')
                ->nullOnDelete();
        });

        // 4. teacher_signoffs (Historical human attestation journal)
        Schema::create('teacher_signoffs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('revision_id')
                ->constrained('scenario_revisions')
                ->cascadeOnDelete();
            $table->foreignUuid('user_id')
                ->constrained('users')
                ->restrictOnDelete();
            $table->boolean('is_confirmed')->default(true);
            $table->jsonb('checklist_confirmed');
            $table->text('teacher_notes')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestampTz('confirmed_at')->useCurrent();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['revision_id', 'user_id'], 'uq_teacher_signoffs_revision_user');
            $table->index('revision_id', 'idx_teacher_signoffs_revision');
        });

        // 5. export_jobs (Async compilation tasks)
        Schema::create('export_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('revision_id')
                ->constrained('scenario_revisions')
                ->cascadeOnDelete();
            $table->foreignUuid('user_id')
                ->constrained('users')
                ->restrictOnDelete();
            $table->string('format', 16);
            $table->string('status', 32);
            $table->text('error_message')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('completed_at')->nullable();

            $table->index('revision_id', 'idx_export_jobs_revision');
            $table->index('user_id', 'idx_export_jobs_user');
        });

        DB::statement("ALTER TABLE export_jobs ADD CONSTRAINT chk_export_jobs_format CHECK (format IN ('pdf', 'docx', 'slides_pdf'))");
        DB::statement("ALTER TABLE export_jobs ADD CONSTRAINT chk_export_jobs_status CHECK (status IN ('queued', 'processing', 'ready', 'failed'))");

        // 6. exported_artifacts (Compiled artifacts in private storage)
        Schema::create('exported_artifacts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('export_job_id')
                ->constrained('export_jobs')
                ->cascadeOnDelete();
            $table->string('file_disk', 32)->default('local');
            $table->string('file_path', 1000);
            $table->string('file_name', 255);
            $table->bigInteger('file_size_bytes');
            $table->string('mime_type', 100)->default('application/pdf');
            $table->string('sha256_hash', 64);
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique('export_job_id', 'uq_exported_artifacts_job');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('exported_artifacts');
        Schema::dropIfExists('export_jobs');
        Schema::dropIfExists('teacher_signoffs');

        if (Schema::hasTable('revision_moderation_states')) {
            Schema::table('revision_moderation_states', function (Blueprint $table) {
                $table->dropForeign(['latest_verification_run_id']);
            });
        }

        Schema::dropIfExists('verification_findings');
        Schema::dropIfExists('verification_runs');
    }
};
