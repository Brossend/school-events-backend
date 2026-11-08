<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CalendarEvent;
use App\Models\ScenarioRequest;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\SchoolTradition;
use App\Models\TraditionVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ScenarioConstructorTest extends TestCase
{
    use DatabaseTransactions;

    protected School $schoolA;
    protected School $schoolB;
    protected User $teacherA;
    protected User $teacherB;
    protected CalendarEvent $calendarEvent;
    protected SchoolTradition $traditionA;
    protected TraditionVersion $versionA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->schoolA = School::factory()->create(['name' => 'Лицей № 130']);
        $this->schoolB = School::factory()->create(['name' => 'Гимназия № 9']);

        $this->teacherA = User::factory()->create(['email' => 'teacherA_sc@test.ru']);
        SchoolMembership::factory()->create([
            'school_id' => $this->schoolA->id,
            'user_id' => $this->teacherA->id,
            'role' => 'teacher',
            'is_default' => true,
        ]);

        $this->teacherB = User::factory()->create(['email' => 'teacherB_sc@test.ru']);
        SchoolMembership::factory()->create([
            'school_id' => $this->schoolB->id,
            'user_id' => $this->teacherB->id,
            'role' => 'teacher',
            'is_default' => true,
        ]);

        $this->calendarEvent = CalendarEvent::create([
            'title' => 'День российской науки',
            'event_type' => 'federal',
            'date_type' => 'fixed_annual',
            'fixed_month' => 2,
            'fixed_day' => 8,
            'description' => 'Праздник науки и научных открытий',
            'target_age_groups' => ['primary_1_4', 'basic_5_9', 'senior_10_11'],
            'recommended_value_directions' => ['historical_memory', 'labor_and_creation'],
            'status' => 'approved',
            'created_by_user_id' => $this->teacherA->id,
        ]);

        $this->traditionA = SchoolTradition::create([
            'school_id' => $this->schoolA->id,
            'title' => 'День Лицея',
            'description' => 'Ежегодный традиционный праздник лицея',
            'established_year' => 2000,
            'created_by_user_id' => $this->teacherA->id,
        ]);

        $this->versionA = TraditionVersion::create([
            'tradition_id' => $this->traditionA->id,
            'academic_year' => '2025-2026',
            'parameters_snapshot' => ['theme' => 'Наука и будущее'],
            'created_by_user_id' => $this->teacherA->id,
        ]);
    }

    public function test_teacher_can_create_scenario_request_with_calendar_event(): void
    {
        $payload = [
            'calendar_event_id' => $this->calendarEvent->id,
            'scale' => 'class_level',
            'format_type' => 'class_hour',
            'target_grades' => [7, 8],
            'participants_count' => 30,
            'duration_minutes' => 45,
            'value_direction' => 'historical_memory',
            'family_involvement' => false,
            'available_resources' => ['Проектор', 'Ноутбук'],
            'pedagogical_constraints' => 'Фокус на отечественных ученых',
        ];

        $response = $this->actingAs($this->teacherA)
            ->postJson('/api/v1/scenario-requests', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('data.school_id', $this->schoolA->id)
            ->assertJsonPath('data.user_id', $this->teacherA->id)
            ->assertJsonPath('data.calendar_event_id', $this->calendarEvent->id)
            ->assertJsonPath('data.value_direction', 'historical_memory');

        $this->assertDatabaseHas('scenario_requests', [
            'school_id' => $this->schoolA->id,
            'calendar_event_id' => $this->calendarEvent->id,
        ]);
    }

    public function test_teacher_can_create_scenario_request_with_school_tradition(): void
    {
        $payload = [
            'tradition_version_id' => $this->versionA->id,
            'scale' => 'all_school',
            'format_type' => 'festival',
            'target_grades' => [5, 6, 7, 8, 9, 10, 11],
            'participants_count' => 250,
            'duration_minutes' => 90,
            'value_direction' => 'labor_and_creation',
            'family_involvement' => true,
        ];

        $response = $this->actingAs($this->teacherA)
            ->postJson('/api/v1/scenario-requests', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('data.school_id', $this->schoolA->id)
            ->assertJsonPath('data.tradition_version_id', $this->versionA->id);
    }

    public function test_teacher_can_create_scenario_request_with_custom_event_title(): void
    {
        $payload = [
            'custom_event_title' => 'Школьный фестиваль робототехники',
            'scale' => 'class_level',
            'format_type' => 'workshop',
            'target_grades' => [6, 7],
            'participants_count' => 20,
            'duration_minutes' => 60,
            'value_direction' => 'labor_and_creation',
            'family_involvement' => false,
        ];

        $response = $this->actingAs($this->teacherA)
            ->postJson('/api/v1/scenario-requests', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('data.custom_event_title', 'Школьный фестиваль робототехники');
    }

    public function test_occasion_mutual_exclusivity_enforced_multiple_occasions(): void
    {
        $payload = [
            'calendar_event_id' => $this->calendarEvent->id,
            'custom_event_title' => 'Двойной повод',
            'scale' => 'class_level',
            'format_type' => 'class_hour',
            'target_grades' => [5],
            'participants_count' => 25,
            'duration_minutes' => 45,
            'value_direction' => 'family',
            'family_involvement' => false,
        ];

        $response = $this->actingAs($this->teacherA)
            ->postJson('/api/v1/scenario-requests', $payload);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $fields = collect($response->json('error.details'))->pluck('field')->all();
        $this->assertContains('occasion', $fields);
    }

    public function test_occasion_mutual_exclusivity_enforced_zero_occasions(): void
    {
        $payload = [
            'scale' => 'class_level',
            'format_type' => 'class_hour',
            'target_grades' => [5],
            'participants_count' => 25,
            'duration_minutes' => 45,
            'value_direction' => 'family',
            'family_involvement' => false,
        ];

        $response = $this->actingAs($this->teacherA)
            ->postJson('/api/v1/scenario-requests', $payload);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $fields = collect($response->json('error.details'))->pluck('field')->all();
        $this->assertContains('occasion', $fields);
    }

    public function test_school_id_in_body_is_strictly_rejected(): void
    {
        $payload = [
            'school_id' => $this->schoolB->id,
            'custom_event_title' => 'Попытка подмены школы',
            'scale' => 'class_level',
            'format_type' => 'class_hour',
            'target_grades' => [5],
            'participants_count' => 25,
            'duration_minutes' => 45,
            'value_direction' => 'family',
            'family_involvement' => false,
        ];

        $response = $this->actingAs($this->teacherA)
            ->postJson('/api/v1/scenario-requests', $payload);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'TENANT_INJECTION_FORBIDDEN');
    }

    public function test_scale_validation_rules(): void
    {
        $payload = [
            'custom_event_title' => 'Тест масштаба',
            'scale' => 'invalid_scale',
            'format_type' => 'class_hour',
            'target_grades' => [5],
            'participants_count' => 25,
            'duration_minutes' => 45,
            'value_direction' => 'family',
            'family_involvement' => false,
        ];

        $response = $this->actingAs($this->teacherA)
            ->postJson('/api/v1/scenario-requests', $payload);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $fields = collect($response->json('error.details'))->pluck('field')->all();
        $this->assertContains('scale', $fields);
    }

    public function test_participants_count_out_of_bounds_rejected(): void
    {
        $payload = [
            'custom_event_title' => 'Тест участников',
            'scale' => 'class_level',
            'format_type' => 'class_hour',
            'target_grades' => [5],
            'participants_count' => 4, // min 5
            'duration_minutes' => 45,
            'value_direction' => 'family',
            'family_involvement' => false,
        ];

        $response = $this->actingAs($this->teacherA)
            ->postJson('/api/v1/scenario-requests', $payload);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $fields = collect($response->json('error.details'))->pluck('field')->all();
        $this->assertContains('participants_count', $fields);
    }

    public function test_duration_minutes_out_of_bounds_rejected(): void
    {
        $payload = [
            'custom_event_title' => 'Тест тайминга',
            'scale' => 'class_level',
            'format_type' => 'class_hour',
            'target_grades' => [5],
            'participants_count' => 25,
            'duration_minutes' => 250, // max 240
            'value_direction' => 'family',
            'family_involvement' => false,
        ];

        $response = $this->actingAs($this->teacherA)
            ->postJson('/api/v1/scenario-requests', $payload);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $fields = collect($response->json('error.details'))->pluck('field')->all();
        $this->assertContains('duration_minutes', $fields);
    }

    public function test_cross_tenant_tradition_version_rejected(): void
    {
        // Teacher B tries to use School A's tradition version
        $payload = [
            'tradition_version_id' => $this->versionA->id,
            'scale' => 'class_level',
            'format_type' => 'class_hour',
            'target_grades' => [5],
            'participants_count' => 25,
            'duration_minutes' => 45,
            'value_direction' => 'family',
            'family_involvement' => false,
        ];

        $response = $this->actingAs($this->teacherB)
            ->postJson('/api/v1/scenario-requests', $payload);

        $response->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN_TENANT_ACCESS');
    }

    public function test_teacher_can_view_own_school_scenario_request(): void
    {
        $req = ScenarioRequest::create([
            'school_id' => $this->schoolA->id,
            'user_id' => $this->teacherA->id,
            'custom_event_title' => 'Собственный запрос',
            'scale' => 'class_level',
            'format_type' => 'class_hour',
            'target_grades' => [5],
            'participants_count' => 25,
            'duration_minutes' => 45,
            'value_direction' => 'family',
            'family_involvement' => false,
            'context_snapshot' => ['occasion' => ['title' => 'Собственный запрос']],
        ]);

        $response = $this->actingAs($this->teacherA)
            ->getJson("/api/v1/scenario-requests/{$req->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $req->id);
    }

    public function test_teacher_forbidden_from_viewing_other_school_scenario_request(): void
    {
        $req = ScenarioRequest::create([
            'school_id' => $this->schoolA->id,
            'user_id' => $this->teacherA->id,
            'custom_event_title' => 'Чужой запрос',
            'scale' => 'class_level',
            'format_type' => 'class_hour',
            'target_grades' => [5],
            'participants_count' => 25,
            'duration_minutes' => 45,
            'value_direction' => 'family',
            'family_involvement' => false,
            'context_snapshot' => ['occasion' => ['title' => 'Чужой запрос']],
        ]);

        $response = $this->actingAs($this->teacherB)
            ->getJson("/api/v1/scenario-requests/{$req->id}");

        $response->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN_TENANT_ACCESS');
    }

    public function test_teacher_cannot_use_other_school_custom_calendar_event(): void
    {
        $schoolBEvent = CalendarEvent::create([
            'school_id' => $this->schoolB->id,
            'title' => 'День школы Б',
            'event_type' => 'school_custom',
            'date_type' => 'fixed_annual',
            'fixed_month' => 5,
            'fixed_day' => 25,
            'description' => 'Школьный праздник школы Б',
            'target_age_groups' => ['basic_5_9'],
            'recommended_value_directions' => ['family'],
            'status' => 'approved',
            'created_by_user_id' => $this->teacherB->id,
        ]);

        $payload = [
            'calendar_event_id' => $schoolBEvent->id,
            'scale' => 'class_level',
            'format_type' => 'class_hour',
            'target_grades' => [5],
            'participants_count' => 25,
            'duration_minutes' => 45,
            'value_direction' => 'family',
            'family_involvement' => false,
        ];

        $response = $this->actingAs($this->teacherA)
            ->postJson('/api/v1/scenario-requests', $payload);

        $response->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN_TENANT_ACCESS');
    }

    public function test_teacher_cannot_use_other_region_calendar_event(): void
    {
        // School A has region 66
        $this->schoolA->update(['region_code' => '66']);

        // Regional event of region 77
        $region77Event = CalendarEvent::create([
            'title' => 'День Московской области',
            'event_type' => 'regional',
            'region_code' => '77',
            'date_type' => 'fixed_annual',
            'fixed_month' => 9,
            'fixed_day' => 1,
            'description' => 'Региональный праздник региона 77',
            'target_age_groups' => ['basic_5_9'],
            'recommended_value_directions' => ['family'],
            'status' => 'approved',
            'created_by_user_id' => $this->teacherA->id,
        ]);

        $payload = [
            'calendar_event_id' => $region77Event->id,
            'scale' => 'class_level',
            'format_type' => 'class_hour',
            'target_grades' => [5],
            'participants_count' => 25,
            'duration_minutes' => 45,
            'value_direction' => 'family',
            'family_involvement' => false,
        ];

        $response = $this->actingAs($this->teacherA)
            ->postJson('/api/v1/scenario-requests', $payload);

        $response->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN_EVENT_ACCESS');
    }

    public function test_teacher_cannot_use_draft_calendar_event(): void
    {
        $draftEvent = CalendarEvent::create([
            'school_id' => $this->schoolA->id,
            'title' => 'Черновой праздник лицея',
            'event_type' => 'school_custom',
            'date_type' => 'fixed_annual',
            'fixed_month' => 4,
            'fixed_day' => 12,
            'description' => 'Не утверждённый праздник',
            'target_age_groups' => ['basic_5_9'],
            'recommended_value_directions' => ['family'],
            'status' => 'draft',
            'created_by_user_id' => $this->teacherA->id,
        ]);

        $payload = [
            'calendar_event_id' => $draftEvent->id,
            'scale' => 'class_level',
            'format_type' => 'class_hour',
            'target_grades' => [5],
            'participants_count' => 25,
            'duration_minutes' => 45,
            'value_direction' => 'family',
            'family_involvement' => false,
        ];

        $response = $this->actingAs($this->teacherA)
            ->postJson('/api/v1/scenario-requests', $payload);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'EVENT_STATUS_INVALID');
    }

    public function test_same_school_different_teacher_author_access_isolation(): void
    {
        $teacherA2 = User::factory()->create(['email' => 'teacherA2_sc@test.ru']);
        SchoolMembership::factory()->create([
            'school_id' => $this->schoolA->id,
            'user_id' => $teacherA2->id,
            'role' => 'teacher',
            'is_default' => true,
        ]);

        $adminA = User::factory()->create(['email' => 'adminA_sc@test.ru']);
        SchoolMembership::factory()->create([
            'school_id' => $this->schoolA->id,
            'user_id' => $adminA->id,
            'role' => 'school_admin',
            'is_default' => true,
        ]);

        $req = ScenarioRequest::create([
            'school_id' => $this->schoolA->id,
            'user_id' => $this->teacherA->id,
            'custom_event_title' => 'Запрос учителя А',
            'scale' => 'class_level',
            'format_type' => 'class_hour',
            'target_grades' => [5],
            'participants_count' => 25,
            'duration_minutes' => 45,
            'value_direction' => 'family',
            'family_involvement' => false,
            'context_snapshot' => ['occasion' => ['title' => 'Запрос учителя А']],
        ]);

        // 1. Teacher A2 in same school CANNOT view Teacher A's request
        $respA2 = $this->actingAs($teacherA2)
            ->getJson("/api/v1/scenario-requests/{$req->id}");
        $respA2->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN_AUTHOR_ACCESS');

        // 2. Teacher A2 in same school CANNOT trigger generation for Teacher A's request
        $genA2 = $this->actingAs($teacherA2)
            ->withHeader('Idempotency-Key', (string) \Illuminate\Support\Str::uuid())
            ->postJson("/api/v1/scenario-requests/{$req->id}/generate");
        $genA2->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN_AUTHOR_ACCESS');

        // 3. School admin in same school CAN view Teacher A's request
        $respAdmin = $this->actingAs($adminA)
            ->getJson("/api/v1/scenario-requests/{$req->id}");
        $respAdmin->assertStatus(200)
            ->assertJsonPath('data.id', $req->id);
    }
}
