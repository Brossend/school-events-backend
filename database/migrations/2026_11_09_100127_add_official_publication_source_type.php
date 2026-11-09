<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE event_sources DROP CONSTRAINT chk_source_type');
        DB::statement("ALTER TABLE event_sources ADD CONSTRAINT chk_source_type CHECK (source_type IN ('normative_act', 'scientific_publication', 'ethnographic_archive', 'encyclopedia', 'official_publication'))");
    }

    public function down(): void
    {
        if (DB::table('event_sources')->where('source_type', 'official_publication')->exists()) {
            throw new RuntimeException('Cannot remove source type while referenced; no data was deleted.');
        }
        DB::statement('ALTER TABLE event_sources DROP CONSTRAINT chk_source_type');
        DB::statement("ALTER TABLE event_sources ADD CONSTRAINT chk_source_type CHECK (source_type IN ('normative_act', 'scientific_publication', 'ethnographic_archive', 'encyclopedia'))");
    }
};
