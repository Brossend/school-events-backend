<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cultural_claim_sources', function (Blueprint $table) {
            $table->boolean('is_detached')->default(false)->index();
            $table->timestampTz('detached_at')->nullable();
            $table->foreignUuid('detached_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('detachment_reason')->nullable();
            $table->string('previous_verification_status', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cultural_claim_sources', function (Blueprint $table) {
            $table->dropForeign(['detached_by_user_id']);
            $table->dropColumn([
                'is_detached',
                'detached_at',
                'detached_by_user_id',
                'detachment_reason',
                'previous_verification_status',
            ]);
        });
    }
};
