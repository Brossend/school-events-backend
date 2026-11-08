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
        Schema::table('scenarios', function (Blueprint $table) {
            $table->softDeletesTz();
        });

        Schema::table('school_traditions', function (Blueprint $table) {
            $table->boolean('is_archived')->default(false);
            $table->index('is_archived', 'idx_traditions_archived');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('school_traditions', function (Blueprint $table) {
            $table->dropIndex('idx_traditions_archived');
            $table->dropColumn('is_archived');
        });

        Schema::table('scenarios', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
