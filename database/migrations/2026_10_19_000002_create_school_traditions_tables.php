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
        // 1. school_traditions
        Schema::create('school_traditions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->restrictOnDelete();
            $table->string('title', 255);
            $table->text('description');
            $table->smallInteger('established_year')->nullable();
            $table->foreignUuid('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            $table->index('school_id', 'idx_traditions_school');
        });

        // 2. tradition_versions
        Schema::create('tradition_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tradition_id')->constrained('school_traditions')->cascadeOnDelete();
            $table->string('academic_year', 9);
            $table->jsonb('parameters_snapshot');
            $table->text('notes')->nullable();
            $table->foreignUuid('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['tradition_id', 'academic_year'], 'uq_tradition_academic_year');
        });

        DB::statement("ALTER TABLE tradition_versions ADD CONSTRAINT chk_tradition_academic_year CHECK (academic_year ~ '^[0-9]{4}-[0-9]{4}$')");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tradition_versions');
        Schema::dropIfExists('school_traditions');
    }
};
