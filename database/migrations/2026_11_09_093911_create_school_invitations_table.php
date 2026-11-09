<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_invitations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->restrictOnDelete();
            $table->string('role', 32)->default('teacher');
            $table->string('email')->nullable();
            $table->char('code_hash', 64)->unique();
            $table->timestampTz('expires_at');
            $table->timestampTz('used_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->index(['school_id', 'created_at']);
        });
        DB::statement("ALTER TABLE school_invitations ADD CONSTRAINT chk_invitation_teacher CHECK (role = 'teacher')");
    }

    public function down(): void
    {
        Schema::dropIfExists('school_invitations');
    }
};
