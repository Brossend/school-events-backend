<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS uq_generation_runs_single_active ON generation_runs (scenario_request_id) WHERE status IN ('queued', 'running')");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS uq_generation_runs_single_active');
    }
};
