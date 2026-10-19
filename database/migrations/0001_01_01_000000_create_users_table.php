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
        // 1. schools table
        Schema::create('schools', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 255);
            $table->string('short_name', 100)->nullable();
            $table->string('region_code', 10);
            $table->string('city', 100);
            $table->string('inn', 12)->nullable();
            $table->jsonb('settings')->default('{}');
            $table->timestampsTz();

            $table->index(['region_code', 'city'], 'idx_schools_region');
        });

        // 2. users table
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 255);
            $table->string('email', 255)->unique();
            $table->string('password', 255);
            $table->string('system_role', 32)->default('none');
            $table->string('curator_scope', 16)->nullable();
            $table->jsonb('curated_region_codes')->default('[]');
            $table->boolean('is_active')->default(true);
            $table->rememberToken();
            $table->timestampsTz();
        });

        // Add CHECK constraints for users
        DB::statement("ALTER TABLE users ADD CONSTRAINT chk_users_system_role CHECK (system_role IN ('none', 'catalog_curator', 'system_admin'))");
        DB::statement("ALTER TABLE users ADD CONSTRAINT chk_users_curator_scope CHECK (curator_scope IS NULL OR curator_scope IN ('global', 'regional'))");

        // 3. school_memberships table
        Schema::create('school_memberships', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 32);
            $table->boolean('is_default')->default(false);
            $table->timestampsTz();

            $table->unique(['school_id', 'user_id'], 'uq_school_user');
            $table->index('user_id', 'idx_memberships_user');
        });

        // Add CHECK constraint and partial unique index on school_memberships
        DB::statement("ALTER TABLE school_memberships ADD CONSTRAINT chk_memberships_role CHECK (role IN ('teacher', 'school_admin'))");
        DB::statement("CREATE UNIQUE INDEX uq_user_default_school ON school_memberships (user_id) WHERE is_default = true");

        // 4. password_reset_tokens table
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email', 255)->primary();
            $table->string('token', 255);
            $table->timestampTz('created_at')->nullable();
        });

        // 5. sessions table (for database session driver with UUID user_id)
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id', 255)->primary();
            $table->uuid('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('school_memberships');
        Schema::dropIfExists('users');
        Schema::dropIfExists('schools');
    }
};
