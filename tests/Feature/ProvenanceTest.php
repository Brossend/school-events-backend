<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CalendarEvent;
use App\Models\CulturalClaim;
use App\Models\CulturalClaimSource;
use App\Models\EventSource;
use App\Models\SourceReview;
use App\Models\User;
use App\Services\ProvenanceVerificationService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProvenanceTest extends TestCase
{
    use DatabaseTransactions;

    protected CalendarEvent $event1;

    protected CalendarEvent $event2;

    protected User $curatorGlobal;

    protected User $curator66;

    protected User $curator77;

    protected User $inactiveCurator;

    protected User $teacher;

    protected ProvenanceVerificationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(ProvenanceVerificationService::class);

        $this->curatorGlobal = User::factory()->curator('global')->create();
        $this->curator66 = User::factory()->curator('regional', ['66'])->create();
        $this->curator77 = User::factory()->curator('regional', ['77'])->create();
        $this->inactiveCurator = User::factory()->curator('global')->create(['is_active' => false]);
        $this->teacher = User::factory()->create();

        // Event 1 (Regional 66)
        $this->event1 = CalendarEvent::create([
            'title' => 'День бажовских сказов',
            'event_type' => 'regional',
            'region_code' => '66',
            'date_type' => 'fixed_annual',
            'fixed_month' => 1,
            'fixed_day' => 27,
            'description' => 'Традиционные уральские чтения.',
            'target_age_groups' => ['basic_5_9'],
            'recommended_value_directions' => ['cultural_diversity'],
            'status' => 'approved',
        ]);

        // Event 2 (Federal)
        $this->event2 = CalendarEvent::create([
            'title' => 'День славянской письменности и культуры',
            'event_type' => 'federal',
            'date_type' => 'fixed_annual',
            'fixed_month' => 5,
            'fixed_day' => 24,
            'description' => 'День памяти святых равноапостольных братьев Кирилла и Мефодия.',
            'target_age_groups' => ['primary_1_4', 'basic_5_9'],
            'recommended_value_directions' => ['historical_memory'],
            'status' => 'approved',
        ]);
    }

    /**
     * Test successful claim verification with valid source, approved review, and locator.
     */
    public function test_successful_claim_verification(): void
    {
        $source = EventSource::create([
            'calendar_event_id' => $this->event1->id,
            'source_type' => 'encyclopedia',
            'title' => 'Бажовская энциклопедия',
            'author' => 'Издательство УрФУ',
            'url' => 'https://ural-heritage.ru/bajov-encyclopedia',
            'verified_by_user_id' => $this->curator66->id,
        ]);

        SourceReview::create([
            'event_source_id' => $source->id,
            'reviewer_user_id' => $this->curator66->id,
            'decision' => 'approved',
            'comments' => 'Академическое издание Института истории и археологии УрО РАН.',
            'reviewed_at' => now(),
        ]);

        $claim = CulturalClaim::create([
            'calendar_event_id' => $this->event1->id,
            'claim_text' => 'Образ Хозяйки Медной горы восходит к дохристианским представлениям горняков Урала.',
            'culture_name' => 'Горнозаводская культура Урала',
            'provenance_notes' => 'Фольклорная традиция уральских рабочих.',
        ]);

        $verifiedRecord = $this->service->verifyClaim(
            claim: $claim,
            source: $source,
            citationLocator: 'Стр. 142-148, раздел «Мифология горного промысла»',
            verifier: $this->curator66,
            evidenceExcerpt: '«Образ Хозяйки Медной горы восходит к дохристианским представлениям горняков Урала...»',
            academicYear: '2026-2027',
            notes: 'Первоисточник проверен в фонде Белинки'
        );

        $this->assertInstanceOf(CulturalClaimSource::class, $verifiedRecord);
        $this->assertEquals('verified_authentic', $verifiedRecord->verification_status);
        $this->assertEquals($this->curator66->id, $verifiedRecord->verified_by_user_id);
        $this->assertEquals($this->event1->id, $verifiedRecord->calendar_event_id);
        $this->assertEquals('«Образ Хозяйки Медной горы восходит к дохристианским представлениям горняков Урала...»', $verifiedRecord->citation_excerpt);
        $this->assertEquals('Первоисточник проверен в фонде Белинки', $verifiedRecord->notes);
    }

    /**
     * Requirement 7.1: source_reviews отсутствует -> verified_authentic запрещён
     */
    public function test_absent_source_review_prohibits_claim_verification(): void
    {
        $source = EventSource::create([
            'calendar_event_id' => $this->event1->id,
            'source_type' => 'scientific_publication',
            'title' => 'Статья без рецензии',
            'verified_by_user_id' => null,
        ]);

        $claim = CulturalClaim::create([
            'calendar_event_id' => $this->event1->id,
            'claim_text' => 'Тезис без рецензии на источник',
            'culture_name' => 'Уральская культура',
            'provenance_notes' => 'Заметки',
        ]);

        $this->expectException(ValidationException::class);
        $this->service->verifyClaim(
            claim: $claim,
            source: $source,
            citationLocator: 'Стр. 45',
            verifier: $this->curator66,
            evidenceExcerpt: 'Конкретная проверенная выдержка из текста источника'
        );
    }

    /**
     * Requirement 7.2: latest decision = requires_amendment -> запрещён
     */
    public function test_latest_decision_requires_amendment_prohibits_verification(): void
    {
        $source = EventSource::create([
            'calendar_event_id' => $this->event1->id,
            'source_type' => 'scientific_publication',
            'title' => 'Статья с требованием доработки',
        ]);

        SourceReview::create([
            'event_source_id' => $source->id,
            'reviewer_user_id' => $this->curator66->id,
            'decision' => 'requires_amendment',
            'comments' => 'Требуется уточнить архивные шифры документа.',
            'reviewed_at' => now(),
        ]);

        $claim = CulturalClaim::create([
            'calendar_event_id' => $this->event1->id,
            'claim_text' => 'Спорный факт',
            'culture_name' => 'Культура Урала',
            'provenance_notes' => 'Замечания',
        ]);

        $this->expectException(ValidationException::class);
        $this->service->verifyClaim(
            claim: $claim,
            source: $source,
            citationLocator: 'Стр. 12',
            verifier: $this->curator66,
            evidenceExcerpt: 'Конкретная проверенная выдержка из текста источника'
        );
    }

    /**
     * Requirement 7.3: latest decision = rejected -> запрещён
     */
    public function test_latest_decision_rejected_prohibits_verification(): void
    {
        $source = EventSource::create([
            'calendar_event_id' => $this->event1->id,
            'source_type' => 'scientific_publication',
            'title' => 'Отклоненный источник',
        ]);

        SourceReview::create([
            'event_source_id' => $source->id,
            'reviewer_user_id' => $this->curator66->id,
            'decision' => 'rejected',
            'comments' => 'Источник признан недостоверным.',
            'reviewed_at' => now(),
        ]);

        $claim = CulturalClaim::create([
            'calendar_event_id' => $this->event1->id,
            'claim_text' => 'Недостоверный тезис',
            'culture_name' => 'Культура Урала',
            'provenance_notes' => 'Замечания к тезису',
        ]);

        $this->expectException(ValidationException::class);
        $this->service->verifyClaim(
            claim: $claim,
            source: $source,
            citationLocator: 'Статья 1',
            verifier: $this->curator66,
            evidenceExcerpt: 'Конкретная проверенная выдержка из текста источника'
        );
    }

    /**
     * Requirement 7.4: old approved, latest rejected -> утверждение не выдаётся как подтверждённое
     */
    public function test_old_approved_latest_rejected_invalidates_served_claim(): void
    {
        $source = EventSource::create([
            'calendar_event_id' => $this->event1->id,
            'source_type' => 'scientific_publication',
            'title' => 'Ранее одобренный источник',
            'verified_by_user_id' => $this->curator66->id,
        ]);

        // Review 1: Approved
        SourceReview::create([
            'event_source_id' => $source->id,
            'reviewer_user_id' => $this->curator66->id,
            'decision' => 'approved',
            'comments' => 'Первоначально одобрено.',
            'reviewed_at' => now()->subDay(),
        ]);

        $claim = CulturalClaim::create([
            'calendar_event_id' => $this->event1->id,
            'claim_text' => 'Факт о промыслах',
            'culture_name' => 'Культура Урала',
            'provenance_notes' => 'Заметки',
        ]);

        $this->service->verifyClaim(
            claim: $claim,
            source: $source,
            citationLocator: 'Стр. 99',
            verifier: $this->curator66,
            evidenceExcerpt: 'Цитата из ранее проверенного издания'
        );

        // Review 2: Later rejected due to discovered fabrication
        SourceReview::create([
            'event_source_id' => $source->id,
            'reviewer_user_id' => $this->curator66->id,
            'decision' => 'rejected',
            'comments' => 'Обнаружена фальсификация архивного документа.',
            'reviewed_at' => now(),
        ]);

        // When queried through API, claim MUST NOT be returned as verified_authentic
        $response = $this->actingAs($this->curator66, 'web')
            ->getJson("/api/v1/calendar-events/{$this->event1->id}");

        $response->assertStatus(200);
        $status = $response->json('data.cultural_claims.0.evidence_records.0.verification_status');
        $this->assertNotEquals('verified_authentic', $status);
        $this->assertContains($status, ['rejected_fake', 'unverified']);
    }

    /**
     * Requirement 7.5: verified_by_user_id указан, но approved review отсутствует -> запрещён
     */
    public function test_verified_by_user_id_without_approved_review_is_rejected(): void
    {
        $source = EventSource::create([
            'calendar_event_id' => $this->event1->id,
            'source_type' => 'scientific_publication',
            'title' => 'Источник с заполненным verified_by_user_id без записи review',
            'verified_by_user_id' => $this->curator66->id,
        ]);

        $claim = CulturalClaim::create([
            'calendar_event_id' => $this->event1->id,
            'claim_text' => 'Тезис',
            'culture_name' => 'Культура Урала',
            'provenance_notes' => 'Заметки',
        ]);

        $this->expectException(ValidationException::class);
        $this->service->verifyClaim(
            claim: $claim,
            source: $source,
            citationLocator: 'Стр. 5',
            verifier: $this->curator66,
            evidenceExcerpt: 'Конкретная проверенная выдержка из текста источника'
        );
    }

    /**
     * Requirement 7.6: неправильный reviewer / региональный scope -> запрещён
     */
    public function test_wrong_reviewer_or_out_of_scope_curator_is_rejected(): void
    {
        // Event 1 has region_code '66'. Curator77 has scope ['77'].
        $source = EventSource::create([
            'calendar_event_id' => $this->event1->id,
            'source_type' => 'scientific_publication',
            'title' => 'Уральский источник',
            'verified_by_user_id' => $this->curator77->id,
        ]);

        // Attempting to approve source for region 66 using curator of region 77
        SourceReview::create([
            'event_source_id' => $source->id,
            'reviewer_user_id' => $this->curator77->id,
            'decision' => 'approved',
            'comments' => 'Одобрение куратором чужого региона.',
            'reviewed_at' => now(),
        ]);

        $claim = CulturalClaim::create([
            'calendar_event_id' => $this->event1->id,
            'claim_text' => 'Тезис',
            'culture_name' => 'Культура Урала',
            'provenance_notes' => 'Заметки',
        ]);

        $this->expectException(ValidationException::class);
        $this->service->verifyClaim(
            claim: $claim,
            source: $source,
            citationLocator: 'Стр. 5',
            verifier: $this->curator77,
            evidenceExcerpt: 'Конкретная проверенная выдержка из текста источника'
        );
    }

    /**
     * Requirement 7.7: source другого calendar_event_id -> отказ PostgreSQL composite FK
     */
    public function test_source_of_another_calendar_event_rejected_by_composite_fk(): void
    {
        $claim1 = CulturalClaim::create([
            'calendar_event_id' => $this->event1->id,
            'claim_text' => 'Утверждение из события 1',
            'culture_name' => 'Культура 1',
            'provenance_notes' => 'Запись 1',
        ]);

        $source2 = EventSource::create([
            'calendar_event_id' => $this->event2->id,
            'source_type' => 'ethnographic_archive',
            'title' => 'Источник события 2',
            'verified_by_user_id' => $this->curatorGlobal->id,
        ]);

        SourceReview::create([
            'event_source_id' => $source2->id,
            'reviewer_user_id' => $this->curatorGlobal->id,
            'decision' => 'approved',
            'comments' => 'Одобрено для события 2.',
            'reviewed_at' => now(),
        ]);

        // 1. Service throws ValidationException
        try {
            $this->service->verifyClaim(
                claim: $claim1,
                source: $source2,
                citationLocator: 'Лист 4',
                verifier: $this->curatorGlobal,
                evidenceExcerpt: 'Цитата из источника другого события'
            );
            $this->fail('Service should have blocked cross-event linking.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('event_source_id', $e->errors());
        }

        // 2. Direct SQL insert bypassing service -> DB composite FK must throw QueryException
        $this->expectException(QueryException::class);
        DB::table('cultural_claim_sources')->insert([
            'id' => (string) Str::uuid7(),
            'cultural_claim_id' => $claim1->id,
            'event_source_id' => $source2->id,
            'calendar_event_id' => $claim1->calendar_event_id,
            'citation_locator' => 'Лист 4',
            'verification_status' => 'verified_authentic',
            'verified_by_user_id' => $this->curatorGlobal->id,
            'verified_at' => now(),
            'created_at' => now(),
        ]);
    }

    /**
     * Requirement BE-002.2 P0 & 6.7: Одобренный нормативный источник того же праздника
     * НЕ должен автоматически подтверждать произвольное историческое утверждение.
     */
    public function test_approved_normative_source_cannot_verify_arbitrary_historical_claim(): void
    {
        $lawSource = EventSource::create([
            'calendar_event_id' => $this->event2->id,
            'source_type' => 'normative_act',
            'title' => 'Федеральный закон о памятных датах РФ',
            'verified_by_user_id' => $this->curatorGlobal->id,
        ]);

        SourceReview::create([
            'event_source_id' => $lawSource->id,
            'reviewer_user_id' => $this->curatorGlobal->id,
            'decision' => 'approved',
            'comments' => 'Текст федерального закона проверен по эталонной базе.',
            'reviewed_at' => now(),
        ]);

        // Arbitrary historical claim about 17th century events
        $historicalClaim = CulturalClaim::create([
            'calendar_event_id' => $this->event2->id,
            'claim_text' => 'В 1612 году народное ополчение Минина и Пожарского освободило Москву от интервентов.',
            'culture_name' => 'Общероссийская культура',
            'provenance_notes' => 'Исторические события Смутного времени.',
        ]);

        // 1. Service throws ValidationException: normative act cannot verify historical claims
        try {
            $this->service->verifyClaim(
                claim: $historicalClaim,
                source: $lawSource,
                citationLocator: 'Статья 1, часть 2',
                verifier: $this->curatorGlobal,
                academicYear: '2026-2027',
                evidenceExcerpt: '«Установить день памяти...»'
            );
            $this->fail('Service should have blocked verifying historical claim with normative act.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('event_source_id', $e->errors());
        }

        // 2. Dynamic evaluation also rejects it if manually written to DB
        $claimSource = CulturalClaimSource::create([
            'cultural_claim_id' => $historicalClaim->id,
            'event_source_id' => $lawSource->id,
            'calendar_event_id' => $this->event2->id,
            'citation_locator' => 'Статья 1',
            'citation_excerpt' => '«Установить день памяти...»',
            'verification_status' => 'verified_authentic',
            'verified_by_user_id' => $this->curatorGlobal->id,
            'verified_at' => now(),
            'notes' => 'Нормативный акт',
        ]);

        $evaluated = $this->service->evaluateStatus($claimSource);
        $this->assertEquals('unverified', $evaluated, 'Historical claim linked to normative act must dynamically evaluate to unverified!');
    }

    /**
     * Requirement BE-002.2 #3: Детерминированный tie-breaker для рецензий с одинаковым reviewed_at.
     */
    public function test_official_calendar_publication_cannot_verify_arbitrary_historical_claim(): void
    {
        $lawSource = EventSource::create([
            'calendar_event_id' => $this->event2->id,
            'source_type' => 'official_publication',
            'title' => 'Федеральный закон о памятных датах РФ',
            'verified_by_user_id' => $this->curatorGlobal->id,
        ]);

        SourceReview::create([
            'event_source_id' => $lawSource->id,
            'reviewer_user_id' => $this->curatorGlobal->id,
            'decision' => 'approved',
            'comments' => 'Текст федерального закона проверен по эталонной базе.',
            'reviewed_at' => now(),
        ]);

        // Arbitrary historical claim about 17th century events
        $historicalClaim = CulturalClaim::create([
            'calendar_event_id' => $this->event2->id,
            'claim_text' => 'В 1612 году народное ополчение Минина и Пожарского освободило Москву от интервентов.',
            'culture_name' => 'Общероссийская культура',
            'provenance_notes' => 'Исторические события Смутного времени.',
        ]);

        // 1. Service throws ValidationException: normative act cannot verify historical claims
        try {
            $this->service->verifyClaim(
                claim: $historicalClaim,
                source: $lawSource,
                citationLocator: 'Статья 1, часть 2',
                verifier: $this->curatorGlobal,
                academicYear: '2026-2027',
                evidenceExcerpt: '«Установить день памяти...»'
            );
            $this->fail('Service should have blocked verifying historical claim with normative act.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('event_source_id', $e->errors());
        }

        // 2. Dynamic evaluation also rejects it if manually written to DB
        $claimSource = CulturalClaimSource::create([
            'cultural_claim_id' => $historicalClaim->id,
            'event_source_id' => $lawSource->id,
            'calendar_event_id' => $this->event2->id,
            'citation_locator' => 'Статья 1',
            'citation_excerpt' => '«Установить день памяти...»',
            'verification_status' => 'verified_authentic',
            'verified_by_user_id' => $this->curatorGlobal->id,
            'verified_at' => now(),
            'notes' => 'Нормативный акт',
        ]);

        $evaluated = $this->service->evaluateStatus($claimSource);
        $this->assertEquals('unverified', $evaluated, 'Historical claim linked to normative act must dynamically evaluate to unverified!');
    }

    /**
     * Requirement BE-002.2 #3: Детерминированный tie-breaker для рецензий с одинаковым reviewed_at.
     */
    public function test_source_reviews_with_identical_timestamps_use_deterministic_tie_breaker(): void
    {
        $source = EventSource::create([
            'calendar_event_id' => $this->event1->id,
            'source_type' => 'scientific_publication',
            'title' => 'Исследование уральских сказов',
            'verified_by_user_id' => $this->curator66->id,
        ]);

        $fixedTime = now()->subMinutes(10);

        // Review 1: Approved
        $rev1 = SourceReview::create([
            'event_source_id' => $source->id,
            'reviewer_user_id' => $this->curator66->id,
            'decision' => 'approved',
            'comments' => 'Одобрено.',
            'reviewed_at' => $fixedTime,
        ]);

        // Review 2: Rejected with identical reviewed_at timestamp (created later, higher id)
        $rev2 = SourceReview::create([
            'event_source_id' => $source->id,
            'reviewer_user_id' => $this->curator66->id,
            'decision' => 'rejected',
            'comments' => 'Отклонено из-за плагиата.',
            'reviewed_at' => $fixedTime,
        ]);

        $claim = CulturalClaim::create([
            'calendar_event_id' => $this->event1->id,
            'claim_text' => 'Традиции уральских горщиков',
            'culture_name' => 'Горнозаводская культура',
            'provenance_notes' => 'Заметки',
        ]);

        // Verification must be rejected because deterministic tie-breaker (id DESC) selects the latest review ($rev2 = rejected)
        $this->expectException(ValidationException::class);
        $this->service->verifyClaim(
            claim: $claim,
            source: $source,
            citationLocator: 'Стр. 55',
            verifier: $this->curator66,
            evidenceExcerpt: 'Цитата из исследования'
        );
    }

    /**
     * Requirement BE-002.2 #1: Запрет шаблонных локаторов и пустых выдержек.
     */
    public function test_generic_placeholders_for_locator_and_excerpt_are_rejected(): void
    {
        $source = EventSource::create([
            'calendar_event_id' => $this->event1->id,
            'source_type' => 'scientific_publication',
            'title' => 'Этнография Урала',
            'verified_by_user_id' => $this->curator66->id,
        ]);

        SourceReview::create([
            'event_source_id' => $source->id,
            'reviewer_user_id' => $this->curator66->id,
            'decision' => 'approved',
            'comments' => 'Одобрено.',
            'reviewed_at' => now(),
        ]);

        $claim = CulturalClaim::create([
            'calendar_event_id' => $this->event1->id,
            'claim_text' => 'Традиции',
            'culture_name' => 'Культура Урала',
            'provenance_notes' => 'Заметки',
        ]);

        // 1. Generic locator rejection
        try {
            $this->service->verifyClaim(
                claim: $claim,
                source: $source,
                citationLocator: 'см. источник',
                verifier: $this->curator66,
                evidenceExcerpt: 'Конкретная проверенная цитата'
            );
            $this->fail('Placeholder locator should have been rejected.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('citation_locator', $e->errors());
        }

        // 2. Generic / empty excerpt rejection
        try {
            $this->service->verifyClaim(
                claim: $claim,
                source: $source,
                citationLocator: 'Глава 2, стр. 15',
                verifier: $this->curator66,
                evidenceExcerpt: 'n/a'
            );
            $this->fail('Placeholder excerpt should have been rejected.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('evidence_excerpt', $e->errors());
        }
    }

    /**
     * BE-002.3 Scenario 1: Одобренный источник + локатор + notes, но без evidenceExcerpt -> отказ / unverified.
     */
    public function test_scenario_1_approved_source_with_locator_and_notes_without_excerpt_is_rejected(): void
    {
        $source = EventSource::create([
            'calendar_event_id' => $this->event1->id,
            'source_type' => 'scientific_publication',
            'title' => 'Одобренный сборник фольклора',
            'verified_by_user_id' => $this->curator66->id,
        ]);

        SourceReview::create([
            'event_source_id' => $source->id,
            'reviewer_user_id' => $this->curator66->id,
            'decision' => 'approved',
            'comments' => 'Источник прошел экспертизу.',
            'reviewed_at' => now(),
        ]);

        $claim = CulturalClaim::create([
            'calendar_event_id' => $this->event1->id,
            'claim_text' => 'Тезис о традиции уральских горняков',
            'culture_name' => 'Горнозаводская культура',
            'provenance_notes' => 'Заметки',
        ]);

        // 1. Attempting verification without evidenceExcerpt throws ValidationException (no fallback to notes)
        try {
            $this->service->verifyClaim(
                claim: $claim,
                source: $source,
                citationLocator: 'Стр. 55-58',
                verifier: $this->curator66,
                evidenceExcerpt: null,
                notes: 'Произвольный комментарий эксперта без цитаты'
            );
            $this->fail('Verification without evidenceExcerpt must throw ValidationException!');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('evidence_excerpt', $e->errors());
        }

        // 2. A record in DB having locator and notes but missing citation_excerpt must evaluate to unverified
        $dbRecord = CulturalClaimSource::create([
            'cultural_claim_id' => $claim->id,
            'event_source_id' => $source->id,
            'calendar_event_id' => $this->event1->id,
            'citation_locator' => 'Стр. 55-58',
            'citation_excerpt' => null,
            'verification_status' => 'verified_authentic',
            'verified_by_user_id' => $this->curator66->id,
            'verified_at' => now(),
            'notes' => 'Произвольный комментарий без цитаты',
        ]);

        $status = $this->service->evaluateStatus($dbRecord);
        $this->assertEquals('unverified', $status, 'Record without citation_excerpt must evaluate to unverified!');
    }

    /**
     * BE-002.3 Scenario 2: Одобренный источник + сфабрикованная выдержка без подтверждения человеком -> невозможно подтвердить (unverified).
     */
    public function test_scenario_2_approved_source_with_fabricated_excerpt_without_human_attestation_is_unverified(): void
    {
        $source = EventSource::create([
            'calendar_event_id' => $this->event1->id,
            'source_type' => 'scientific_publication',
            'title' => 'Одобренный академический источник',
            'verified_by_user_id' => $this->curator66->id,
        ]);

        SourceReview::create([
            'event_source_id' => $source->id,
            'reviewer_user_id' => $this->curator66->id,
            'decision' => 'approved',
            'comments' => 'Источник подлинный.',
            'reviewed_at' => now(),
        ]);

        $claim = CulturalClaim::create([
            'calendar_event_id' => $this->event1->id,
            'claim_text' => 'Сфабрикованный тезис, якобы содержащийся в источнике',
            'culture_name' => 'Горнозаводская культура',
            'provenance_notes' => 'Заметки',
        ]);

        // Fabricated record in DB with excerpt, but WITHOUT human verifier attestation
        $unattestedRecord = CulturalClaimSource::create([
            'cultural_claim_id' => $claim->id,
            'event_source_id' => $source->id,
            'calendar_event_id' => $this->event1->id,
            'citation_locator' => 'Стр. 100',
            'citation_excerpt' => 'Сфабрикованный фрагмент текста, не проверявшийся куратором',
            'verification_status' => 'verified_authentic', // Attempted elevation without human verifier
            'verified_by_user_id' => null,
            'verified_at' => null,
            'notes' => null,
        ]);

        // 1. Dynamic evaluation must strictly return unverified
        $this->assertEquals('unverified', $this->service->evaluateStatus($unattestedRecord));

        // 2. Calendar API must return unverified
        $response = $this->actingAs($this->curator66, 'web')
            ->getJson("/api/v1/calendar-events/{$this->event1->id}");

        $response->assertStatus(200);
        $recordStatus = $response->json('data.cultural_claims.0.evidence_records.0.verification_status');
        $this->assertEquals('unverified', $recordStatus);

        // 3. Attempting attestation with ordinary teacher without permissions throws ValidationException
        try {
            $this->service->verifyClaim(
                claim: $claim,
                source: $source,
                citationLocator: 'Стр. 100',
                verifier: $this->teacher,
                evidenceExcerpt: 'Сфабрикованный фрагмент текста'
            );
            $this->fail('Unauthorized user cannot attest claims.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('verified_by_user_id', $e->errors());
        }
    }

    /**
     * BE-002.3 Scenario 3: Верификация источника куратором вне своего региона -> отказ.
     */
    public function test_scenario_3_source_verification_by_out_of_scope_curator_is_rejected(): void
    {
        // Event 1 is regional for region 66 (Свердловская область)
        $source = EventSource::create([
            'calendar_event_id' => $this->event1->id,
            'source_type' => 'scientific_publication',
            'title' => 'Региональный источник Урала',
            'verified_by_user_id' => $this->curator66->id,
        ]);

        SourceReview::create([
            'event_source_id' => $source->id,
            'reviewer_user_id' => $this->curator66->id,
            'decision' => 'approved',
            'comments' => 'Одобрено куратором 66 региона.',
            'reviewed_at' => now(),
        ]);

        $claim = CulturalClaim::create([
            'calendar_event_id' => $this->event1->id,
            'claim_text' => 'Локальный уральский обычай',
            'culture_name' => 'Культура Среднего Урала',
            'provenance_notes' => 'Заметки',
        ]);

        // Curator 77 has scope ['77'] (Москва) and tries to verify a claim for region 66
        $this->expectException(ValidationException::class);
        $this->service->verifyClaim(
            claim: $claim,
            source: $source,
            citationLocator: 'Стр. 25',
            verifier: $this->curator77,
            evidenceExcerpt: '«Народный обычай уральских горщиков...»'
        );
    }

    /**
     * BE-002.3 Scenario 4: Подтверждённый claim после последующего rejected source_review -> не verified_authentic.
     */
    public function test_scenario_4_confirmed_claim_after_subsequent_rejected_source_review_is_not_authentic(): void
    {
        $source = EventSource::create([
            'calendar_event_id' => $this->event1->id,
            'source_type' => 'scientific_publication',
            'title' => 'Издание, первоначально признанное подлинным',
            'verified_by_user_id' => $this->curator66->id,
        ]);

        // Review 1: Approved
        SourceReview::create([
            'event_source_id' => $source->id,
            'reviewer_user_id' => $this->curator66->id,
            'decision' => 'approved',
            'comments' => 'Первичное положительное заключение.',
            'reviewed_at' => now()->subHours(2),
        ]);

        $claim = CulturalClaim::create([
            'calendar_event_id' => $this->event1->id,
            'claim_text' => 'Сведения об уральской традиции',
            'culture_name' => 'Горнозаводская культура',
            'provenance_notes' => 'Первоначальная запись',
        ]);

        // Human attestation succeeds initially
        $attested = $this->service->verifyClaim(
            claim: $claim,
            source: $source,
            citationLocator: 'Том 2, стр. 88',
            verifier: $this->curator66,
            evidenceExcerpt: '«Подлинная цитата из полевых этнографических записей...»',
            academicYear: '2026-2027',
            notes: 'Первоначально подтверждено'
        );

        $this->assertEquals('verified_authentic', $this->service->evaluateStatus($attested));

        // Review 2: Subsequent rejection due to discovered falsification
        SourceReview::create([
            'event_source_id' => $source->id,
            'reviewer_user_id' => $this->curator66->id,
            'decision' => 'rejected',
            'comments' => 'Повторная академическая экспертиза установила подлог архивного документа.',
            'reviewed_at' => now(),
        ]);

        // Dynamic evaluation must NOT return verified_authentic (returns rejected_fake)
        $evaluatedStatus = $this->service->evaluateStatus($attested);
        $this->assertNotEquals('verified_authentic', $evaluatedStatus);
        $this->assertEquals('rejected_fake', $evaluatedStatus);

        // Serving through API must not return verified_authentic
        $response = $this->actingAs($this->curator66, 'web')
            ->getJson("/api/v1/calendar-events/{$this->event1->id}");

        $response->assertStatus(200);
        $apiStatus = $response->json('data.cultural_claims.0.evidence_records.0.verification_status');
        $this->assertNotEquals('verified_authentic', $apiStatus);
    }

    /**
     * BE-002.3 Scenario 5: Полная цепочка с валидным подтверждением claim-to-evidence -> verified_authentic.
     */
    public function test_scenario_5_full_chain_with_valid_claim_to_evidence_attestation(): void
    {
        // 1. Regional event (66)
        $this->assertNotNull($this->event1);
        $this->assertEquals('66', $this->event1->region_code);

        // 2. Source created
        $source = EventSource::create([
            'calendar_event_id' => $this->event1->id,
            'source_type' => 'scientific_publication',
            'title' => 'Этнографические очерки Среднего Урала',
            'author' => 'УрО РАН',
            'url' => 'https://ural-ethnography.ru/vol-3',
            'verified_by_user_id' => $this->curator66->id,
        ]);

        // 3. Level 1: Source Review approved by authorized regional curator
        SourceReview::create([
            'event_source_id' => $source->id,
            'reviewer_user_id' => $this->curator66->id,
            'decision' => 'approved',
            'comments' => 'Академическая рецензия подтверждает научную достоверность источника.',
            'reviewed_at' => now(),
        ]);

        // 4. Cultural claim created
        $claim = CulturalClaim::create([
            'calendar_event_id' => $this->event1->id,
            'claim_text' => 'Традиция почитания духов рудных жил горнозаводскими рабочими Урала.',
            'culture_name' => 'Горнозаводская традиция Урала',
            'provenance_notes' => 'Этнографические экспедиции 1930-х годов.',
        ]);

        // 5. Level 2: Explicit human claim-to-evidence attestation by curator66
        $exactLocator = 'Глава 4, § 2, стр. 142-148';
        $exactExcerpt = '«Почитание хранителей недр в заводских поселках Урала зафиксировано в записях...»';
        $expertNotes = 'Проверено по фондам Музея истории и археологии Урала.';
        $academicYear = '2026-2027';

        $claimSource = $this->service->verifyClaim(
            claim: $claim,
            source: $source,
            citationLocator: $exactLocator,
            verifier: $this->curator66,
            evidenceExcerpt: $exactExcerpt,
            academicYear: $academicYear,
            notes: $expertNotes
        );

        // 6. Assert all fields persisted correctly with excerpt and notes separated
        $this->assertInstanceOf(CulturalClaimSource::class, $claimSource);
        $this->assertEquals('verified_authentic', $claimSource->verification_status);
        $this->assertEquals($claim->id, $claimSource->cultural_claim_id);
        $this->assertEquals($source->id, $claimSource->event_source_id);
        $this->assertEquals($this->event1->id, $claimSource->calendar_event_id);
        $this->assertEquals($exactLocator, $claimSource->citation_locator);
        $this->assertEquals($exactExcerpt, $claimSource->citation_excerpt);
        $this->assertEquals($expertNotes, $claimSource->notes);
        $this->assertEquals($this->curator66->id, $claimSource->verified_by_user_id);
        $this->assertNotNull($claimSource->verified_at);
        $this->assertEquals($academicYear, $claimSource->applicable_academic_year);

        // 7. Dynamic evaluation confirms verified_authentic
        $evaluated = $this->service->evaluateStatus($claimSource);
        $this->assertEquals('verified_authentic', $evaluated);

        // 8. API served response includes verified_authentic and citation_excerpt
        $response = $this->actingAs($this->curator66, 'web')
            ->getJson("/api/v1/calendar-events/{$this->event1->id}");

        $response->assertStatus(200);
        $evidenceRecord = $response->json('data.cultural_claims.0.evidence_records.0');
        $this->assertEquals('verified_authentic', $evidenceRecord['verification_status']);
        $this->assertEquals($exactLocator, $evidenceRecord['citation_locator']);
        $this->assertEquals($exactExcerpt, $evidenceRecord['citation_excerpt']);
        $this->assertEquals($expertNotes, $evidenceRecord['notes']);
    }
}
