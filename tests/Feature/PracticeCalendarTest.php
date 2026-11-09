<?php

namespace Tests\Feature;

use App\Models\CalendarEvent;
use App\Models\School;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PracticeCalendarTest extends TestCase
{
    use DatabaseTransactions;

    public function test_dry_run_and_inspection_do_not_change_data(): void
    {
        $before = CalendarEvent::count();

        $this->artisan('practice:calendar')->assertSuccessful();
        $this->artisan('practice:inspect', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame($before, CalendarEvent::count());
    }

    public function test_import_is_idempotent_and_never_verifies_cultural_claims(): void
    {
        $claims = DB::table('cultural_claims')->count();
        $reviews = DB::table('source_reviews')->count();
        $this->artisan('practice:calendar', ['--apply' => true])->assertSuccessful();
        $events = CalendarEvent::count();
        $sources = DB::table('event_sources')->count();
        $occurrences = DB::table('event_occurrences')->count();

        $this->artisan('practice:calendar', ['--apply' => true])->assertSuccessful();

        $this->assertSame($events, CalendarEvent::count());
        $this->assertSame($sources, DB::table('event_sources')->count());
        $this->assertSame($occurrences, DB::table('event_occurrences')->count());
        $this->assertSame($claims, DB::table('cultural_claims')->count());
        $this->assertSame($reviews, DB::table('source_reviews')->count());
        $this->assertDatabaseHas('event_occurrences', ['academic_year' => '2026-2027', 'start_date' => '2026-11-29']);
        $this->assertDatabaseHas('event_sources', ['source_type' => 'official_publication', 'verified_by_user_id' => null]);
    }

    public function test_import_preserves_manually_edited_existing_event(): void
    {
        $this->artisan('practice:calendar', ['--apply' => true])->assertSuccessful();
        $event = CalendarEvent::where('title', 'День знаний')->firstOrFail();
        $event->update(['description' => 'Собственное уточнение школы']);

        $this->artisan('practice:calendar', ['--apply' => true])->assertSuccessful();

        $this->assertSame('Собственное уточнение школы', $event->fresh()->description);
    }

    public function test_production_import_and_inspection_are_refused_without_changes(): void
    {
        $before = CalendarEvent::count();
        app()->instance('env', 'production');

        $this->artisan('practice:calendar', ['--apply' => true])->assertFailed();
        $this->artisan('practice:inspect', ['--dry-run' => true])->assertFailed();
        $this->artisan('practice:bootstrap')->assertFailed();

        $this->assertSame($before, CalendarEvent::count());
        app()->instance('env', 'testing');
    }

    public static function refusedDatabases(): array
    {
        return [
            'primary' => ['school_events'],
            'unrelated' => ['another_database'],
            'similar prefix' => ['school_events_ux002'],
            'trailing newline' => ["school_events_ux001\n"],
        ];
    }

    #[DataProvider('refusedDatabases')]
    public function test_commands_refuse_wrong_active_database_without_changes(string $database): void
    {
        $events = CalendarEvent::count();
        $users = User::count();
        $schools = School::count();

        $this->withDatabaseIdentity($database, function (): void {
            $this->artisan('practice:calendar', ['--apply' => true])->expectsOutputToContain('Refused:')->assertFailed();
            $this->artisan('practice:inspect')->expectsOutputToContain('Refused:')->assertFailed();
            $this->artisan('practice:bootstrap')->expectsOutputToContain('Refused:')->assertFailed();
        });

        $this->assertDatabaseCount('calendar_events', $events);
        $this->assertDatabaseCount('users', $users);
        $this->assertDatabaseCount('schools', $schools);
    }

    public function test_bootstrap_refuses_the_shared_test_database(): void
    {
        $this->artisan('practice:bootstrap')->expectsOutputToContain('Refused:')->assertFailed();
    }

    public function test_bootstrap_creates_one_admin_with_a_hashed_password_and_refuses_a_repeat(): void
    {
        $this->withEmptyBootstrapDatabase(function (): void {
            $this->assertDatabaseCount('users', 0);
            $this->assertDatabaseCount('schools', 0);

            $this->withDatabaseIdentity('school_events_ux001_test', function (): void {
                $this->artisan('practice:bootstrap', ['--email' => ' Admin@Example.test ', '--school' => ' Practice School '])
                    ->expectsQuestion('New local administrator password (not logged)', 'OnlyForTests2026!')
                    ->assertSuccessful();
                $this->artisan('practice:bootstrap')->expectsOutputToContain('Refused:')->assertFailed();
            });

            $this->assertDatabaseCount('users', 1);
            $this->assertDatabaseCount('schools', 1);
            $user = User::where('email', 'admin@example.test')->firstOrFail();
            $this->assertTrue(Hash::check('OnlyForTests2026!', $user->password));
            $this->assertSame('none', $user->system_role);
            $this->assertDatabaseHas('schools', ['name' => 'Practice School']);
            $this->assertDatabaseHas('school_memberships', ['user_id' => $user->id, 'role' => 'school_admin', 'is_default' => true]);
        });
    }

    public static function invalidBootstrapInputs(): array
    {
        return [
            'email' => ['not-an-email', 'Practice School', 'OnlyForTests2026!'],
            'blank school' => ['admin@example.test', '   ', 'OnlyForTests2026!'],
            'short password' => ['admin@example.test', 'Practice School', 'Aa1'],
            'missing uppercase' => ['admin@example.test', 'Practice School', 'onlyfortests2026'],
            'missing number' => ['admin@example.test', 'Practice School', 'OnlyForTestsPassword'],
        ];
    }

    public function test_bootstrap_rechecks_for_accounts_after_acquiring_the_lock(): void
    {
        $this->withEmptyBootstrapDatabase(function (): void {
            $this->assertDatabaseCount('users', 0);
            $this->assertDatabaseCount('schools', 0);
            DB::listen(function (QueryExecuted $query): void {
                if (str_contains($query->sql, "pg_advisory_xact_lock(hashtextextended('ux001-bootstrap'")) {
                    User::factory()->create(['email' => 'competing@example.test']);
                }
            });

            $this->withDatabaseIdentity('school_events_ux001_test', function (): void {
                $this->artisan('practice:bootstrap', ['--email' => 'admin@example.test', '--school' => 'Practice School'])
                    ->expectsQuestion('New local administrator password (not logged)', 'OnlyForTests2026!')
                    ->expectsOutputToContain('bootstrap already completed by another process')
                    ->assertFailed();
            });

            $this->assertDatabaseCount('users', 1);
            $this->assertDatabaseMissing('users', ['email' => 'admin@example.test']);
            $this->assertDatabaseCount('schools', 0);
            $this->assertDatabaseCount('school_memberships', 0);
        });
    }

    #[DataProvider('invalidBootstrapInputs')]
    public function test_bootstrap_rejects_invalid_input_without_creating_accounts(string $email, string $school, string $password): void
    {
        $this->withEmptyBootstrapDatabase(function () use ($email, $school, $password): void {
            $this->assertDatabaseCount('users', 0);
            $this->assertDatabaseCount('schools', 0);

            $this->withDatabaseIdentity('school_events_ux001_test', function () use ($email, $school, $password): void {
                $this->artisan('practice:bootstrap', ['--email' => $email, '--school' => $school])
                    ->expectsQuestion('New local administrator password (not logged)', $password)
                    ->expectsOutputToContain('Invalid email, school or password')
                    ->assertFailed();
            });

            $this->assertDatabaseCount('users', 0);
            $this->assertDatabaseCount('schools', 0);
            $this->assertDatabaseCount('school_memberships', 0);
        });
    }

    private function withEmptyBootstrapDatabase(callable $callback): void
    {
        $default = DB::getDefaultConnection();
        $schema = 'ux001_bootstrap_'.bin2hex(random_bytes(8));
        config(['database.connections.bootstrap_fixture' => array_merge(config('database.connections.pgsql'), ['search_path' => $schema])]);
        $connection = DB::connection('bootstrap_fixture');
        $connection->statement('CREATE SCHEMA '.$schema);

        try {
            DB::setDefaultConnection('bootstrap_fixture');
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'bootstrap_fixture', '--force' => true, '--no-interaction' => true]), Artisan::output());
            $callback();
        } finally {
            DB::setDefaultConnection($default);
            $connection->statement('DROP SCHEMA '.$schema.' CASCADE');
            DB::purge('bootstrap_fixture');
        }
    }

    private function withDatabaseIdentity(string $database, callable $callback): void
    {
        $manager = DB::getFacadeRoot();
        $proxy = Mockery::mock($manager);
        $proxy->shouldReceive('selectOne')->with('SELECT current_database() AS name', [], false)
            ->andReturn((object) ['name' => $database]);
        DB::swap($proxy);

        try {
            $callback();
        } finally {
            DB::swap($manager);
        }
    }

    public function test_manifest_has_reviewed_sources_and_no_unconfirmed_audience_claim(): void
    {
        $manifest = json_decode(file_get_contents(database_path('data/ux001-calendar-2026-2027.json')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertCount(36, $manifest['entries']);
        foreach ($manifest['entries'] as $event) {
            $this->assertStringStartsWith('https://', $event['source_url']);
            $this->assertSame([], $event['audience']);
        }
    }
}
