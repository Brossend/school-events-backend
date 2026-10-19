<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CalendarEvent;
use App\Models\EventOccurrence;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CalendarTest extends TestCase
{
    use DatabaseTransactions;

    protected School $schoolA;
    protected School $schoolB;
    protected School $schoolMoscow;

    protected User $teacherA;
    protected User $teacherB;
    protected User $adminA;
    protected User $globalCurator;
    protected User $regionalCurator66;
    protected User $systemAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Schools (region 66 for Ekaterinburg, 77 for Moscow)
        $this->schoolA = School::factory()->create([
            'name' => 'Лицей № 130 г. Екатеринбург',
            'region_code' => '66',
        ]);
        $this->schoolB = School::factory()->create([
            'name' => 'Гимназия № 9 г. Екатеринбург',
            'region_code' => '66',
        ]);
        $this->schoolMoscow = School::factory()->create([
            'name' => 'Школа № 57 г. Москва',
            'region_code' => '77',
        ]);

        // 2. Users
        $this->teacherA = User::factory()->create(['email' => 'teacherA@test.ru']);
        SchoolMembership::factory()->create([
            'school_id' => $this->schoolA->id,
            'user_id' => $this->teacherA->id,
            'role' => 'teacher',
            'is_default' => true,
        ]);

        $this->teacherB = User::factory()->create(['email' => 'teacherB@test.ru']);
        SchoolMembership::factory()->create([
            'school_id' => $this->schoolB->id,
            'user_id' => $this->teacherB->id,
            'role' => 'teacher',
            'is_default' => true,
        ]);

        $this->adminA = User::factory()->create(['email' => 'adminA@test.ru']);
        SchoolMembership::factory()->create([
            'school_id' => $this->schoolA->id,
            'user_id' => $this->adminA->id,
            'role' => 'school_admin',
            'is_default' => true,
        ]);

        $this->globalCurator = User::factory()->curator('global')->create(['email' => 'curator_global@test.ru']);
        $this->regionalCurator66 = User::factory()->curator('regional', ['66'])->create(['email' => 'curator_66@test.ru']);
        $this->systemAdmin = User::factory()->systemAdmin()->create(['email' => 'admin@test.ru']);
    }

    /**
     * Test federal events are globally visible to all schools.
     */
    public function test_federal_events_visible_across_all_schools(): void
    {
        $federalEvent = CalendarEvent::create([
            'title' => 'День народного единства',
            'event_type' => 'federal',
            'date_type' => 'fixed_annual',
            'fixed_month' => 11,
            'fixed_day' => 4,
            'description' => 'Государственный праздник Российской Федерации, отмечаемый ежегодно 4 ноября.',
            'target_age_groups' => ['primary_1_4', 'basic_5_9', 'senior_10_11'],
            'recommended_value_directions' => ['historical_memory', 'civic_responsibility'],
            'status' => 'approved',
        ]);

        // Teacher A sees the event
        $resA = $this->actingAs($this->teacherA, 'web')->getJson('/api/v1/calendar-events');
        $resA->assertStatus(200)
            ->assertJsonFragment(['id' => $federalEvent->id, 'title' => 'День народного единства']);

        // Teacher B sees the event
        $resB = $this->actingAs($this->teacherB, 'web')->getJson('/api/v1/calendar-events');
        $resB->assertStatus(200)
            ->assertJsonFragment(['id' => $federalEvent->id, 'title' => 'День народного единства']);
    }

    /**
     * Test Teacher of School A sees only their own school custom events in the catalog.
     */
    public function test_school_a_teacher_sees_only_own_school_custom_events(): void
    {
        $eventA = CalendarEvent::create([
            'school_id' => $this->schoolA->id,
            'title' => 'День рождения Лицея 130',
            'event_type' => 'school_custom',
            'date_type' => 'fixed_annual',
            'fixed_month' => 10,
            'fixed_day' => 19,
            'description' => 'Традиционный лицейский праздник основания школы.',
            'target_age_groups' => ['basic_5_9', 'senior_10_11'],
            'recommended_value_directions' => ['labor_and_creation'],
            'status' => 'approved',
        ]);

        $eventB = CalendarEvent::create([
            'school_id' => $this->schoolB->id,
            'title' => 'Юбилей Гимназии 9',
            'event_type' => 'school_custom',
            'date_type' => 'single_occurrence',
            'description' => 'Большой гимназический бал и чествование выпускников.',
            'target_age_groups' => ['senior_10_11'],
            'recommended_value_directions' => ['historical_memory'],
            'status' => 'approved',
        ]);

        $response = $this->actingAs($this->teacherA, 'web')->getJson('/api/v1/calendar-events');
        $response->assertStatus(200)
            ->assertJsonFragment(['id' => $eventA->id])
            ->assertJsonMissing(['id' => $eventB->id]);
    }

    /**
     * Test Teacher of School B sees only their own school custom events in the catalog.
     */
    public function test_school_b_teacher_sees_only_own_school_custom_events(): void
    {
        $eventA = CalendarEvent::create([
            'school_id' => $this->schoolA->id,
            'title' => 'День рождения Лицея 130',
            'event_type' => 'school_custom',
            'date_type' => 'fixed_annual',
            'fixed_month' => 10,
            'fixed_day' => 19,
            'description' => 'Традиционный лицейский праздник основания школы.',
            'target_age_groups' => ['basic_5_9', 'senior_10_11'],
            'recommended_value_directions' => ['labor_and_creation'],
            'status' => 'approved',
        ]);

        $eventB = CalendarEvent::create([
            'school_id' => $this->schoolB->id,
            'title' => 'Юбилей Гимназии 9',
            'event_type' => 'school_custom',
            'date_type' => 'single_occurrence',
            'description' => 'Большой гимназический бал и чествование выпускников.',
            'target_age_groups' => ['senior_10_11'],
            'recommended_value_directions' => ['historical_memory'],
            'status' => 'approved',
        ]);

        $response = $this->actingAs($this->teacherB, 'web')->getJson('/api/v1/calendar-events');
        $response->assertStatus(200)
            ->assertJsonFragment(['id' => $eventB->id])
            ->assertJsonMissing(['id' => $eventA->id]);
    }

    /**
     * Requirement 7.8: учитель региона 66 не получает события региона 77 даже по прямому ID (404)
     */
    public function test_teacher_of_region_66_cannot_view_region_77_event_directly(): void
    {
        $event77 = CalendarEvent::create([
            'title' => 'День основания Москвы',
            'event_type' => 'regional',
            'region_code' => '77',
            'date_type' => 'fixed_annual',
            'fixed_month' => 9,
            'fixed_day' => 5,
            'description' => 'Столичный праздник региона 77.',
            'target_age_groups' => ['basic_5_9'],
            'recommended_value_directions' => ['civic_responsibility'],
            'status' => 'approved',
        ]);

        // Direct lookup by ID for teacher of school in region 66 must yield 404
        $resDirect = $this->actingAs($this->teacherA, 'web')->getJson("/api/v1/calendar-events/{$event77->id}");
        $resDirect->assertStatus(404)
            ->assertJsonPath('error.code', 'EVENT_NOT_FOUND');

        // And region 77 event must not appear in list for teacher of region 66
        $resList = $this->actingAs($this->teacherA, 'web')->getJson('/api/v1/calendar-events');
        $resList->assertStatus(200)
            ->assertJsonMissing(['id' => $event77->id]);
    }

    /**
     * Requirement 7.9: неверный X-School-Id на календаре -> отказ
     */
    public function test_invalid_x_school_id_header_on_calendar_is_rejected(): void
    {
        // 1. Foreign school ID: Teacher A tries to pass School B ID -> 403
        $resForbidden = $this->actingAs($this->teacherA, 'web')
            ->withHeader('X-School-Id', $this->schoolB->id)
            ->getJson('/api/v1/calendar-events');

        $resForbidden->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN_TENANT_ACCESS');

        // 2. Non-existent school ID -> 404
        $nonExistent = (string) \Illuminate\Support\Str::uuid7();
        $resNotFound = $this->actingAs($this->teacherA, 'web')
            ->withHeader('X-School-Id', $nonExistent)
            ->getJson('/api/v1/calendar-events');

        $resNotFound->assertStatus(404)
            ->assertJsonPath('error.code', 'SCHOOL_NOT_FOUND');
    }

    /**
     * Requirement 7.10: две школы без default -> требуется выбор школы (422)
     */
    public function test_multiple_schools_without_default_requires_selection_on_calendar(): void
    {
        $multiUser = User::factory()->create();

        // 2 memberships, neither is default
        SchoolMembership::create([
            'school_id' => $this->schoolA->id,
            'user_id' => $multiUser->id,
            'role' => 'teacher',
            'is_default' => false,
        ]);
        SchoolMembership::create([
            'school_id' => $this->schoolB->id,
            'user_id' => $multiUser->id,
            'role' => 'teacher',
            'is_default' => false,
        ]);

        // Request without X-School-Id header must yield 422
        $res = $this->actingAs($multiUser, 'web')->getJson('/api/v1/calendar-events');
        $res->assertStatus(422)
            ->assertJsonPath('error.code', 'SCHOOL_SELECTION_REQUIRED');

        // Request with explicit valid header succeeds
        $resValid = $this->actingAs($multiUser, 'web')
            ->withHeader('X-School-Id', $this->schoolA->id)
            ->getJson('/api/v1/calendar-events');
        $resValid->assertStatus(200);
    }

    /**
     * Requirement 7.11: региональный куратор пытается создать федеральный или чужой региональный праздник -> 403
     */
    public function test_regional_curator_forbidden_from_creating_federal_or_other_region(): void
    {
        // 1. Regional curator attempts federal event -> 403
        $federalPayload = [
            'title' => 'Попытка создания федерального праздника куратором региона',
            'event_type' => 'federal',
            'date_type' => 'fixed_annual',
            'fixed_month' => 6,
            'fixed_day' => 12,
            'description' => 'Куратор региона не имеет права создавать глобальные федеральные записи.',
            'target_age_groups' => ['primary_1_4'],
            'recommended_value_directions' => ['civic_responsibility'],
        ];

        $resFed = $this->actingAs($this->regionalCurator66, 'web')
            ->postJson('/api/v1/calendar-events', $federalPayload);

        $resFed->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');

        // 2. Regional curator attempts out-of-scope region 77 -> 403
        $region77Payload = [
            'title' => 'Московский региональный праздник',
            'event_type' => 'regional',
            'region_code' => '77',
            'date_type' => 'fixed_annual',
            'fixed_month' => 9,
            'fixed_day' => 5,
            'description' => 'Куратор Свердловской области (66) пытается создать событие Москвы (77).',
            'target_age_groups' => ['basic_5_9'],
            'recommended_value_directions' => ['historical_memory'],
        ];

        $resReg = $this->actingAs($this->regionalCurator66, 'web')
            ->postJson('/api/v1/calendar-events', $region77Payload);

        $resReg->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN_REGIONAL_SCOPE');
    }

    /**
     * Requirement 7.12: school_admin пытается создать региональный каталог -> 403
     */
    public function test_school_admin_forbidden_from_creating_regional_event(): void
    {
        $payload = [
            'title' => 'Региональный праздник от имени школы',
            'event_type' => 'regional',
            'region_code' => '66',
            'date_type' => 'fixed_annual',
            'fixed_month' => 9,
            'fixed_day' => 1,
            'description' => 'Школьный администратор пытается внести событие в региональный реестр.',
            'target_age_groups' => ['primary_1_4'],
            'recommended_value_directions' => ['labor_and_creation'],
        ];

        $res = $this->actingAs($this->adminA, 'web')
            ->postJson('/api/v1/calendar-events', $payload);

        $res->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }

    /**
     * Requirement 7.13: пересечение диапазона дат с многодневным событием
     */
    public function test_date_range_intersection_with_multiday_event(): void
    {
        $multiDayEvent = CalendarEvent::create([
            'title' => 'Неделя детской книги и творчества',
            'event_type' => 'federal',
            'date_type' => 'single_occurrence',
            'description' => 'Всероссийская многодневная декада чтения.',
            'target_age_groups' => ['primary_1_4', 'basic_5_9'],
            'recommended_value_directions' => ['labor_and_creation'],
            'status' => 'approved',
        ]);

        // Occurrence spans Oct 25 to Nov 5, 2026
        EventOccurrence::create([
            'calendar_event_id' => $multiDayEvent->id,
            'academic_year' => '2026-2027',
            'start_date' => '2026-10-25',
            'end_date' => '2026-11-05',
        ]);

        // 1. Query range [2026-11-01 .. 2026-11-10]: Event started before Nov 1 but continues until Nov 5 -> MATCH
        $resMatch = $this->actingAs($this->teacherA, 'web')
            ->getJson('/api/v1/calendar-events?from=2026-11-01&to=2026-11-10');

        $resMatch->assertStatus(200)
            ->assertJsonFragment(['id' => $multiDayEvent->id]);

        // 2. Query range [2026-11-06 .. 2026-11-10]: Event already ended on Nov 5 -> NO MATCH
        $resNoMatch = $this->actingAs($this->teacherA, 'web')
            ->getJson('/api/v1/calendar-events?from=2026-11-06&to=2026-11-10');

        $resNoMatch->assertStatus(200)
            ->assertJsonMissing(['id' => $multiDayEvent->id]);
    }

    /**
     * Test draft/unapproved catalog events are invisible to regular teachers.
     */
    public function test_unapproved_catalog_event_invisible_to_teachers(): void
    {
        $draftEvent = CalendarEvent::create([
            'title' => 'Проект регионального праздника',
            'event_type' => 'regional',
            'region_code' => '66',
            'date_type' => 'fixed_annual',
            'fixed_month' => 5,
            'fixed_day' => 1,
            'description' => 'Черновик описания регионального праздника ремесел.',
            'target_age_groups' => ['primary_1_4'],
            'recommended_value_directions' => ['cultural_diversity'],
            'status' => 'draft',
        ]);

        $response = $this->actingAs($this->teacherA, 'web')->getJson("/api/v1/calendar-events/{$draftEvent->id}");
        $response->assertStatus(404)
            ->assertJsonPath('error.code', 'EVENT_NOT_FOUND');
    }

    /**
     * Test draft/unapproved catalog events are visible to curators.
     */
    public function test_unapproved_catalog_event_visible_to_curators(): void
    {
        $draftEvent = CalendarEvent::create([
            'title' => 'Проект регионального праздника (кураторский доступ)',
            'event_type' => 'regional',
            'region_code' => '66',
            'date_type' => 'fixed_annual',
            'fixed_month' => 5,
            'fixed_day' => 1,
            'description' => 'Черновик описания регионального праздника ремесел.',
            'target_age_groups' => ['primary_1_4'],
            'recommended_value_directions' => ['cultural_diversity'],
            'status' => 'draft',
        ]);

        $response = $this->actingAs($this->globalCurator, 'web')->getJson("/api/v1/calendar-events/{$draftEvent->id}");
        $response->assertStatus(200)
            ->assertJsonPath('data.id', $draftEvent->id);
    }

    /**
     * Test draft/unapproved catalog events are visible to system admins.
     */
    public function test_unapproved_catalog_event_visible_to_system_admins(): void
    {
        $draftEvent = CalendarEvent::create([
            'title' => 'Проект регионального праздника (админский доступ)',
            'event_type' => 'regional',
            'region_code' => '66',
            'date_type' => 'fixed_annual',
            'fixed_month' => 5,
            'fixed_day' => 1,
            'description' => 'Черновик описания регионального праздника ремесел.',
            'target_age_groups' => ['primary_1_4'],
            'recommended_value_directions' => ['cultural_diversity'],
            'status' => 'draft',
        ]);

        $response = $this->actingAs($this->systemAdmin, 'web')->getJson("/api/v1/calendar-events/{$draftEvent->id}");
        $response->assertStatus(200)
            ->assertJsonPath('data.id', $draftEvent->id);
    }

    /**
     * Test search query parameter 'q'.
     */
    public function test_calendar_search_query(): void
    {
        $event1 = CalendarEvent::create([
            'title' => 'День российской науки',
            'event_type' => 'federal',
            'date_type' => 'fixed_annual',
            'fixed_month' => 2,
            'fixed_day' => 8,
            'description' => 'Праздник учрежден указом президента РФ в честь основания Академии наук.',
            'target_age_groups' => ['senior_10_11'],
            'recommended_value_directions' => ['labor_and_creation'],
            'status' => 'approved',
        ]);

        $event2 = CalendarEvent::create([
            'title' => 'День космонавтики',
            'event_type' => 'federal',
            'date_type' => 'fixed_annual',
            'fixed_month' => 4,
            'fixed_day' => 12,
            'description' => 'День первого полета человека в космос (Юрий Гагарин).',
            'target_age_groups' => ['basic_5_9'],
            'recommended_value_directions' => ['historical_memory'],
            'status' => 'approved',
        ]);

        $response = $this->actingAs($this->teacherA, 'web')
            ->getJson('/api/v1/calendar-events?q=Гагарин');

        $response->assertStatus(200)
            ->assertJsonFragment(['id' => $event2->id])
            ->assertJsonMissing(['id' => $event1->id]);
    }

    /**
     * Test pagination meta structure.
     */
    public function test_calendar_pagination_meta_structure(): void
    {
        $res = $this->actingAs($this->teacherA, 'web')->getJson('/api/v1/calendar-events?per_page=5');

        $res->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'title', 'event_type', 'region_code', 'description'],
                ],
                'meta' => [
                    'current_page',
                    'last_page',
                    'per_page',
                    'total',
                ],
            ]);
    }

    /**
     * Test school admin can create school custom events scoped to their school.
     */
    public function test_school_admin_can_create_school_custom_event(): void
    {
        $payload = [
            'title' => 'Школьная ярмарка талантов',
            'event_type' => 'school_custom',
            'date_type' => 'fixed_annual',
            'fixed_month' => 4,
            'fixed_day' => 15,
            'description' => 'Ежегодная благотворительная и творческая ярмарка лицея.',
            'target_age_groups' => ['primary_1_4', 'basic_5_9', 'senior_10_11'],
            'recommended_value_directions' => ['mutual_aid', 'labor_and_creation'],
        ];

        $response = $this->actingAs($this->adminA, 'web')
            ->postJson('/api/v1/calendar-events', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('data.title', 'Школьная ярмарка талантов');

        $createdId = $response->json('data.id');
        $created = CalendarEvent::find($createdId);
        $this->assertNotNull($created);
        $this->assertEquals($this->schoolA->id, $created->school_id);
    }

    /**
     * Requirement BE-002.2 #4: Неполная нижняя граница ?from=2026-11-01 находит ежегодный праздник 8 февраля в будущем году.
     */
    public function test_unbounded_from_date_finds_future_annual_event(): void
    {
        $febEvent = CalendarEvent::create([
            'title' => 'День российской науки',
            'event_type' => 'federal',
            'date_type' => 'fixed_annual',
            'fixed_month' => 2,
            'fixed_day' => 8,
            'description' => 'Ежегодный праздник 8 февраля.',
            'target_age_groups' => ['senior_10_11'],
            'recommended_value_directions' => ['labor_and_creation'],
            'status' => 'approved',
        ]);

        $res = $this->actingAs($this->teacherA, 'web')
            ->getJson('/api/v1/calendar-events?from=2026-11-01');

        $res->assertStatus(200)
            ->assertJsonFragment(['id' => $febEvent->id]);
    }

    /**
     * Requirement BE-002.2 #4: Неполная верхняя граница ?to=2027-02-01 находит ежегодный праздник 4 ноября в предыдущем году.
     */
    public function test_unbounded_to_date_finds_past_annual_event(): void
    {
        $novEvent = CalendarEvent::create([
            'title' => 'День народного единства',
            'event_type' => 'federal',
            'date_type' => 'fixed_annual',
            'fixed_month' => 11,
            'fixed_day' => 4,
            'description' => 'Ежегодный праздник 4 ноября.',
            'target_age_groups' => ['primary_1_4'],
            'recommended_value_directions' => ['historical_memory'],
            'status' => 'approved',
        ]);

        $res = $this->actingAs($this->teacherA, 'web')
            ->getJson('/api/v1/calendar-events?to=2027-02-01');

        $res->assertStatus(200)
            ->assertJsonFragment(['id' => $novEvent->id]);
    }

    /**
     * Requirement BE-002.2 #4: Диапазон через границу календарного года (?from=2026-11-01&to=2027-02-15).
     */
    public function test_cross_year_boundary_date_range(): void
    {
        $novEvent = CalendarEvent::create([
            'title' => 'Праздник в ноябре',
            'event_type' => 'federal',
            'date_type' => 'fixed_annual',
            'fixed_month' => 11,
            'fixed_day' => 4,
            'description' => 'Ноябрьское событие.',
            'target_age_groups' => ['basic_5_9'],
            'recommended_value_directions' => ['civic_responsibility'],
            'status' => 'approved',
        ]);

        $febEvent = CalendarEvent::create([
            'title' => 'Праздник в феврале',
            'event_type' => 'federal',
            'date_type' => 'fixed_annual',
            'fixed_month' => 2,
            'fixed_day' => 8,
            'description' => 'Февральское событие.',
            'target_age_groups' => ['basic_5_9'],
            'recommended_value_directions' => ['labor_and_creation'],
            'status' => 'approved',
        ]);

        $sepEvent = CalendarEvent::create([
            'title' => 'Сентябрьский праздник вне диапазона',
            'event_type' => 'federal',
            'date_type' => 'fixed_annual',
            'fixed_month' => 9,
            'fixed_day' => 5,
            'description' => 'Сентябрьское событие.',
            'target_age_groups' => ['basic_5_9'],
            'recommended_value_directions' => ['cultural_diversity'],
            'status' => 'approved',
        ]);

        $res = $this->actingAs($this->teacherA, 'web')
            ->getJson('/api/v1/calendar-events?from=2026-11-01&to=2027-02-15');

        $res->assertStatus(200)
            ->assertJsonFragment(['id' => $novEvent->id])
            ->assertJsonFragment(['id' => $febEvent->id])
            ->assertJsonMissing(['id' => $sepEvent->id]);
    }

    /**
     * Requirement BE-002.2 #4: События с floating_rule без рассчитанных occurrences не получают фиктивных дат.
     */
    public function test_floating_rule_without_occurrences_is_not_matched_by_date_filter(): void
    {
        $floatingEvent = CalendarEvent::create([
            'title' => 'Праздник с плавающей датой без occurrences',
            'event_type' => 'federal',
            'date_type' => 'floating_rule',
            'description' => 'Событие с плавающей датой (например, Масленица или последнее воскресенье месяца).',
            'target_age_groups' => ['primary_1_4'],
            'recommended_value_directions' => ['cultural_diversity'],
            'status' => 'approved',
        ]);

        $res = $this->actingAs($this->teacherA, 'web')
            ->getJson('/api/v1/calendar-events?from=2026-11-01&to=2026-11-30');

        $res->assertStatus(200)
            ->assertJsonMissing(['id' => $floatingEvent->id]);
    }
}
