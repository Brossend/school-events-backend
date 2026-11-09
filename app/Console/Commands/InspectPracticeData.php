<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class InspectPracticeData extends Command
{
    protected $signature = 'practice:inspect {--dry-run : Read-only inventory; this command never deletes data}';

    protected $description = 'List explicitly marked synthetic records and dependent counts without deleting anything';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing']) || DB::connection()->getDriverName() !== 'pgsql') {
            $this->error('Refused: isolated development/testing PostgreSQL database required.');

            return self::FAILURE;
        }
        $database = DB::selectOne('SELECT current_database() AS name', [], false)->name;
        if (! preg_match('/\Aschool_events_(ux001[a-z0-9_]*|test)\z/', $database)) {
            $this->error('Refused: isolated development/testing database required.');

            return self::FAILURE;
        }
        $this->info('READ ONLY: '.$database.'; no deletion command is provided. Create a separate empty practice database for a clean start.');
        foreach (['schools' => 'name', 'calendar_events' => 'title', 'scenarios' => 'title'] as $table => $field) {
            $rows = DB::table($table)->where($field, 'like', 'ДЕМО:%')->orderBy('id')->get(['id', $field]);
            $this->line($table.': '.$rows->count().' explicitly marked records');
            foreach ($rows as $row) {
                $this->line($row->id.' '.$row->{$field});
            }
        }
        foreach (['scenarios', 'scenario_comparisons', 'export_jobs', 'exported_artifacts'] as $table) {
            $this->line($table.' total: '.DB::table($table)->count());
        }
        $this->line('synthetic comparisons: '.DB::table('scenario_comparisons')->where('is_synthetic', true)->count());

        return self::SUCCESS;
    }
}
