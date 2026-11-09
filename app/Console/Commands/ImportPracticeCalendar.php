<?php

namespace App\Console\Commands;

use App\Models\CalendarEvent;
use App\Models\EventOccurrence;
use App\Models\EventSource;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportPracticeCalendar extends Command
{
    protected $signature = 'practice:calendar {--apply : Import the reviewed manifest; default is dry-run}';

    protected $description = 'Preview or import reviewed calendar into an isolated practice database only';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing']) || DB::connection()->getDriverName() !== 'pgsql' || ! preg_match('/\Aschool_events_(ux001[a-z0-9_]*|test)\z/', DB::selectOne('SELECT current_database() AS name', [], false)->name)) {
            $this->error('Refused: use local/testing and an isolated school_events_ux001* or school_events_test database.');

            return self::FAILURE;
        }
        $manifest = json_decode(file_get_contents(database_path('data/ux001-calendar-2026-2027.json')), true, flags: JSON_THROW_ON_ERROR);
        $this->table(['Date', 'Type', 'Title'], array_map(fn ($entry) => [$entry['date'], $entry['type'], $entry['title']], $manifest['entries']));
        $this->info('Records: '.count($manifest['entries']).'; mode: '.($this->option('apply') ? 'apply' : 'dry-run; no changes'));
        if (! $this->option('apply')) {
            return self::SUCCESS;
        }
        DB::transaction(function () use ($manifest) {
            DB::select("SELECT pg_advisory_xact_lock(hashtextextended('ux001-calendar-import', 0))", [], false);
            foreach ($manifest['entries'] as $entry) {
                $event = CalendarEvent::firstOrCreate(['title' => $entry['title'], 'school_id' => null, 'event_type' => $entry['type']], [
                    'region_code' => $entry['region_code'], 'date_type' => 'single_occurrence',
                    'description' => 'Дата указана в источнике: '.$entry['source_title'].'. '.$entry['locator'].'. Это повод для планирования, а не обязательное мероприятие школы. Направление — редакционная рекомендация; возрастная аудитория источником не установлена.',
                    'target_age_groups' => $entry['audience'], 'recommended_value_directions' => [$entry['direction']], 'status' => 'approved',
                ]);
                EventOccurrence::firstOrCreate(['calendar_event_id' => $event->id, 'academic_year' => $manifest['academic_year'], 'start_date' => $entry['date']], [
                    'end_date' => $entry['date'], 'source_reference' => $entry['source_url'],
                ]);
                EventSource::firstOrCreate(['calendar_event_id' => $event->id, 'url' => $entry['source_url']], [
                    'source_type' => 'official_publication', 'title' => $entry['source_title'], 'author' => $entry['type'] === 'federal' ? 'Минпросвещения России' : 'Областная газета',
                ]);
            }
        });
        $this->info('Import complete. Cultural evidence was not auto-verified; existing records were not overwritten.');

        return self::SUCCESS;
    }
}
