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
        DB::statement('ALTER TABLE export_jobs DROP CONSTRAINT IF EXISTS chk_export_jobs_format');
        DB::statement("ALTER TABLE export_jobs ADD CONSTRAINT chk_export_jobs_format CHECK (format IN ('pdf', 'docx', 'pptx', 'print_pack_pdf', 'slides_pdf'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE export_jobs DROP CONSTRAINT IF EXISTS chk_export_jobs_format');
        DB::statement("ALTER TABLE export_jobs ADD CONSTRAINT chk_export_jobs_format CHECK (format IN ('pdf', 'docx', 'slides_pdf'))");
    }
};
