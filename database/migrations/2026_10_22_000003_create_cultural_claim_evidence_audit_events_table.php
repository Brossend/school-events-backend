<?php

declare(strict_types=1);

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
        Schema::create('cultural_claim_evidence_audit_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('cultural_claim_source_id')->nullable()->index();
            $table->foreignUuid('cultural_claim_id')->constrained('cultural_claims')->cascadeOnDelete();
            $table->foreignUuid('event_source_id')->constrained('event_sources')->cascadeOnDelete();
            $table->string('action', 48)->index(); // 'attached', 'detached', 'reattached', 'reverified', 'invalidated_by_source_amendment'
            $table->foreignUuid('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('previous_verification_status', 32)->nullable();
            $table->string('new_verification_status', 32)->nullable();
            $table->string('citation_locator')->nullable();
            $table->text('citation_excerpt')->nullable();
            $table->text('reason')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampTz('created_at')->useCurrent()->index();

            $table->index(['cultural_claim_id', 'event_source_id', 'created_at'], 'cceae_claim_source_time_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cultural_claim_evidence_audit_events');
    }
};
