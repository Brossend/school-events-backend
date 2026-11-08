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
        Schema::table('export_jobs', function (Blueprint $table) {
            $table->string('provenance_fingerprint', 64)->nullable()->index()->after('status');
        });

        Schema::table('exported_artifacts', function (Blueprint $table) {
            $table->string('provenance_fingerprint', 64)->nullable()->index()->after('sha256_hash');
        });

        Schema::table('teacher_signoffs', function (Blueprint $table) {
            $table->string('provenance_fingerprint', 64)->nullable()->index()->after('is_confirmed');
        });

        Schema::table('revision_moderation_states', function (Blueprint $table) {
            $table->string('provenance_fingerprint', 64)->nullable()->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('revision_moderation_states', function (Blueprint $table) {
            $table->dropColumn('provenance_fingerprint');
        });

        Schema::table('teacher_signoffs', function (Blueprint $table) {
            $table->dropColumn('provenance_fingerprint');
        });

        Schema::table('exported_artifacts', function (Blueprint $table) {
            $table->dropColumn('provenance_fingerprint');
        });

        Schema::table('export_jobs', function (Blueprint $table) {
            $table->dropColumn('provenance_fingerprint');
        });
    }
};
