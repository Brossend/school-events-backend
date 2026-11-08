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
        Schema::table('teacher_signoffs', function (Blueprint $table) {
            $table->dropUnique('uq_teacher_signoffs_revision_user');
            $table->unique(['revision_id', 'user_id', 'provenance_fingerprint'], 'uq_teacher_signoffs_rev_user_fingerprint');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('teacher_signoffs', function (Blueprint $table) {
            $table->dropUnique('uq_teacher_signoffs_rev_user_fingerprint');
            $table->unique(['revision_id', 'user_id'], 'uq_teacher_signoffs_revision_user');
        });
    }
};
