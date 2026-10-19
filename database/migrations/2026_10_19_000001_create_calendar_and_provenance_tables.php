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
        // 1. calendar_events
        Schema::create('calendar_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->nullable()->constrained('schools')->cascadeOnDelete();
            $table->string('title', 255);
            $table->string('event_type', 32);
            $table->string('region_code', 10)->nullable();
            $table->string('date_type', 32);
            $table->smallInteger('fixed_month')->nullable();
            $table->smallInteger('fixed_day')->nullable();
            $table->text('description');
            $table->jsonb('target_age_groups');
            $table->jsonb('recommended_value_directions');
            $table->string('status', 32)->default('draft');
            $table->timestampsTz();

            $table->index(['fixed_month', 'fixed_day'], 'idx_cal_dates');
            $table->index('region_code', 'idx_cal_region');
            $table->index('status', 'idx_cal_status');
            $table->index('school_id', 'idx_cal_school');
        });

        DB::statement("ALTER TABLE calendar_events ADD CONSTRAINT chk_cal_event_type CHECK (event_type IN ('federal', 'regional', 'cultural_traditional', 'school_custom'))");
        DB::statement("ALTER TABLE calendar_events ADD CONSTRAINT chk_cal_date_type CHECK (date_type IN ('fixed_annual', 'floating_rule', 'single_occurrence'))");
        DB::statement("ALTER TABLE calendar_events ADD CONSTRAINT chk_cal_status CHECK (status IN ('draft', 'pending_review', 'approved', 'rejected'))");
        DB::statement("ALTER TABLE calendar_events ADD CONSTRAINT chk_cal_fixed_month CHECK (fixed_month IS NULL OR (fixed_month >= 1 AND fixed_month <= 12))");
        DB::statement("ALTER TABLE calendar_events ADD CONSTRAINT chk_cal_fixed_day CHECK (fixed_day IS NULL OR (fixed_day >= 1 AND fixed_day <= 31))");

        // 2. event_occurrences
        Schema::create('event_occurrences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('calendar_event_id')->constrained('calendar_events')->cascadeOnDelete();
            $table->string('academic_year', 9);
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->string('source_reference', 500)->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['calendar_event_id', 'academic_year', 'start_date'], 'uq_event_occurrence_date');
            $table->index(['academic_year', 'start_date'], 'idx_occ_year_date');
        });

        // 3. event_sources
        Schema::create('event_sources', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('calendar_event_id')->constrained('calendar_events')->cascadeOnDelete();
            $table->string('source_type', 32);
            $table->string('title', 500);
            $table->string('author', 255)->nullable();
            $table->string('url', 1000)->nullable();
            $table->string('doi_or_isbn', 64)->nullable();
            $table->foreignUuid('verified_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('verification_date')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            // Unique constraint required to support composite FK in cultural_claim_sources
            $table->unique(['id', 'calendar_event_id'], 'uq_event_source_id_event');
        });

        DB::statement("ALTER TABLE event_sources ADD CONSTRAINT chk_source_type CHECK (source_type IN ('normative_act', 'scientific_publication', 'ethnographic_archive', 'encyclopedia'))");

        // 4. source_reviews
        Schema::create('source_reviews', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('event_source_id')->constrained('event_sources')->cascadeOnDelete();
            $table->foreignUuid('reviewer_user_id')->constrained('users')->restrictOnDelete();
            $table->string('decision', 32);
            $table->text('comments');
            $table->timestampTz('reviewed_at')->useCurrent();
        });

        DB::statement("ALTER TABLE source_reviews ADD CONSTRAINT chk_review_decision CHECK (decision IN ('approved', 'rejected', 'requires_amendment'))");

        // 5. cultural_claims
        Schema::create('cultural_claims', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('calendar_event_id')->constrained('calendar_events')->cascadeOnDelete();
            $table->text('claim_text');
            $table->string('culture_name', 100);
            $table->text('provenance_notes');
            $table->timestampTz('created_at')->useCurrent();

            // Unique constraint required to support composite FK in cultural_claim_sources
            $table->unique(['id', 'calendar_event_id'], 'uq_cultural_claim_id_event');
        });

        // 6. cultural_claim_sources
        Schema::create('cultural_claim_sources', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('calendar_event_id');
            $table->uuid('cultural_claim_id');
            $table->uuid('event_source_id');
            $table->string('citation_locator', 255);
            $table->string('verification_status', 32)->default('unverified');
            $table->foreignUuid('verified_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('verified_at')->nullable();
            $table->string('applicable_academic_year', 9)->nullable();
            $table->text('notes')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('calendar_event_id')
                ->references('id')
                ->on('calendar_events')
                ->cascadeOnDelete();

            $table->unique(['cultural_claim_id', 'event_source_id', 'citation_locator'], 'uq_claim_source_locator');
        });

        // Strict composite foreign keys enforcing that claim and source belong to the exact same calendar_event_id
        DB::statement('ALTER TABLE cultural_claim_sources ADD CONSTRAINT fk_claim_source_claim FOREIGN KEY (cultural_claim_id, calendar_event_id) REFERENCES cultural_claims (id, calendar_event_id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE cultural_claim_sources ADD CONSTRAINT fk_claim_source_source FOREIGN KEY (event_source_id, calendar_event_id) REFERENCES event_sources (id, calendar_event_id) ON DELETE RESTRICT');
        DB::statement("ALTER TABLE cultural_claim_sources ADD CONSTRAINT chk_claim_verification_status CHECK (verification_status IN ('unverified', 'verified_authentic', 'disputed', 'rejected_fake'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cultural_claim_sources');
        Schema::dropIfExists('cultural_claims');
        Schema::dropIfExists('source_reviews');
        Schema::dropIfExists('event_sources');
        Schema::dropIfExists('event_occurrences');
        Schema::dropIfExists('calendar_events');
    }
};
