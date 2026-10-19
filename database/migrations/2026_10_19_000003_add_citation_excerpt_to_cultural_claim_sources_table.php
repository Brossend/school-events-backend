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
        Schema::table('cultural_claim_sources', function (Blueprint $table) {
            if (! Schema::hasColumn('cultural_claim_sources', 'citation_excerpt')) {
                $table->text('citation_excerpt')->nullable()->after('citation_locator');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cultural_claim_sources', function (Blueprint $table) {
            if (Schema::hasColumn('cultural_claim_sources', 'citation_excerpt')) {
                $table->dropColumn('citation_excerpt');
            }
        });
    }
};
