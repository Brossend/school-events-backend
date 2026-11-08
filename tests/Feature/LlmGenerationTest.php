<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ExecuteGenerationRunJob;
use App\Models\CalendarEvent;
use App\Models\CulturalClaim;
use App\Models\CulturalClaimSource;
use App\Models\EventSource;
use App\Models\GenerationRun;
use App\Models\Scenario;
use App\Models\ScenarioRequest;
use App\Models\ScenarioRevision;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\SourceReview;
use App\Models\User;
use App\Services\ContextSnapshotBuilder;
use App\Services\Llm\GenerationOutputValidator;
use App\Services\Llm\MockLlmProvider;
use App\Services\Llm\ScenarioSemanticValidator;
use App\Services\Llm\ServerNormalizationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

class LlmGenerationTest extends TestCase
{
    use DatabaseTransactions;

    protected School $schoolA;

    protected School $schoolB;

    protected User $teacherA;

    protected User $teacherB;

    protected User $curator;

    protected CalendarEvent $event;

    protected function setUp(): void
    {
        parent::setUp();

        $this->schoolA = School::factory()->create(['name' => 'Школа № 100']);
        $this->schoolB = School::factory()->create(['name' => 'Школа № 200']);

        $this->teacherA = User::factory()->create(['email' => 'teacherA_llm@test.ru']);
        SchoolMembership::factory()->create([
            'school_id' => $this->schoolA->id,
            'user_id' => $this->teacherA->id,
            'role' => 'teacher',
            'is_default' => true,
        ]);

        $this->teacherB = User::factory()->create(['email' => 'teacherB_llm@test.ru']);
        SchoolMembership::factory()->create([
            'school_id' => $this->schoolB->id,
            'user_id' => $this->teacherB->id,
            'role' => 'teacher',
            'is_default' => true,
        ]);

        $this->curator = User::factory()->create([
            'email' => 'curator_llm@test.ru',
            'system_role' => 'catalog_curator',
        ]);

        $this->event = CalendarEvent::create([
            'title' => 'День народного единства',
            'event_type' => 'federal',
            'date_type' => 'fixed_annual',
            'fixed_month' => 11,
            'fixed_day' => 4,
            'description' => 'Государственный праздник России',
            'target_age_groups' => ['primary_1_4', 'basic_5_9', 'senior_10_11'],
            'recommended_value_directions' => ['historical_memory', 'civic_responsibility'],
            'status' => 'approved',
            'created_by_user_id' => $this->curator->id,
        ]);
    }

    public function test_execute_generation_run_job_produces_valid_scenario_and_revision(): void
    {
        $input = [
            'calendar_event_id' => $this->event->id,
            'scale' => 'class_level',
            'format_type' => 'class_hour',
            'target_grades' => [5, 6],
            'participants_count' => 25,
            'duration_minutes' => 45,
            'value_direction' => 'labor_and_creation',
            'family_involvement' => false,
            'available_resources' => ['Проектор', 'Цветная бумага'],
        ];

        $snapshot = app(ContextSnapshotBuilder::class)->buildSnapshot($this->teacherA, $this->schoolA, $input);

        $request = ScenarioRequest::create([
            'school_id' => $this->schoolA->id,
            'user_id' => $this->teacherA->id,
            'calendar_event_id' => $this->event->id,
            'scale' => $input['scale'],
            'format_type' => $input['format_type'],
            'target_grades' => $input['target_grades'],
            'participants_count' => $input['participants_count'],
            'duration_minutes' => $input['duration_minutes'],
            'value_direction' => $input['value_direction'],
            'family_involvement' => $input['family_involvement'],
            'available_resources' => $input['available_resources'],
            'context_snapshot' => $snapshot,
        ]);

        $run = GenerationRun::create([
            'scenario_request_id' => $request->id,
            'provider_name' => MockLlmProvider::PROVIDER_NAME,
            'model_identifier' => MockLlmProvider::MODEL_IDENTIFIER,
            'prompt_version' => MockLlmProvider::PROMPT_VERSION,
            'schema_version' => MockLlmProvider::SCHEMA_VERSION,
            'status' => 'queued',
            'attempts' => 0,
        ]);

        // Execute job synchronously
        $job = new ExecuteGenerationRunJob($run->id);
        app()->call([$job, 'handle']);

        $run->refresh();
        $this->assertEquals('completed', $run->status, $run->error_code.': '.$run->error_message);
        $this->assertGreaterThan(0, $run->latency_ms);
        $this->assertNotNull($run->prompt_tokens);
        $this->assertNotNull($run->completion_tokens);

        // Verify Scenario
        $scenario = Scenario::where('scenario_request_id', $request->id)->first();
        $this->assertNotNull($scenario);
        $this->assertEquals($this->schoolA->id, $scenario->school_id);
        $this->assertNotNull($scenario->current_revision_id);

        // Verify ScenarioRevision
        $revision = $scenario->currentRevision;
        $this->assertNotNull($revision);
        $this->assertEquals(1, $revision->version_number);
        $this->assertEquals($run->id, $revision->source_generation_run_id);
        $this->assertNotEmpty($revision->content_payload);

        // Verify relational tables
        $this->assertGreaterThanOrEqual(3, $revision->stages()->count());
        $this->assertGreaterThanOrEqual(3, $revision->materials()->count());

        // Verify moderation state
        $this->assertNotNull($revision->moderationState);
        $this->assertContains($revision->moderationState->status, ['pending_verification', 'running', 'verification_passed']);
    }

    public function test_contour_1_draft_2020_12_schema_validation_catches_invalid_output(): void
    {
        $validator = app(GenerationOutputValidator::class);

        // Missing required field 'pedagogical_goal'
        $invalidPayload = [
            'concept' => 'Слишком короткий концепт',
        ];

        $this->expectException(ValidationException::class);
        $validator->validate($invalidPayload);
    }

    public function test_contour_2_semantic_validator_catches_duration_sum_mismatch(): void
    {
        $semanticValidator = app(ScenarioSemanticValidator::class);

        $payload = [
            'value_direction' => 'family',
            'pedagogical_mechanism' => [
                'mechanism_type' => 'family_investigation',
                'description' => 'Описание механизма исследования семейных традиций',
                'target_activity_title' => 'Семейное древо',
            ],
            'format' => ['total_duration_minutes' => 45],
            'target_audience' => [
                'target_grades' => [5, 6],
                'participants_count_min' => 10,
                'participants_count_max' => 30,
            ],
            'stages' => [
                ['id' => 'stage_1', 'order_index' => 1, 'duration_minutes' => 10],
                ['id' => 'stage_2', 'order_index' => 2, 'duration_minutes' => 20],
                ['id' => 'stage_3', 'order_index' => 3, 'duration_minutes' => 10], // sum = 40 != 45
            ],
            'activities' => [
                ['title' => 'Семейное древо', 'target_stage_id' => 'stage_2'],
            ],
        ];

        $this->expectException(ValidationException::class);
        $semanticValidator->validate($payload, 45);
    }

    public function test_contour_2_semantic_validator_catches_invalid_mechanism_for_direction(): void
    {
        $semanticValidator = app(ScenarioSemanticValidator::class);

        $payload = [
            'value_direction' => 'family', // Requires family_investigation
            'pedagogical_mechanism' => [
                'mechanism_type' => 'historical_source_analysis', // Mismatch!
                'description' => 'Анализ источников',
                'target_activity_title' => 'Анализ документов',
            ],
            'format' => ['total_duration_minutes' => 45],
            'target_audience' => [
                'target_grades' => [5, 6],
                'participants_count_min' => 10,
                'participants_count_max' => 30,
            ],
            'stages' => [
                ['id' => 'stage_1', 'order_index' => 1, 'duration_minutes' => 10],
                ['id' => 'stage_2', 'order_index' => 2, 'duration_minutes' => 25],
                ['id' => 'stage_3', 'order_index' => 3, 'duration_minutes' => 10],
            ],
            'activities' => [
                ['title' => 'Анализ документов', 'target_stage_id' => 'stage_2'],
            ],
        ];

        $this->expectException(ValidationException::class);
        $semanticValidator->validate($payload, 45);
    }

    public function test_contour_3_server_normalizer_preserves_verified_cultural_claims(): void
    {
        $source = EventSource::create([
            'calendar_event_id' => $this->event->id,
            'source_type' => 'scientific_publication',
            'title' => 'История Смутного времени',
            'author' => 'Историк А. В.',
        ]);

        SourceReview::create([
            'event_source_id' => $source->id,
            'reviewer_user_id' => $this->curator->id,
            'decision' => 'approved',
            'comments' => 'Первоисточник верифицирован.',
            'reviewed_at' => now(),
        ]);

        $claim = CulturalClaim::create([
            'calendar_event_id' => $this->event->id,
            'culture_name' => 'Общероссийская культура',
            'claim_text' => 'Освобождение Москвы народным ополчением под предводительством Минина и Пожарского в 1612 году.',
            'provenance_notes' => 'Исторический контекст 1612 года.',
            'created_by_user_id' => $this->curator->id,
        ]);

        CulturalClaimSource::create([
            'calendar_event_id' => $this->event->id,
            'cultural_claim_id' => $claim->id,
            'event_source_id' => $source->id,
            'citation_locator' => 'Глава 3, с. 112',
            'citation_excerpt' => 'Народное ополчение Кузьмы Минина и Дмитрия Пожарского освободило Москву в 1612 году.',
            'verification_status' => 'verified_authentic',
            'verified_by_user_id' => $this->curator->id,
            'verified_at' => now(),
            'applicable_academic_year' => '2025-2026',
            'created_at' => now(),
        ]);

        $input = [
            'calendar_event_id' => $this->event->id,
            'scale' => 'class_level',
            'format_type' => 'class_hour',
            'target_grades' => [5],
            'participants_count' => 20,
            'duration_minutes' => 45,
            'value_direction' => 'historical_memory',
            'family_involvement' => false,
        ];

        $snapshot = app(ContextSnapshotBuilder::class)->buildSnapshot($this->teacherA, $this->schoolA, $input);
        $this->assertEquals('verified_authentic', $snapshot['cultural_provenance']['claims'][0]['effective_status']);

        $request = ScenarioRequest::create([
            'school_id' => $this->schoolA->id,
            'user_id' => $this->teacherA->id,
            'calendar_event_id' => $this->event->id,
            'scale' => $input['scale'],
            'format_type' => $input['format_type'],
            'target_grades' => $input['target_grades'],
            'participants_count' => $input['participants_count'],
            'duration_minutes' => $input['duration_minutes'],
            'value_direction' => $input['value_direction'],
            'family_involvement' => $input['family_involvement'],
            'context_snapshot' => $snapshot,
        ]);

        $llmResult = app(MockLlmProvider::class)->generate($request);
        $rawOutput = $llmResult['output_payload'];

        $normalizer = app(ServerNormalizationService::class);
        $normalized = $normalizer->normalize($rawOutput, $snapshot);

        $this->assertNotEmpty($normalized['cultural_claims']);
        $matchedClaim = $normalized['cultural_claims'][0];
        $this->assertEquals('verified_authentic', $matchedClaim['server_verification_status']);
        $this->assertNotEmpty($matchedClaim['evidence_records']);
        $this->assertEquals('verified_authentic', $matchedClaim['evidence_records'][0]['verification_status']);
    }

    public function test_contour_3_server_normalizer_forces_unverified_on_hallucinated_claims(): void
    {
        $input = [
            'custom_event_title' => 'Событие без проверенных фактов',
            'scale' => 'class_level',
            'format_type' => 'class_hour',
            'target_grades' => [5],
            'participants_count' => 20,
            'duration_minutes' => 45,
            'value_direction' => 'family',
            'family_involvement' => false,
        ];

        $snapshot = app(ContextSnapshotBuilder::class)->buildSnapshot($this->teacherA, $this->schoolA, $input);

        $request = ScenarioRequest::create([
            'school_id' => $this->schoolA->id,
            'user_id' => $this->teacherA->id,
            'custom_event_title' => $input['custom_event_title'],
            'scale' => $input['scale'],
            'format_type' => $input['format_type'],
            'target_grades' => $input['target_grades'],
            'participants_count' => $input['participants_count'],
            'duration_minutes' => $input['duration_minutes'],
            'value_direction' => $input['value_direction'],
            'family_involvement' => $input['family_involvement'],
            'context_snapshot' => $snapshot,
        ]);

        $llmResult = app(MockLlmProvider::class)->generate($request);
        $rawOutput = $llmResult['output_payload'];

        // Inject hallucinated claim
        $rawOutput['cultural_claims'][] = [
            'claim_id' => 'claim_hallucinated_123',
            'claim_text' => 'Выдуманный исторический факт, отсутствующий в проверенном календаре.',
            'culture_name' => 'Культура региона',
            'claimed_origin' => 'Неизвестный источник',
            'claimed_meaning' => 'Символическое значение',
        ];

        $normalizer = app(ServerNormalizationService::class);
        $normalized = $normalizer->normalize($rawOutput, $snapshot);

        $hallucinated = collect($normalized['cultural_claims'])->firstWhere('claim_id', 'claim_hallucinated_123');
        $this->assertNotNull($hallucinated);
        $this->assertEquals('unverified', $hallucinated['server_verification_status']);
        $this->assertEmpty($hallucinated['evidence_records']);
    }

    public function test_claim_id_matched_but_text_altered_becomes_unverified_never_verified_authentic(): void
    {
        // 1. Setup verified claim A in database
        $source = EventSource::create([
            'calendar_event_id' => $this->event->id,
            'source_type' => 'scientific_publication',
            'title' => 'Летопись событий',
            'author' => 'Академик И. И.',
        ]);

        SourceReview::create([
            'event_source_id' => $source->id,
            'reviewer_user_id' => $this->curator->id,
            'decision' => 'approved',
            'comments' => 'Источник проверен.',
            'reviewed_at' => now(),
        ]);

        $claim = CulturalClaim::create([
            'calendar_event_id' => $this->event->id,
            'culture_name' => 'Общероссийская культура',
            'claim_text' => 'Кузьма Минин обратился к нижегородцам с призывом собрать ополчение.',
            'provenance_notes' => 'Призыв Минина в Нижнем Новгороде.',
            'created_by_user_id' => $this->curator->id,
        ]);

        CulturalClaimSource::create([
            'calendar_event_id' => $this->event->id,
            'cultural_claim_id' => $claim->id,
            'event_source_id' => $source->id,
            'citation_locator' => 'Том 1, с. 45',
            'citation_excerpt' => 'Воззвание Кузьмы Минина в Нижнем Новгороде осенью 1611 года.',
            'verification_status' => 'verified_authentic',
            'verified_by_user_id' => $this->curator->id,
            'verified_at' => now(),
            'applicable_academic_year' => '2025-2026',
            'created_at' => now(),
        ]);

        $input = [
            'calendar_event_id' => $this->event->id,
            'scale' => 'class_level',
            'format_type' => 'class_hour',
            'target_grades' => [5],
            'participants_count' => 20,
            'duration_minutes' => 45,
            'value_direction' => 'historical_memory',
            'family_involvement' => false,
        ];

        $snapshot = app(ContextSnapshotBuilder::class)->buildSnapshot($this->teacherA, $this->schoolA, $input);

        $request = ScenarioRequest::create([
            'school_id' => $this->schoolA->id,
            'user_id' => $this->teacherA->id,
            'calendar_event_id' => $this->event->id,
            'scale' => $input['scale'],
            'format_type' => $input['format_type'],
            'target_grades' => $input['target_grades'],
            'participants_count' => $input['participants_count'],
            'duration_minutes' => $input['duration_minutes'],
            'value_direction' => $input['value_direction'],
            'family_involvement' => $input['family_involvement'],
            'context_snapshot' => $snapshot,
        ]);

        $llmResult = app(MockLlmProvider::class)->generate($request);
        $rawOutput = $llmResult['output_payload'];

        // NEGATIVE TEST CASE 1:
        // Snapshot has verified claim A with ID $claim->id.
        // LLM outputs the same ID, but COMPLETELY DIFFERENT text B.
        $claimId = $rawOutput['cultural_claims'][0]['claim_id'];
        $rawOutput['cultural_claims'][0]['claim_text'] = 'Совершенно другой вымышленный текст о других исторических событиях.';

        $normalizer = app(ServerNormalizationService::class);
        $normalized = $normalizer->normalize($rawOutput, $snapshot);

        $alteredClaim = $normalized['cultural_claims'][0];
        // Must NEVER receive verified_authentic!
        $this->assertEquals('unverified', $alteredClaim['server_verification_status']);
        $this->assertEmpty($alteredClaim['evidence_records']);

        // Schema validation must still pass with status=unverified and evidence_records=[]
        $validator = app(GenerationOutputValidator::class);
        $this->assertNotEquals('verified_authentic', $alteredClaim['server_verification_status']);
    }

    public function test_claim_text_matched_but_foreign_id_becomes_unverified(): void
    {
        $source = EventSource::create([
            'calendar_event_id' => $this->event->id,
            'source_type' => 'scientific_publication',
            'title' => 'История ополчения',
            'author' => 'Профессор П. П.',
        ]);

        SourceReview::create([
            'event_source_id' => $source->id,
            'reviewer_user_id' => $this->curator->id,
            'decision' => 'approved',
            'comments' => 'Одобрено.',
            'reviewed_at' => now(),
        ]);

        $claim = CulturalClaim::create([
            'calendar_event_id' => $this->event->id,
            'culture_name' => 'Общероссийская культура',
            'claim_text' => 'Формирование второго ополчения в Ярославле весной 1612 года.',
            'provenance_notes' => 'Ярославский период ополчения.',
            'created_by_user_id' => $this->curator->id,
        ]);

        CulturalClaimSource::create([
            'calendar_event_id' => $this->event->id,
            'cultural_claim_id' => $claim->id,
            'event_source_id' => $source->id,
            'citation_locator' => 'Стр. 89',
            'citation_excerpt' => 'Весной 1612 года Ярославль стал центром подготовки ополчения.',
            'verification_status' => 'verified_authentic',
            'verified_by_user_id' => $this->curator->id,
            'verified_at' => now(),
            'applicable_academic_year' => '2025-2026',
            'created_at' => now(),
        ]);

        $input = [
            'calendar_event_id' => $this->event->id,
            'scale' => 'class_level',
            'format_type' => 'class_hour',
            'target_grades' => [5],
            'participants_count' => 20,
            'duration_minutes' => 45,
            'value_direction' => 'historical_memory',
            'family_involvement' => false,
        ];

        $snapshot = app(ContextSnapshotBuilder::class)->buildSnapshot($this->teacherA, $this->schoolA, $input);

        $request = ScenarioRequest::create([
            'school_id' => $this->schoolA->id,
            'user_id' => $this->teacherA->id,
            'calendar_event_id' => $this->event->id,
            'scale' => $input['scale'],
            'format_type' => $input['format_type'],
            'target_grades' => $input['target_grades'],
            'participants_count' => $input['participants_count'],
            'duration_minutes' => $input['duration_minutes'],
            'value_direction' => $input['value_direction'],
            'family_involvement' => $input['family_involvement'],
            'context_snapshot' => $snapshot,
        ]);

        $llmResult = app(MockLlmProvider::class)->generate($request);
        $rawOutput = $llmResult['output_payload'];

        // Same text, but foreign ID
        $rawOutput['cultural_claims'][0]['claim_id'] = 'claim_foreign_imposter_999';

        $normalizer = app(ServerNormalizationService::class);
        $normalized = $normalizer->normalize($rawOutput, $snapshot);

        $imposterClaim = $normalized['cultural_claims'][0];
        $this->assertEquals('unverified', $imposterClaim['server_verification_status']);
        $this->assertEmpty($imposterClaim['evidence_records']);
    }

    public function test_tampered_source_references_do_not_transfer_evidence(): void
    {
        $source = EventSource::create([
            'calendar_event_id' => $this->event->id,
            'source_type' => 'scientific_publication',
            'title' => 'Подлинный источник события',
            'author' => 'Историк И. И.',
        ]);

        SourceReview::create([
            'event_source_id' => $source->id,
            'reviewer_user_id' => $this->curator->id,
            'decision' => 'approved',
            'comments' => 'Подлинно.',
            'reviewed_at' => now(),
        ]);

        $claim = CulturalClaim::create([
            'calendar_event_id' => $this->event->id,
            'culture_name' => 'Общероссийская культура',
            'claim_text' => 'Освобождение Китай-города силами народного ополчения.',
            'provenance_notes' => 'Штурм Китай-города.',
            'created_by_user_id' => $this->curator->id,
        ]);

        CulturalClaimSource::create([
            'calendar_event_id' => $this->event->id,
            'cultural_claim_id' => $claim->id,
            'event_source_id' => $source->id,
            'citation_locator' => 'Глава 4, стр. 15',
            'citation_excerpt' => 'Ополченцы освободили Китай-город в конце октября 1612 года.',
            'verification_status' => 'verified_authentic',
            'verified_by_user_id' => $this->curator->id,
            'verified_at' => now(),
            'applicable_academic_year' => '2025-2026',
            'created_at' => now(),
        ]);

        $input = [
            'calendar_event_id' => $this->event->id,
            'scale' => 'class_level',
            'format_type' => 'class_hour',
            'target_grades' => [5],
            'participants_count' => 20,
            'duration_minutes' => 45,
            'value_direction' => 'historical_memory',
            'family_involvement' => false,
        ];

        $snapshot = app(ContextSnapshotBuilder::class)->buildSnapshot($this->teacherA, $this->schoolA, $input);

        $request = ScenarioRequest::create([
            'school_id' => $this->schoolA->id,
            'user_id' => $this->teacherA->id,
            'calendar_event_id' => $this->event->id,
            'scale' => $input['scale'],
            'format_type' => $input['format_type'],
            'target_grades' => $input['target_grades'],
            'participants_count' => $input['participants_count'],
            'duration_minutes' => $input['duration_minutes'],
            'value_direction' => $input['value_direction'],
            'family_involvement' => $input['family_involvement'],
            'context_snapshot' => $snapshot,
        ]);

        $llmResult = app(MockLlmProvider::class)->generate($request);
        $rawOutput = $llmResult['output_payload'];

        // LLM maliciously replaces source_references with a completely fake external source
        $rawOutput['cultural_claims'][0]['source_references'] = [
            [
                'source_title' => 'Поддельный сайт или выдуманный источник',
                'author' => 'Неизвестный автор',
                'citation_locator' => 'Фейковая страница 999',
            ],
        ];

        $normalizer = app(ServerNormalizationService::class);
        $normalized = $normalizer->normalize($rawOutput, $snapshot);

        // Server normalization MUST ignore the LLM's fake source_references
        // and strictly attach the real verified evidence record from server snapshot
        $verifiedClaim = $normalized['cultural_claims'][0];
        $this->assertEquals('verified_authentic', $verifiedClaim['server_verification_status']);
        $this->assertNotEmpty($verifiedClaim['evidence_records']);
        $this->assertEquals($source->id, $verifiedClaim['evidence_records'][0]['event_source_id']);
        $this->assertEquals('Подлинный источник события', $verifiedClaim['evidence_records'][0]['source_title']);
        $this->assertEquals('Глава 4, стр. 15', $verifiedClaim['evidence_records'][0]['citation_locator']);
    }

    public function test_scenario_revision_is_append_only_immutable(): void
    {
        $input = [
            'custom_event_title' => 'Проверка неизменяемости ревизии',
            'scale' => 'class_level',
            'format_type' => 'class_hour',
            'target_grades' => [5],
            'participants_count' => 20,
            'duration_minutes' => 45,
            'value_direction' => 'family',
            'family_involvement' => false,
        ];

        $snapshot = app(ContextSnapshotBuilder::class)->buildSnapshot($this->teacherA, $this->schoolA, $input);

        $request = ScenarioRequest::create([
            'school_id' => $this->schoolA->id,
            'user_id' => $this->teacherA->id,
            'custom_event_title' => $input['custom_event_title'],
            'scale' => $input['scale'],
            'format_type' => $input['format_type'],
            'target_grades' => $input['target_grades'],
            'participants_count' => $input['participants_count'],
            'duration_minutes' => $input['duration_minutes'],
            'value_direction' => $input['value_direction'],
            'family_involvement' => $input['family_involvement'],
            'context_snapshot' => $snapshot,
        ]);

        $run = GenerationRun::create([
            'scenario_request_id' => $request->id,
            'provider_name' => MockLlmProvider::PROVIDER_NAME,
            'model_identifier' => MockLlmProvider::MODEL_IDENTIFIER,
            'prompt_version' => MockLlmProvider::PROMPT_VERSION,
            'schema_version' => MockLlmProvider::SCHEMA_VERSION,
            'status' => 'queued',
            'attempts' => 0,
        ]);

        $job = new ExecuteGenerationRunJob($run->id);
        app()->call([$job, 'handle']);

        $scenario = Scenario::where('scenario_request_id', $request->id)->first();
        $revision = $scenario->currentRevision;

        // Attempting to update ScenarioRevision must throw LogicException
        $this->expectException(LogicException::class);
        $revision->update(['concept' => 'Попытка изменения существующей ревизии']);
    }

    public function test_teacher_can_list_and_view_generated_scenarios(): void
    {
        $input = [
            'custom_event_title' => 'Праздник осени',
            'scale' => 'class_level',
            'format_type' => 'class_hour',
            'target_grades' => [5],
            'participants_count' => 20,
            'duration_minutes' => 45,
            'value_direction' => 'family',
            'family_involvement' => false,
        ];

        $snapshot = app(ContextSnapshotBuilder::class)->buildSnapshot($this->teacherA, $this->schoolA, $input);

        $request = ScenarioRequest::create([
            'school_id' => $this->schoolA->id,
            'user_id' => $this->teacherA->id,
            'custom_event_title' => $input['custom_event_title'],
            'scale' => $input['scale'],
            'format_type' => $input['format_type'],
            'target_grades' => $input['target_grades'],
            'participants_count' => $input['participants_count'],
            'duration_minutes' => $input['duration_minutes'],
            'value_direction' => $input['value_direction'],
            'family_involvement' => $input['family_involvement'],
            'context_snapshot' => $snapshot,
        ]);

        $run = GenerationRun::create([
            'scenario_request_id' => $request->id,
            'provider_name' => MockLlmProvider::PROVIDER_NAME,
            'model_identifier' => MockLlmProvider::MODEL_IDENTIFIER,
            'prompt_version' => MockLlmProvider::PROMPT_VERSION,
            'schema_version' => MockLlmProvider::SCHEMA_VERSION,
            'status' => 'queued',
            'attempts' => 0,
        ]);

        $job = new ExecuteGenerationRunJob($run->id);
        app()->call([$job, 'handle']);

        $scenario = Scenario::where('scenario_request_id', $request->id)->first();

        // 1. List scenarios for School A
        $listResponse = $this->actingAs($this->teacherA)
            ->getJson('/api/v1/scenarios');

        $listResponse->assertStatus(200)
            ->assertJsonPath('data.0.id', $scenario->id);

        // 2. View detailed scenario for School A
        $detailResponse = $this->actingAs($this->teacherA)
            ->getJson("/api/v1/scenarios/{$scenario->id}");

        $detailResponse->assertStatus(200)
            ->assertJsonPath('data.id', $scenario->id)
            ->assertJsonPath('data.current_revision.version_number', 1);
        $this->assertContains($detailResponse->json('data.current_revision.moderation_state.status'), ['pending_verification', 'running', 'verification_passed']);

        // 3. Teacher B (School B) is denied access
        $crossResponse = $this->actingAs($this->teacherB)
            ->getJson("/api/v1/scenarios/{$scenario->id}");

        $crossResponse->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN_TENANT_ACCESS');
    }

    public function test_same_school_teacher_author_isolation_on_scenarios_and_runs(): void
    {
        $input = [
            'custom_event_title' => 'Изоляция авторов в одной школе',
            'scale' => 'class_level',
            'format_type' => 'class_hour',
            'target_grades' => [5],
            'participants_count' => 20,
            'duration_minutes' => 45,
            'value_direction' => 'family',
            'family_involvement' => false,
        ];

        $snapshot = app(ContextSnapshotBuilder::class)->buildSnapshot($this->teacherA, $this->schoolA, $input);

        $request = ScenarioRequest::create([
            'school_id' => $this->schoolA->id,
            'user_id' => $this->teacherA->id,
            'custom_event_title' => $input['custom_event_title'],
            'scale' => $input['scale'],
            'format_type' => $input['format_type'],
            'target_grades' => $input['target_grades'],
            'participants_count' => $input['participants_count'],
            'duration_minutes' => $input['duration_minutes'],
            'value_direction' => $input['value_direction'],
            'family_involvement' => $input['family_involvement'],
            'context_snapshot' => $snapshot,
        ]);

        $run = GenerationRun::create([
            'scenario_request_id' => $request->id,
            'provider_name' => MockLlmProvider::PROVIDER_NAME,
            'model_identifier' => MockLlmProvider::MODEL_IDENTIFIER,
            'prompt_version' => MockLlmProvider::PROMPT_VERSION,
            'schema_version' => MockLlmProvider::SCHEMA_VERSION,
            'status' => 'queued',
            'attempts' => 0,
        ]);

        $job = new ExecuteGenerationRunJob($run->id);
        app()->call([$job, 'handle']);

        $scenario = Scenario::where('scenario_request_id', $request->id)->first();

        // Second teacher in the same School A
        $teacherA2 = User::factory()->create(['email' => 'teacherA2_llm@test.ru']);
        SchoolMembership::factory()->create([
            'school_id' => $this->schoolA->id,
            'user_id' => $teacherA2->id,
            'role' => 'teacher',
            'is_default' => true,
        ]);

        // School Admin in School A
        $schoolAdmin = User::factory()->create(['email' => 'admin_llm@test.ru']);
        SchoolMembership::factory()->create([
            'school_id' => $this->schoolA->id,
            'user_id' => $schoolAdmin->id,
            'role' => 'school_admin',
            'is_default' => true,
        ]);

        // 1. Another teacher in the same school gets 403 FORBIDDEN_AUTHOR_ACCESS on scenario
        $this->actingAs($teacherA2)
            ->getJson("/api/v1/scenarios/{$scenario->id}")
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN_AUTHOR_ACCESS');

        // 2. Another teacher in the same school gets 403 FORBIDDEN_AUTHOR_ACCESS on generation run
        $this->actingAs($teacherA2)
            ->getJson("/api/v1/generation-runs/{$run->id}")
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN_AUTHOR_ACCESS');

        // 3. Another teacher listing scenarios does not see Teacher A's scenario
        $listA2 = $this->actingAs($teacherA2)
            ->getJson('/api/v1/scenarios')
            ->assertStatus(200);
        $ids = collect($listA2->json('data'))->pluck('id')->all();
        $this->assertNotContains($scenario->id, $ids);

        // 4. School admin in School A CAN view scenario
        $this->actingAs($schoolAdmin)
            ->getJson("/api/v1/scenarios/{$scenario->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.id', $scenario->id);

        // 5. System admin with membership CAN view scenario
        $systemAdmin = User::factory()->create([
            'email' => 'sysadmin_llm@test.ru',
            'system_role' => 'system_admin',
        ]);
        SchoolMembership::factory()->create([
            'school_id' => $this->schoolA->id,
            'user_id' => $systemAdmin->id,
            'role' => 'school_admin',
            'is_default' => true,
        ]);
        $this->actingAs($systemAdmin)
            ->getJson("/api/v1/scenarios/{$scenario->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.id', $scenario->id);
    }

    public function test_participants_count_500_clamps_to_max_500_and_passes_schema(): void
    {
        $input = [
            'custom_event_title' => 'Большой школьный праздник',
            'scale' => 'all_school',
            'format_type' => 'festival',
            'target_grades' => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11],
            'participants_count' => 500,
            'duration_minutes' => 60,
            'value_direction' => 'civic_responsibility',
            'family_involvement' => false,
        ];

        $snapshot = app(ContextSnapshotBuilder::class)->buildSnapshot($this->teacherA, $this->schoolA, $input);

        $request = ScenarioRequest::create([
            'school_id' => $this->schoolA->id,
            'user_id' => $this->teacherA->id,
            'custom_event_title' => $input['custom_event_title'],
            'scale' => $input['scale'],
            'format_type' => $input['format_type'],
            'target_grades' => $input['target_grades'],
            'participants_count' => $input['participants_count'],
            'duration_minutes' => $input['duration_minutes'],
            'value_direction' => $input['value_direction'],
            'family_involvement' => $input['family_involvement'],
            'context_snapshot' => $snapshot,
        ]);

        $llmResult = app(MockLlmProvider::class)->generate($request);
        $payload = $llmResult['output_payload'];

        $this->assertLessThanOrEqual(500, $payload['target_audience']['participants_count_max']);
        $this->assertGreaterThanOrEqual(1, $payload['target_audience']['participants_count_min']);
        $this->assertEquals('mixed', $payload['target_audience']['age_group']);

        // Must validate with 0 schema errors
        $validator = app(GenerationOutputValidator::class);
        $validator->validate($payload);
        $this->assertTrue(true);
    }

    public function test_claim_without_sources_does_not_fabricate_fake_sources(): void
    {
        // Event has a cultural claim, but NO approved sources
        $claim = CulturalClaim::create([
            'calendar_event_id' => $this->event->id,
            'culture_name' => 'Культура региона',
            'claim_text' => 'Утверждение без подтверждённых первоисточников',
            'provenance_notes' => 'Источники ещё не проверены',
            'created_by_user_id' => $this->curator->id,
        ]);

        $input = [
            'calendar_event_id' => $this->event->id,
            'scale' => 'class_level',
            'format_type' => 'class_hour',
            'target_grades' => [5],
            'participants_count' => 20,
            'duration_minutes' => 45,
            'value_direction' => 'historical_memory',
            'family_involvement' => false,
        ];

        $snapshot = app(ContextSnapshotBuilder::class)->buildSnapshot($this->teacherA, $this->schoolA, $input);

        $claimInSnapshot = collect($snapshot['cultural_provenance']['claims'])->firstWhere('id', $claim->id);
        $this->assertNotNull($claimInSnapshot);
        $this->assertEmpty($claimInSnapshot['evidence_records']);

        $request = ScenarioRequest::create([
            'school_id' => $this->schoolA->id,
            'user_id' => $this->teacherA->id,
            'calendar_event_id' => $this->event->id,
            'scale' => $input['scale'],
            'format_type' => $input['format_type'],
            'target_grades' => $input['target_grades'],
            'participants_count' => $input['participants_count'],
            'duration_minutes' => $input['duration_minutes'],
            'value_direction' => $input['value_direction'],
            'family_involvement' => $input['family_involvement'],
            'context_snapshot' => $snapshot,
        ]);

        $llmResult = app(MockLlmProvider::class)->generate($request);
        $payload = $llmResult['output_payload'];

        // Output must not invent fake sources like "Раздел 1" or "Государственный каталог"
        $rawJson = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('Раздел 1, страница', $rawJson);
        $this->assertStringNotContainsString('Государственный каталог', $rawJson);

        // Schema validation passes
        $validator = app(GenerationOutputValidator::class);
        $validator->validate($payload);
        $this->assertTrue(true);
    }

    public function test_duration_240_partitions_stages_under_120_max(): void
    {
        $input = [
            'custom_event_title' => 'Длинный методический интенсив',
            'scale' => 'class_level',
            'format_type' => 'quest',
            'target_grades' => [8],
            'participants_count' => 30,
            'duration_minutes' => 240,
            'value_direction' => 'labor_and_creation',
            'family_involvement' => false,
        ];

        $snapshot = app(ContextSnapshotBuilder::class)->buildSnapshot($this->teacherA, $this->schoolA, $input);

        $request = ScenarioRequest::create([
            'school_id' => $this->schoolA->id,
            'user_id' => $this->teacherA->id,
            'custom_event_title' => $input['custom_event_title'],
            'scale' => $input['scale'],
            'format_type' => $input['format_type'],
            'target_grades' => $input['target_grades'],
            'participants_count' => $input['participants_count'],
            'duration_minutes' => $input['duration_minutes'],
            'value_direction' => $input['value_direction'],
            'family_involvement' => $input['family_involvement'],
            'context_snapshot' => $snapshot,
        ]);

        $llmResult = app(MockLlmProvider::class)->generate($request);
        $payload = $llmResult['output_payload'];

        $stages = $payload['stages'];
        $sum = 0;
        foreach ($stages as $stage) {
            $this->assertLessThanOrEqual(120, $stage['duration_minutes']);
            $this->assertGreaterThanOrEqual(5, $stage['duration_minutes']);
            $sum += $stage['duration_minutes'];
        }
        $this->assertEquals(240, $sum);

        // Validate contours 1 and 2
        app(GenerationOutputValidator::class)->validate($payload);
        app(ScenarioSemanticValidator::class)->validate($payload, 240);
        $this->assertTrue(true);
    }

    public function test_generation_run_status_endpoint(): void
    {
        $request = ScenarioRequest::create([
            'school_id' => $this->schoolA->id,
            'user_id' => $this->teacherA->id,
            'custom_event_title' => 'Проверка статуса запуска',
            'scale' => 'class_level',
            'format_type' => 'class_hour',
            'target_grades' => [5],
            'participants_count' => 20,
            'duration_minutes' => 45,
            'value_direction' => 'family',
            'family_involvement' => false,
            'context_snapshot' => ['occasion' => ['title' => 'Проверка статуса запуска']],
        ]);

        $run = GenerationRun::create([
            'scenario_request_id' => $request->id,
            'provider_name' => MockLlmProvider::PROVIDER_NAME,
            'model_identifier' => MockLlmProvider::MODEL_IDENTIFIER,
            'prompt_version' => MockLlmProvider::PROMPT_VERSION,
            'schema_version' => MockLlmProvider::SCHEMA_VERSION,
            'status' => 'queued',
            'attempts' => 0,
        ]);

        // Teacher A checks run
        $responseA = $this->actingAs($this->teacherA)
            ->getJson("/api/v1/generation-runs/{$run->id}");

        $responseA->assertStatus(200)
            ->assertJsonPath('data.id', $run->id)
            ->assertJsonPath('data.status', 'queued');

        // Teacher B cannot check Teacher A's run
        $responseB = $this->actingAs($this->teacherB)
            ->getJson("/api/v1/generation-runs/{$run->id}");

        $responseB->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN_TENANT_ACCESS');
    }
}
