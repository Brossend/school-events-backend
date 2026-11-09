<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CalendarEvent;
use App\Models\EventOccurrence;
use App\Models\Scenario;
use App\Models\ScenarioRequest;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\SchoolTradition;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DashboardSummaryTest extends TestCase
{
    use DatabaseTransactions;

    protected User $teacher;

    protected User $outsider;

    protected School $school;

    protected School $otherSchool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::create([
            'name' => 'Лицей 130',
            'short_name' => 'Лицей 130',
            'region_code' => '66',
            'city' => 'Екатеринбург',
            'inn' => '6670012345',
            'settings' => [],
        ]);

        $this->otherSchool = School::create([
            'name' => 'Гимназия 9',
            'region_code' => '66',
            'city' => 'Екатеринбург',
            'inn' => '6670054321',
            'settings' => [],
        ]);

        $this->teacher = User::factory()->create();
        SchoolMembership::create([
            'school_id' => $this->school->id,
            'user_id' => $this->teacher->id,
            'role' => 'teacher',
            'is_default' => true,
        ]);

        $this->outsider = User::factory()->create();

        // 1. Calendar event visible to 66
        CalendarEvent::create([
            'title' => 'День бажовских сказов',
            'event_type' => 'regional',
            'region_code' => '66',
            'date_type' => 'fixed_annual',
            'fixed_month' => 1,
            'fixed_day' => 27,
            'description' => 'Уральский фольклорный праздник.',
            'target_age_groups' => ['basic_5_9'],
            'recommended_value_directions' => ['cultural_diversity'],
            'status' => 'approved',
        ]);

        // 2. School tradition
        SchoolTradition::create([
            'school_id' => $this->school->id,
            'title' => 'Посвящение в лицеисты',
            'description' => 'Традиционный праздник осени.',
            'established_year' => 1995,
            'is_archived' => false,
            'created_by_user_id' => $this->teacher->id,
        ]);

        // 3. Draft scenario
        $req1 = ScenarioRequest::create([
            'school_id' => $this->school->id,
            'user_id' => $this->teacher->id,
            'custom_event_title' => 'Осенний бал',
            'scale' => 'class_level',
            'format_type' => 'class_hour',
            'target_grades' => [6],
            'participants_count' => 25,
            'duration_minutes' => 45,
            'value_direction' => 'cultural_diversity',
            'family_involvement' => false,
            'context_snapshot' => [
                'occasion' => ['title' => 'Осенний бал'],
                'pedagogical_parameters' => ['scale' => 'class_level'],
            ],
        ]);

        Scenario::create([
            'school_id' => $this->school->id,
            'user_id' => $this->teacher->id,
            'scenario_request_id' => $req1->id,
            'title' => 'Осенний бал лицеистов',
            'is_archived' => false,
        ]);
    }

    public function test_dashboard_summary_requires_school_tenant(): void
    {
        $response = $this->actingAs($this->outsider)
            ->getJson('/api/v1/dashboard/summary');

        $response->assertStatus(403);
    }

    public function test_dashboard_summary_returns_aggregated_data(): void
    {
        $response = $this->actingAs($this->teacher)
            ->withHeader('X-School-Id', $this->school->id)
            ->getJson('/api/v1/dashboard/summary');

        $response->assertStatus(200)
            ->assertJsonPath('data.school.id', $this->school->id)
            ->assertJsonPath('data.school.name', 'Лицей 130')
            ->assertJsonPath('data.school.region_code', '66')
            ->assertJsonPath('data.counts.total_scenarios', 1)
            ->assertJsonPath('data.counts.draft_scenarios', 1)
            ->assertJsonPath('data.counts.completed_scenarios', 0)
            ->assertJsonPath('data.counts.archived_scenarios', 0)
            ->assertJsonPath('data.counts.traditions_count', 1);

        $this->assertNotEmpty($response->json('data.upcoming_events'));
        $this->assertNotEmpty($response->json('data.recent_scenarios'));
    }

    public function test_dashboard_includes_dated_occurrences_without_repeating_past_one_off_events(): void
    {
        $this->travelTo(Carbon::parse('2026-11-09'));
        $attributes = ['event_type' => 'federal', 'school_id' => null, 'date_type' => 'single_occurrence', 'fixed_month' => null, 'fixed_day' => null, 'status' => 'approved', 'description' => 'Test-only date', 'target_age_groups' => [], 'recommended_value_directions' => []];
        $future = CalendarEvent::create(['title' => 'Реальная календарная дата'] + $attributes);
        $past = CalendarEvent::create(['title' => 'Прошедшая дата'] + $attributes);
        EventOccurrence::create(['calendar_event_id' => $future->id, 'academic_year' => '2026-2027', 'start_date' => '2026-11-29', 'end_date' => '2026-11-29']);
        EventOccurrence::create(['calendar_event_id' => $past->id, 'academic_year' => '2026-2027', 'start_date' => '2026-11-04', 'end_date' => '2026-11-04']);
        $response = $this->actingAs($this->teacher)->withHeader('X-School-Id', $this->school->id)->getJson('/api/v1/dashboard/summary')->assertOk();
        $events = collect($response->json('data.upcoming_events'));
        $this->assertSame('2026-11-29', $events->firstWhere('id', $future->id)['next_date']);
        $this->assertFalse($events->contains('id', $past->id));
    }
}
