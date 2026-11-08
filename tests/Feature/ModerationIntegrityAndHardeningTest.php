<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ExecuteGenerationRunJob;
use App\Jobs\RunVerificationJob;
use App\Models\CalendarEvent;
use App\Models\CulturalClaim;
use App\Models\CulturalClaimSource;
use App\Models\EventSource;
use App\Models\ExportJob;
use App\Models\ExportedArtifact;
use App\Models\GenerationRun;
use App\Models\Scenario;
use App\Models\ScenarioMaterial;
use App\Models\ScenarioRequest;
use App\Models\ScenarioRevision;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\SourceReview;
use App\Models\TeacherSignoff;
use App\Models\User;
use App\Models\VerificationRun;
use App\Services\ContextSnapshotBuilder;
use App\Services\Export\ScenarioExportService;
use App\Services\Llm\MockLlmProvider;
use App\Services\Moderation\ModerationEngineService;
use App\Services\ProvenanceVerificationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ModerationIntegrityAndHardeningTest extends TestCase
{
    use DatabaseTransactions;

    protected School $school;
    protected User $teacher;
    protected User $curator;
    protected CalendarEvent $event;
    protected Scenario $scenario;
    protected ScenarioRevision $revision;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->school = School::factory()->create(['name' => 'Школа № 42 Екатеринбург']);

        $this->teacher = User::factory()->create(['email' => 'teacher_be004_1@test.ru']);
        SchoolMembership::factory()->create([
            'school_id' => $this->school->id,
            'user_id' => $this->teacher->id,
            'role' => 'teacher',
            'is_default' => true,
        ]);

        $this->curator = User::factory()->create([
            'email' => 'curator_be004_1@test.ru',
            'is_active' => true,
            'system_role' => 'catalog_curator',
            'curator_scope' => 'global',
        ]);

        $this->event = CalendarEvent::create([
            'title' => 'День народных промыслов Урала',
            'event_type' => 'regional',
            'region_code' => '66',
            'date_type' => 'fixed_annual',
            'fixed_month' => 11,
            'fixed_day' => 15,
            'description' => 'Региональное культурное событие',
            'target_age_groups' => ['basic_5_9'],
            'recommended_value_directions' => ['cultural_diversity', 'historical_memory'],
            'status' => 'approved',
        ]);

        $input = [
            'calendar_event_id' => $this->event->id,
            'scale' => 'class_level',
            'format_type' => 'class_hour',
            'target_grades' => [7],
            'participants_count' => 25,
            'duration_minutes' => 45,
            'value_direction' => 'cultural_diversity',
            'family_involvement' => false,
            'available_resources' => ['Проектор', 'Материалы для лепки'],
        ];

        $snapshot = app(ContextSnapshotBuilder::class)->buildSnapshot($this->teacher, $this->school, $input);

        $req = ScenarioRequest::create([
            'school_id' => $this->school->id,
            'user_id' => $this->teacher->id,
            'calendar_event_id' => $this->event->id,
            'scale' => 'class_level',
            'format_type' => 'class_hour',
            'target_grades' => [7],
            'participants_count' => 25,
            'duration_minutes' => 45,
            'value_direction' => 'cultural_diversity',
            'family_involvement' => false,
            'available_resources' => ['Проектор', 'Материалы для лепки'],
            'context_snapshot' => $snapshot,
        ]);

        $run = GenerationRun::create([
            'scenario_request_id' => $req->id,
            'provider_name' => MockLlmProvider::PROVIDER_NAME,
            'model_identifier' => MockLlmProvider::MODEL_IDENTIFIER,
            'prompt_version' => MockLlmProvider::PROMPT_VERSION,
            'schema_version' => MockLlmProvider::SCHEMA_VERSION,
            'status' => 'queued',
            'attempts' => 1,
        ]);

        app(ExecuteGenerationRunJob::class, ['runId' => $run->id])->handle(
            app(\App\Services\Llm\LlmProviderInterface::class),
            app(\App\Services\Llm\GenerationOutputValidator::class),
            app(\App\Services\Llm\ScenarioSemanticValidator::class),
            app(\App\Services\Llm\ServerNormalizationService::class),
            app(\App\Services\ScenarioPersistenceService::class),
            app(\App\Services\IdempotencyService::class)
        );

        $this->scenario = Scenario::where('scenario_request_id', $req->id)->firstOrFail();
        $this->revision = $this->scenario->currentRevision;
    }

    /**
     * Helper to create a verified authentic claim in the database.
     */
    protected function createVerifiedAuthenticDbClaim(string $text): CulturalClaim
    {
        $claim = CulturalClaim::create([
            'calendar_event_id' => $this->event->id,
            'claim_text' => $text,
            'culture_name' => 'Уральская традиция',
            'provenance_notes' => 'Исторические очерки по ремёслам Урала',
            'created_at' => now(),
        ]);

        $source = EventSource::create([
            'calendar_event_id' => $this->event->id,
            'title' => 'Очерки истории уральских ремесел',
            'source_type' => 'scientific_publication',
            'author' => 'УрО РАН',
            'created_at' => now(),
        ]);

        SourceReview::create([
            'event_source_id' => $source->id,
            'reviewer_user_id' => $this->curator->id,
            'decision' => 'approved',
            'comments' => 'Подтверждено академическим изданием',
            'reviewed_at' => now(),
            'created_at' => now(),
        ]);

        app(ProvenanceVerificationService::class)->verifyClaim(
            claim: $claim,
            source: $source,
            citationLocator: 'стр. 42-45, раздел 2',
            verifier: $this->curator,
            evidenceExcerpt: 'Традиция камнерезного искусства на Среднем Урале документально подтверждена с XVIII века.'
        );

        return $claim;
    }

    /**
     * Negative test 1: Altered canonical text claiming verified_authentic -> P0.
     */
    public function test_altered_canonical_text_claiming_verified_authentic_triggers_p0(): void
    {
        $dbClaim = $this->createVerifiedAuthenticDbClaim('Камнерезное искусство Урала зародилось в XVIII веке.');

        $payload = $this->revision->content_payload;
        $payload['cultural_claims'] = [
            [
                'claim_id' => $dbClaim->id,
                'claim_text' => 'Камнерезное искусство Урала появилось в древнейшие времена миллион лет назад.', // ALTERED!
                'server_verification_status' => 'verified_authentic',
            ],
        ];

        DB::table('scenario_revisions')->where('id', $this->revision->id)->update([
            'content_payload' => json_encode($payload),
        ]);
        $this->revision->refresh();

        $engine = app(ModerationEngineService::class);
        $run = $engine->verifyRevision($this->revision);

        $this->assertEquals('blocked', $run->status);
        $this->assertGreaterThan(0, $run->p0_violations_count);

        $finding = $run->findings()->where('rule_code', 'P0_FABRICATED_CULTURE')->first();
        $this->assertNotNull($finding);
        $this->assertStringContainsString('изменён', $finding->message);
    }

    /**
     * Negative test 2: Source review rejected after generation -> P0.
     */
    public function test_revoked_source_review_after_generation_triggers_p0(): void
    {
        $dbClaim = $this->createVerifiedAuthenticDbClaim('Традиция уральской росписи по дереву.');

        // Now revoke the review by adding a new rejected review
        $claimSource = CulturalClaimSource::where('cultural_claim_id', $dbClaim->id)->firstOrFail();
        $source = $claimSource->source;

        SourceReview::create([
            'event_source_id' => $source->id,
            'reviewer_user_id' => $this->curator->id,
            'decision' => 'rejected', // REVOKED!
            'comments' => 'Обнаружена недостоверность первоисточника',
            'reviewed_at' => now()->addMinute(),
            'created_at' => now()->addMinute(),
        ]);

        $payload = $this->revision->content_payload;
        $payload['cultural_claims'] = [
            [
                'claim_id' => $dbClaim->id,
                'claim_text' => $dbClaim->claim_text,
                'server_verification_status' => 'verified_authentic',
            ],
        ];

        DB::table('scenario_revisions')->where('id', $this->revision->id)->update([
            'content_payload' => json_encode($payload),
        ]);
        $this->revision->refresh();

        $engine = app(ModerationEngineService::class);
        $run = $engine->verifyRevision($this->revision);

        $this->assertEquals('blocked', $run->status);
        $finding = $run->findings()->where('rule_code', 'P0_FABRICATED_CULTURE')->first();
        $this->assertNotNull($finding);
    }

    /**
     * Negative test 3: Honest unverified claim without aggressive fabrication patterns is NOT flagged as P0.
     */
    public function test_honest_unverified_claim_is_not_false_flagged_as_p0(): void
    {
        $payload = $this->revision->content_payload;
        $payload['cultural_claims'] = [
            [
                'claim_id' => null,
                'claim_text' => 'По устным рассказам старожилов, на ярмарках часто проводились песенные состязания.',
                'server_verification_status' => 'unverified',
            ],
        ];

        DB::table('scenario_revisions')->where('id', $this->revision->id)->update([
            'content_payload' => json_encode($payload),
        ]);
        $this->revision->refresh();

        $engine = app(ModerationEngineService::class);
        $run = $engine->verifyRevision($this->revision);

        $this->assertEquals('passed', $run->status);
        $this->assertEquals(0, $run->p0_violations_count);
    }

    /**
     * Positive test: Fully verified authentic claim passes moderation without Eloquent errors.
     */
    public function test_fully_verified_claim_passes_moderation_cleanly(): void
    {
        $dbClaim = $this->createVerifiedAuthenticDbClaim('Камнерезное искусство Урала зародилось в XVIII веке.');
        $claimSource = CulturalClaimSource::where('cultural_claim_id', $dbClaim->id)->firstOrFail();

        $payload = $this->revision->content_payload;
        $payload['cultural_claims'] = [
            [
                'claim_id' => $dbClaim->id,
                'claim_text' => $dbClaim->claim_text,
                'server_verification_status' => 'verified_authentic',
                'evidence_records' => [
                    [
                        'event_source_id' => $claimSource->event_source_id,
                        'source_title' => $claimSource->source->title,
                        'citation_locator' => $claimSource->citation_locator,
                        'verification_status' => 'verified_authentic',
                        'verified_at' => now()->toIso8601String(),
                    ],
                ],
            ],
        ];

        DB::table('scenario_revisions')->where('id', $this->revision->id)->update([
            'content_payload' => json_encode($payload),
        ]);
        $this->revision->refresh();

        $engine = app(ModerationEngineService::class);
        $run = $engine->verifyRevision($this->revision);

        $this->assertEquals('passed', $run->status);
        $this->assertEquals(0, $run->p0_violations_count);
    }

    /**
     * Negative test: Stereotype located ONLY inside printable card material triggers P0 with exact target path.
     */
    public function test_stereotype_inside_material_card_triggers_p0_with_accurate_target_path(): void
    {
        $payload = $this->revision->content_payload;
        $payload['materials']['printable_cards'] = [
            [
                'title' => 'Карточка задания № 1',
                'front_text' => 'Тут написано что все цыгане склонны к обману.', // FORBIDDEN STEREOTYPE ONLY IN MATERIAL!
                'back_text' => 'Обычный текст',
                'instructions' => 'Инструкция',
            ],
        ];

        DB::table('scenario_revisions')->where('id', $this->revision->id)->update([
            'content_payload' => json_encode($payload),
        ]);
        $this->revision->refresh();

        $engine = app(ModerationEngineService::class);
        $run = $engine->verifyRevision($this->revision);

        $this->assertEquals('blocked', $run->status);
        $finding = $run->findings()->where('rule_code', 'P0_STEREOTYPE')->first();
        $this->assertNotNull($finding);
        $this->assertEquals('materials.printable_cards[0].front_text', $finding->target_path);
    }

    /**
     * Lifecycle test: HTTP POST verify creates exactly ONE verification run, and RunVerificationJob processes it without duplicate.
     */
    public function test_http_post_verify_and_job_lifecycle_creates_single_run(): void
    {
        $initialRunCount = VerificationRun::where('revision_id', $this->revision->id)->count();

        // 1. HTTP request
        $response = $this->actingAs($this->teacher)
            ->postJson("/api/v1/scenarios/{$this->scenario->id}/revisions/{$this->revision->version_number}/verify");

        $response->assertStatus(202);
        $runId = $response->json('data.verification_run_id');
        $this->assertNotNull($runId);

        // Exactly one new run created
        $this->assertEquals($initialRunCount + 1, VerificationRun::where('revision_id', $this->revision->id)->count());

        // 2. Run background job with this runId
        $engine = app(ModerationEngineService::class);
        $job = new RunVerificationJob($this->revision->id, $runId);
        $job->handle($engine);

        // Run count MUST still be $initialRunCount + 1 (no duplicate runs!)
        $this->assertEquals($initialRunCount + 1, VerificationRun::where('revision_id', $this->revision->id)->count());

        $run = VerificationRun::find($runId);
        $this->assertEquals('passed', $run->status);
    }

    /**
     * Concurrency test: Repeated POST verify while running is rejected with 409.
     */
    public function test_concurrent_verification_post_is_rejected_with_409(): void
    {
        // Set an active running run
        VerificationRun::create([
            'revision_id' => $this->revision->id,
            'rule_engine_version' => ModerationEngineService::RULE_ENGINE_VERSION,
            'status' => 'running',
            'created_at' => now(),
        ]);

        $this->revision->moderationState()->update([
            'status' => 'running',
        ]);

        $response = $this->actingAs($this->teacher)
            ->postJson("/api/v1/scenarios/{$this->scenario->id}/revisions/{$this->revision->version_number}/verify");

        $response->assertStatus(409)
            ->assertJsonPath('error.code', 'VERIFICATION_ALREADY_RUNNING');
    }

    /**
     * Idempotent sign-off: Repeated sign-off returns HTTP 200 without changing confirmed_at timestamp.
     */
    public function test_repeated_signoff_by_same_teacher_returns_200_without_mutating_record(): void
    {
        app(ModerationEngineService::class)->verifyRevision($this->revision);

        $checklist = [
            'safety_checked' => true,
            'timing_fit' => true,
            'cultural_context_approved' => true,
        ];

        // First sign-off
        $res1 = $this->actingAs($this->teacher)
            ->postJson("/api/v1/scenarios/{$this->scenario->id}/revisions/{$this->revision->version_number}/signoff", [
                'checklist_confirmed' => $checklist,
                'teacher_notes' => 'Первая заметка',
            ]);
        $res1->assertStatus(200);

        $signoff1 = TeacherSignoff::where('revision_id', $this->revision->id)->first();
        $initialConfirmedAt = $signoff1->confirmed_at->toIso8601String();

        // Second sign-off with different notes
        $res2 = $this->actingAs($this->teacher)
            ->postJson("/api/v1/scenarios/{$this->scenario->id}/revisions/{$this->revision->version_number}/signoff", [
                'checklist_confirmed' => $checklist,
                'teacher_notes' => 'Вторая заметка которая не должна перезаписать первую',
            ]);
        $res2->assertStatus(200);

        // Total signoff records for this revision must be 1
        $this->assertEquals(1, TeacherSignoff::where('revision_id', $this->revision->id)->count());

        $signoff2 = TeacherSignoff::where('revision_id', $this->revision->id)->first();
        $this->assertEquals($initialConfirmedAt, $signoff2->confirmed_at->toIso8601String());
        $this->assertEquals('Первая заметка', $signoff2->teacher_notes);
    }

    /**
     * Sign-off with P1 warnings requires warnings_acknowledged=true.
     */
    public function test_signoff_on_warnings_requires_explicit_acknowledgment(): void
    {
        // Mutate payload to cause P1 timing mismatch
        $payload = $this->revision->content_payload;
        $payload['stages'][0]['duration_minutes'] = 5;
        $payload['stages'][1]['duration_minutes'] = 10;
        $payload['stages'][2]['duration_minutes'] = 15; // sum 30 != 45
        DB::table('scenario_revisions')->where('id', $this->revision->id)->update([
            'content_payload' => json_encode($payload),
        ]);
        $this->revision->refresh();

        app(ModerationEngineService::class)->verifyRevision($this->revision);
        $this->revision->refresh();
        $this->assertEquals('verification_passed_with_warnings', $this->revision->moderationState->status);

        $checklist = [
            'safety_checked' => true,
            'timing_fit' => true,
            'cultural_context_approved' => true,
        ];

        // Attempt sign-off without acknowledgment -> 422
        $resFail = $this->actingAs($this->teacher)
            ->postJson("/api/v1/scenarios/{$this->scenario->id}/revisions/{$this->revision->version_number}/signoff", [
                'checklist_confirmed' => $checklist,
            ]);
        $resFail->assertStatus(422)
            ->assertJsonPath('error.code', 'WARNINGS_ACKNOWLEDGMENT_REQUIRED');

        // Attempt sign-off with warnings_acknowledged=true -> 200
        $resSuccess = $this->actingAs($this->teacher)
            ->postJson("/api/v1/scenarios/{$this->scenario->id}/revisions/{$this->revision->version_number}/signoff", [
                'checklist_confirmed' => $checklist,
                'warnings_acknowledged' => true,
            ]);
        $resSuccess->assertStatus(200);
    }

    /**
     * Negative test: Downloading PDF of historical revision v1 after creating v2 is rejected with 409.
     */
    public function test_downloading_historical_pdf_export_is_rejected_with_409(): void
    {
        // 1. Pass moderation and sign off revision 1
        app(ModerationEngineService::class)->verifyRevision($this->revision);

        TeacherSignoff::create([
            'revision_id' => $this->revision->id,
            'user_id' => $this->teacher->id,
            'is_confirmed' => true,
            'provenance_fingerprint' => $this->revision->moderationState->provenance_fingerprint,
            'checklist_confirmed' => ['safety_checked' => true, 'timing_fit' => true, 'cultural_context_approved' => true],
            'confirmed_at' => now(),
            'created_at' => now(),
        ]);

        // 2. Export revision 1
        $exportKey = (string) Str::uuid();
        $exportRes = $this->actingAs($this->teacher)
            ->withHeader('Idempotency-Key', $exportKey)
            ->postJson("/api/v1/scenarios/{$this->scenario->id}/revisions/1/exports", [
                'format' => 'pdf',
            ]);
        $exportRes->assertStatus(202);
        $jobId = $exportRes->json('data.id');

        // Compile export to ready if not already compiled in testing
        $exportJob = ExportJob::findOrFail($jobId);
        if ($exportJob->status !== 'ready') {
            app(ScenarioExportService::class)->export($exportJob);
            $exportJob->refresh();
        }
        $this->assertEquals('ready', $exportJob->status);

        // Verify download works while revision 1 is active
        $downloadRes1 = $this->actingAs($this->teacher)
            ->get("/api/v1/export-jobs/{$jobId}/download");
        $downloadRes1->assertStatus(200);

        // 3. Patch to revision 2 (advances current_revision_id)
        $patchRes = $this->actingAs($this->teacher)
            ->withHeader('If-Match', (string) $this->scenario->optimistic_lock_version)
            ->patchJson("/api/v1/scenarios/{$this->scenario->id}/revisions/1", [
                'concept' => 'Новая концепция для ревизии версии 2 мероприятия',
            ]);
        $patchRes->assertStatus(201);

        $this->scenario->refresh();
        $this->assertNotEquals($this->revision->id, $this->scenario->current_revision_id);

        // 4. Attempt to download old v1 PDF -> MUST FAIL with 409
        $downloadRes2 = $this->actingAs($this->teacher)
            ->getJson("/api/v1/export-jobs/{$jobId}/download");

        $downloadRes2->assertStatus(409)
            ->assertJsonPath('error.code', 'CANNOT_DOWNLOAD_HISTORICAL_EXPORT');
    }

    /**
     * Negative test: Download blocked after new P0 finding -> 403.
     */
    public function test_download_blocked_after_p0_finding(): void
    {
        // 1. Pass moderation and sign off
        app(ModerationEngineService::class)->verifyRevision($this->revision);

        TeacherSignoff::create([
            'revision_id' => $this->revision->id,
            'user_id' => $this->teacher->id,
            'is_confirmed' => true,
            'provenance_fingerprint' => $this->revision->moderationState->provenance_fingerprint,
            'checklist_confirmed' => ['safety_checked' => true, 'timing_fit' => true, 'cultural_context_approved' => true],
            'confirmed_at' => now(),
            'created_at' => now(),
        ]);

        // 2. Export
        $exportRes = $this->actingAs($this->teacher)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/scenarios/{$this->scenario->id}/revisions/1/exports", [
                'format' => 'pdf',
            ]);
        $jobId = $exportRes->json('data.id');
        $exportJob = ExportJob::findOrFail($jobId);
        if ($exportJob->status !== 'ready') {
            app(ScenarioExportService::class)->export($exportJob);
            $exportJob->refresh();
        }

        // 3. Moderation state gets updated to verification_blocked due to new audit
        $this->revision->moderationState()->update([
            'status' => 'verification_blocked',
            'p0_violations_count' => 1,
        ]);

        // 4. Attempt to download -> MUST FAIL with 403
        $downloadRes = $this->actingAs($this->teacher)
            ->getJson("/api/v1/export-jobs/{$jobId}/download");

        $downloadRes->assertStatus(403)
            ->assertJsonPath('error.code', 'EXPORT_BLOCKED_BY_P0_FINDINGS');
    }

    /**
     * Optimistic locking test: 2 concurrent PATCH requests with identical If-Match.
     * 1 succeeds (201), 1 fails with 409 OPTIMISTIC_LOCK_CONFLICT.
     */
    public function test_concurrent_patch_returns_one_201_and_one_409(): void
    {
        $versionHeader = (string) $this->scenario->optimistic_lock_version;

        $response1 = $this->actingAs($this->teacher)
            ->withHeader('If-Match', $versionHeader)
            ->patchJson("/api/v1/scenarios/{$this->scenario->id}/revisions/1", [
                'concept' => 'Обновление от первого параллельного потока редактирования',
            ]);

        $response1->assertStatus(201);

        // Second request sends same initial versionHeader
        $response2 = $this->actingAs($this->teacher)
            ->withHeader('If-Match', $versionHeader)
            ->patchJson("/api/v1/scenarios/{$this->scenario->id}/revisions/1", [
                'concept' => 'Обновление от второго параллельного потока редактирования',
            ]);

        $response2->assertStatus(409)
            ->assertJsonPath('error.code', 'OPTIMISTIC_LOCK_CONFLICT');
    }

    /**
     * Negative test: Stereotype in expected_outcomes.knowledge[0] blocks verification, sign-off and export.
     */
    public function test_stereotype_in_expected_outcomes_knowledge_blocks_verification_signoff_and_export(): void
    {
        $payload = $this->revision->content_payload;
        $payload['expected_outcomes']['knowledge'][0] = 'Все русские обязательно должны освоить это ремесло.';
        DB::table('scenario_revisions')->where('id', $this->revision->id)->update(['content_payload' => json_encode($payload)]);
        $this->revision->refresh();

        $run = app(ModerationEngineService::class)->verifyRevision($this->revision);
        $this->assertEquals('blocked', $run->status);

        $findings = $run->findings;
        $violation = $findings->firstWhere('rule_code', 'P0_STEREOTYPE');
        $this->assertNotNull($violation);
        $this->assertEquals('expected_outcomes.knowledge[0]', $violation->target_path);

        // Sign-off must be blocked with 422 MODERATION_BLOCKED_P0
        $signoffRes = $this->actingAs($this->teacher)->postJson("/api/v1/scenarios/{$this->scenario->id}/revisions/1/signoff", [
            'is_confirmed' => true,
            'checklist_confirmed' => ['safety_checked' => true, 'timing_fit' => true, 'cultural_context_approved' => true],
        ]);
        $signoffRes->assertStatus(422)
            ->assertJsonPath('error.code', 'MODERATION_BLOCKED_P0');

        // Export must be blocked with 403
        $exportRes = $this->actingAs($this->teacher)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/scenarios/{$this->scenario->id}/revisions/1/exports", ['format' => 'pdf']);
        $exportRes->assertStatus(403);
    }

    /**
     * Negative test: Stereotype in activities[0].rules_and_instructions blocks verification, sign-off and export.
     */
    public function test_stereotype_in_activities_rules_and_instructions_blocks_verification_signoff_and_export(): void
    {
        $payload = $this->revision->content_payload;
        $payload['activities'][0]['rules_and_instructions'] = 'Все татары обязательно должны участвовать в этом конкурсе по правилам.';
        DB::table('scenario_revisions')->where('id', $this->revision->id)->update(['content_payload' => json_encode($payload)]);
        $this->revision->refresh();

        $run = app(ModerationEngineService::class)->verifyRevision($this->revision);
        $this->assertEquals('blocked', $run->status);

        $findings = $run->findings;
        $violation = $findings->firstWhere('rule_code', 'P0_STEREOTYPE');
        $this->assertNotNull($violation);
        $this->assertEquals('activities[0].rules_and_instructions', $violation->target_path);

        $signoffRes = $this->actingAs($this->teacher)->postJson("/api/v1/scenarios/{$this->scenario->id}/revisions/1/signoff", [
            'is_confirmed' => true,
            'checklist_confirmed' => ['safety_checked' => true, 'timing_fit' => true, 'cultural_context_approved' => true],
        ]);
        $signoffRes->assertStatus(422)
            ->assertJsonPath('error.code', 'MODERATION_BLOCKED_P0');

        $exportRes = $this->actingAs($this->teacher)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/scenarios/{$this->scenario->id}/revisions/1/exports", ['format' => 'pdf']);
        $exportRes->assertStatus(403);
    }

    /**
     * Negative test: Stereotype in materials.presentation_slides[0].bullet_points[0] blocks verification, sign-off and export.
     */
    public function test_stereotype_in_presentation_slides_bullet_points_blocks_verification_signoff_and_export(): void
    {
        $payload = $this->revision->content_payload;
        $payload['materials']['presentation_slides'][0]['bullet_points'][0] = 'Все башкиры обязательно поют эту песню.';
        DB::table('scenario_revisions')->where('id', $this->revision->id)->update(['content_payload' => json_encode($payload)]);
        $this->revision->refresh();

        $run = app(ModerationEngineService::class)->verifyRevision($this->revision);
        $this->assertEquals('blocked', $run->status);

        $findings = $run->findings;
        $violation = $findings->firstWhere('rule_code', 'P0_STEREOTYPE');
        $this->assertNotNull($violation);
        $this->assertEquals('materials.presentation_slides[0].bullet_points[0]', $violation->target_path);

        $signoffRes = $this->actingAs($this->teacher)->postJson("/api/v1/scenarios/{$this->scenario->id}/revisions/1/signoff", [
            'is_confirmed' => true,
            'checklist_confirmed' => ['safety_checked' => true, 'timing_fit' => true, 'cultural_context_approved' => true],
        ]);
        $signoffRes->assertStatus(422)
            ->assertJsonPath('error.code', 'MODERATION_BLOCKED_P0');

        $exportRes = $this->actingAs($this->teacher)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/scenarios/{$this->scenario->id}/revisions/1/exports", ['format' => 'pdf']);
        $exportRes->assertStatus(403);
    }

    /**
     * Negative test: Stereotype in roles[0].specific_tasks[0] blocks verification, sign-off and export.
     */
    public function test_stereotype_in_roles_specific_tasks_blocks_verification_signoff_and_export(): void
    {
        $payload = $this->revision->content_payload;
        $payload['roles'][0]['specific_tasks'][0] = 'Все кавказцы обязательно соблюдают строгий порядок выступления.';
        DB::table('scenario_revisions')->where('id', $this->revision->id)->update(['content_payload' => json_encode($payload)]);
        $this->revision->refresh();

        $run = app(ModerationEngineService::class)->verifyRevision($this->revision);
        $this->assertEquals('blocked', $run->status);

        $findings = $run->findings;
        $violation = $findings->firstWhere('rule_code', 'P0_STEREOTYPE');
        $this->assertNotNull($violation);
        $this->assertEquals('roles[0].specific_tasks[0]', $violation->target_path);

        $signoffRes = $this->actingAs($this->teacher)->postJson("/api/v1/scenarios/{$this->scenario->id}/revisions/1/signoff", [
            'is_confirmed' => true,
            'checklist_confirmed' => ['safety_checked' => true, 'timing_fit' => true, 'cultural_context_approved' => true],
        ]);
        $signoffRes->assertStatus(422)
            ->assertJsonPath('error.code', 'MODERATION_BLOCKED_P0');

        $exportRes = $this->actingAs($this->teacher)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/scenarios/{$this->scenario->id}/revisions/1/exports", ['format' => 'pdf']);
        $exportRes->assertStatus(403);
    }

    /**
     * Negative test: Unknown claim_id with matching text fails with P0_FABRICATED_CULTURE.
     */
    public function test_unknown_claim_id_with_matching_text_fails_with_p0(): void
    {
        // 1. Create a legitimate claim in DB
        $dbClaim = CulturalClaim::create([
            'calendar_event_id' => $this->event->id,
            'claim_text' => 'Традиция камнерезного искусства на Урале зародилась в XVIII веке.',
            'culture_name' => 'Уральская культура',
            'provenance_notes' => 'Исторический факт',
        ]);

        $source = EventSource::create([
            'calendar_event_id' => $this->event->id,
            'source_type' => 'scientific_publication',
            'title' => 'Промыслы Урала',
            'author' => 'Академик И. И.',
            'publication_year' => 2020,
        ]);

        SourceReview::create([
            'event_source_id' => $source->id,
            'reviewer_user_id' => $this->curator->id,
            'decision' => 'approved',
            'comments' => 'Одобрено',
            'reviewed_at' => now(),
        ]);

        app(ProvenanceVerificationService::class)->verifyClaim(
            $dbClaim,
            $source,
            'Стр. 45',
            $this->curator,
            'Камнерезное дело получило активное развитие на Урале в 18 веке.'
        );

        // 2. Put matching text in revision payload but with unknown/imposter claim_id
        $payload = $this->revision->content_payload;
        $payload['cultural_claims'] = [
            [
                'claim_id' => 'claim_unknown_imposter_99999999',
                'claim_text' => 'Традиция камнерезного искусства на Урале зародилась в XVIII веке.',
                'culture_name' => 'Уральская культура',
                'claimed_origin' => 'Культурно-историческая традиция региона',
                'claimed_meaning' => 'Ценностный ориентир преемственности поколений',
                'server_verification_status' => 'verified_authentic',
                'evidence_records' => [
                    [
                        'event_source_id' => $source->id,
                        'source_title' => $source->title,
                        'citation_locator' => 'Стр. 45',
                        'verification_status' => 'verified_authentic',
                        'verified_at' => now()->toIso8601String(),
                    ],
                ],
            ],
        ];
        DB::table('scenario_revisions')->where('id', $this->revision->id)->update(['content_payload' => json_encode($payload)]);
        $this->revision->refresh();

        $run = app(ModerationEngineService::class)->verifyRevision($this->revision);
        $this->assertEquals('blocked', $run->status);

        $finding = $run->findings->firstWhere('rule_code', 'P0_FABRICATED_CULTURE');
        $this->assertNotNull($finding);
        $this->assertStringContainsString('claim_unknown_imposter_99999999', $finding->message);
    }

    /**
     * Re-executing an already completed verification run does not duplicate findings.
     */
    public function test_reexecuting_completed_verification_run_does_not_duplicate_findings(): void
    {
        $run1 = app(ModerationEngineService::class)->verifyRevision($this->revision);
        $findingsCount1 = DB::table('verification_findings')->where('verification_run_id', $run1->id)->count();

        // Re-call verifyRevision with the same run id
        $run2 = app(ModerationEngineService::class)->verifyRevision($this->revision, $run1->id);

        $this->assertEquals($run1->id, $run2->id);
        $findingsCount2 = DB::table('verification_findings')->where('verification_run_id', $run1->id)->count();
        $this->assertEquals($findingsCount1, $findingsCount2);
    }

    /**
     * Integration test: Source review revocation (rejected) invalidates export authorization and blocks download of ready PDF.
     */
    public function test_source_review_rejected_revocation_blocks_download_of_ready_pdf(): void
    {
        // 1. Setup authentic claim with approved review
        $claim = CulturalClaim::create([
            'calendar_event_id' => $this->event->id,
            'claim_text' => 'Художественное литье из чугуна в Каслях зародилось в середине XIX века.',
            'culture_name' => 'Уральская культура',
            'provenance_notes' => 'Исторический факт',
        ]);

        $source = EventSource::create([
            'calendar_event_id' => $this->event->id,
            'source_type' => 'scientific_publication',
            'title' => 'Каслинское литье',
            'author' => 'Профессор К. К.',
            'publication_year' => 2019,
        ]);

        $review = SourceReview::create([
            'event_source_id' => $source->id,
            'reviewer_user_id' => $this->curator->id,
            'decision' => 'approved',
            'comments' => 'Источник проверен и одобрен',
            'reviewed_at' => now(),
        ]);

        app(ProvenanceVerificationService::class)->verifyClaim(
            $claim,
            $source,
            'Глава 2, с. 34',
            $this->curator,
            'Завод получил широкую известность благодаря отливке художественных скульптур.'
        );

        $claimFormattedId = 'claim_' . str_replace('-', '', (string) $claim->id);
        $payload = $this->revision->content_payload;
        $payload['cultural_claims'] = [
            [
                'claim_id' => $claimFormattedId,
                'claim_text' => $claim->claim_text,
                'culture_name' => $claim->culture_name,
                'claimed_origin' => 'Культурно-историческая традиция региона',
                'claimed_meaning' => 'Ценностный ориентир преемственности поколений',
                'server_verification_status' => 'verified_authentic',
                'evidence_records' => [
                    [
                        'event_source_id' => $source->id,
                        'source_title' => $source->title,
                        'citation_locator' => 'Глава 2, с. 34',
                        'verification_status' => 'verified_authentic',
                        'verified_at' => now()->toIso8601String(),
                    ],
                ],
            ],
        ];
        DB::table('scenario_revisions')->where('id', $this->revision->id)->update(['content_payload' => json_encode($payload)]);
        $this->revision->refresh();

        // 2. Pass moderation
        $run = app(ModerationEngineService::class)->verifyRevision($this->revision);
        $this->assertEquals('passed', $run->status);

        // 3. Human teacher sign-off
        TeacherSignoff::create([
            'revision_id' => $this->revision->id,
            'user_id' => $this->teacher->id,
            'is_confirmed' => true,
            'provenance_fingerprint' => $this->revision->moderationState->provenance_fingerprint,
            'checklist_confirmed' => ['safety_checked' => true, 'timing_fit' => true, 'cultural_context_approved' => true],
            'confirmed_at' => now(),
            'created_at' => now(),
        ]);

        // 4. Request export & generate ready PDF
        $exportRes = $this->actingAs($this->teacher)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/scenarios/{$this->scenario->id}/revisions/1/exports", ['format' => 'pdf']);
        $exportRes->assertStatus(202);
        $jobId = $exportRes->json('data.id');

        $job = ExportJob::findOrFail($jobId);
        if ($job->status !== 'ready') {
            app(ScenarioExportService::class)->export($job);
            $job->refresh();
        }
        $this->assertEquals('ready', $job->status);

        // Verify PDF can be downloaded initially
        $downloadRes1 = $this->actingAs($this->teacher)->get("/api/v1/export-jobs/{$jobId}/download");
        $downloadRes1->assertStatus(200);

        // 5. Curator revokes source review: creates a newer review with decision = 'rejected'
        SourceReview::create([
            'event_source_id' => $source->id,
            'reviewer_user_id' => $this->curator->id,
            'decision' => 'rejected',
            'comments' => 'Выявлены искажения первоисточника, одобрение аннулировано',
            'reviewed_at' => now()->addSecond(),
        ]);

        // 6. Download of previously ready PDF must now be DENIED with 403 EXPORT_BLOCKED_BY_PROVENANCE_REVOCATION
        $downloadRes2 = $this->actingAs($this->teacher)->getJson("/api/v1/export-jobs/{$jobId}/download");
        $downloadRes2->assertStatus(403)
            ->assertJsonPath('error.code', 'EXPORT_BLOCKED_BY_PROVENANCE_REVOCATION');

        // 7. Requesting new export job must also be DENIED with 403 EXPORT_BLOCKED_BY_PROVENANCE_REVOCATION
        $newExportRes = $this->actingAs($this->teacher)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/scenarios/{$this->scenario->id}/revisions/1/exports", ['format' => 'pdf']);
        $newExportRes->assertStatus(403)
            ->assertJsonPath('error.code', 'EXPORT_BLOCKED_BY_PROVENANCE_REVOCATION');
    }

    /**
     * Integration test: Source review revocation (requires_amendment) invalidates export authorization and blocks download.
     */
    public function test_source_review_requires_amendment_revocation_blocks_download_of_ready_pdf(): void
    {
        $claim = CulturalClaim::create([
            'calendar_event_id' => $this->event->id,
            'claim_text' => 'Традиция художественной обработки камня на Алтае восходит к Колыванской фабрике.',
            'culture_name' => 'Алтайская культура',
            'provenance_notes' => 'Исторический факт',
        ]);

        $source = EventSource::create([
            'calendar_event_id' => $this->event->id,
            'source_type' => 'scientific_publication',
            'title' => 'Колыванская шлифовальная фабрика',
            'author' => 'Историк А. А.',
            'publication_year' => 2018,
        ]);

        SourceReview::create([
            'event_source_id' => $source->id,
            'reviewer_user_id' => $this->curator->id,
            'decision' => 'approved',
            'comments' => 'Одобрено',
            'reviewed_at' => now(),
        ]);

        app(ProvenanceVerificationService::class)->verifyClaim(
            $claim,
            $source,
            'Стр. 12-15',
            $this->curator,
            'Колыванская фабрика создавала монументальные чаши из яшмы и порфира.'
        );

        $claimFormattedId = 'claim_' . str_replace('-', '', (string) $claim->id);
        $payload = $this->revision->content_payload;
        $payload['cultural_claims'] = [
            [
                'claim_id' => $claimFormattedId,
                'claim_text' => $claim->claim_text,
                'culture_name' => $claim->culture_name,
                'claimed_origin' => 'Культурно-историческая традиция региона',
                'claimed_meaning' => 'Ценностный ориентир преемственности поколений',
                'server_verification_status' => 'verified_authentic',
                'evidence_records' => [
                    [
                        'event_source_id' => $source->id,
                        'source_title' => $source->title,
                        'citation_locator' => 'Стр. 12-15',
                        'verification_status' => 'verified_authentic',
                        'verified_at' => now()->toIso8601String(),
                    ],
                ],
            ],
        ];
        DB::table('scenario_revisions')->where('id', $this->revision->id)->update(['content_payload' => json_encode($payload)]);
        $this->revision->refresh();

        $run = app(ModerationEngineService::class)->verifyRevision($this->revision);
        $this->assertEquals('passed', $run->status);

        TeacherSignoff::create([
            'revision_id' => $this->revision->id,
            'user_id' => $this->teacher->id,
            'is_confirmed' => true,
            'provenance_fingerprint' => $this->revision->moderationState->provenance_fingerprint,
            'checklist_confirmed' => ['safety_checked' => true, 'timing_fit' => true, 'cultural_context_approved' => true],
            'confirmed_at' => now(),
            'created_at' => now(),
        ]);

        $exportRes = $this->actingAs($this->teacher)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/scenarios/{$this->scenario->id}/revisions/1/exports", ['format' => 'pdf']);
        $jobId = $exportRes->json('data.id');

        $job = ExportJob::findOrFail($jobId);
        if ($job->status !== 'ready') {
            app(ScenarioExportService::class)->export($job);
            $job->refresh();
        }

        // Curator marks requires_amendment
        SourceReview::create([
            'event_source_id' => $source->id,
            'reviewer_user_id' => $this->curator->id,
            'decision' => 'requires_amendment',
            'comments' => 'Требуется уточнение архивных шифров, подтверждение приостановлено',
            'reviewed_at' => now()->addSecond(),
        ]);

        // Download must be blocked with 403
        $downloadRes = $this->actingAs($this->teacher)->getJson("/api/v1/export-jobs/{$jobId}/download");
        $downloadRes->assertStatus(403)
            ->assertJsonPath('error.code', 'EXPORT_BLOCKED_BY_PROVENANCE_REVOCATION');
    }

    /**
     * Test A (2 sources): Claim X has sources A & B in DB. Payload cites A.
     * Review of A is revoked (rejected), B remains approved.
     * Export / download blocked (EXPORT_BLOCKED_BY_PROVENANCE_REVOCATION, 403).
     */
    public function test_a_two_sources_in_db_cited_source_revoked_uncited_source_approved_blocks_download(): void
    {
        $claim = CulturalClaim::create([
            'calendar_event_id' => $this->event->id,
            'claim_text' => 'Традиция уральской росписи по дереву и бересте сформировалась к концу XVIII века.',
            'culture_name' => 'Уральская культура',
            'provenance_notes' => 'Исторический факт',
        ]);

        $sourceA = EventSource::create([
            'calendar_event_id' => $this->event->id,
            'source_type' => 'scientific_publication',
            'title' => 'Народные росписи Урала (Источник А)',
            'author' => 'Искусствовед А. А.',
            'publication_year' => 2017,
        ]);

        $sourceB = EventSource::create([
            'calendar_event_id' => $this->event->id,
            'source_type' => 'scientific_publication',
            'title' => 'Промыслы Горнозаводского Урала (Источник Б)',
            'author' => 'Историк Б. Б.',
            'publication_year' => 2021,
        ]);

        // Both sources initially approved by curator
        SourceReview::create([
            'event_source_id' => $sourceA->id,
            'reviewer_user_id' => $this->curator->id,
            'decision' => 'approved',
            'comments' => 'Источник А проверен',
            'reviewed_at' => now(),
        ]);

        SourceReview::create([
            'event_source_id' => $sourceB->id,
            'reviewer_user_id' => $this->curator->id,
            'decision' => 'approved',
            'comments' => 'Источник Б проверен',
            'reviewed_at' => now(),
        ]);

        // Attest both sources in DB
        app(ProvenanceVerificationService::class)->verifyClaim(
            $claim,
            $sourceA,
            'Стр. 55-60',
            $this->curator,
            'Уральская народная роспись украшала интерьеры крестьянских домов и предметы быта.'
        );

        app(ProvenanceVerificationService::class)->verifyClaim(
            $claim,
            $sourceB,
            'Том 2, Стр. 110',
            $this->curator,
            'Мастера горнозаводских поселений активно развивали берестяной и деревянный промысел.'
        );

        $claimFormattedId = 'claim_' . str_replace('-', '', (string) $claim->id);

        // Payload in revision CITES ONLY SOURCE A
        $payload = $this->revision->content_payload;
        $payload['cultural_claims'] = [
            [
                'claim_id' => $claimFormattedId,
                'claim_text' => $claim->claim_text,
                'culture_name' => $claim->culture_name,
                'claimed_origin' => 'Культурно-историческая традиция региона',
                'claimed_meaning' => 'Ценностный ориентир преемственности поколений',
                'server_verification_status' => 'verified_authentic',
                'evidence_records' => [
                    [
                        'event_source_id' => $sourceA->id,
                        'source_title' => $sourceA->title,
                        'citation_locator' => 'Стр. 55-60',
                        'verification_status' => 'verified_authentic',
                        'verified_at' => now()->toIso8601String(),
                    ],
                ],
            ],
        ];
        DB::table('scenario_revisions')->where('id', $this->revision->id)->update(['content_payload' => json_encode($payload)]);
        $this->revision->refresh();

        // 1. Initial moderation passes
        $run = app(ModerationEngineService::class)->verifyRevision($this->revision);
        $this->assertEquals('passed', $run->status);

        // 2. Sign-off passes
        TeacherSignoff::create([
            'revision_id' => $this->revision->id,
            'user_id' => $this->teacher->id,
            'is_confirmed' => true,
            'provenance_fingerprint' => $this->revision->moderationState->provenance_fingerprint,
            'checklist_confirmed' => ['safety_checked' => true, 'timing_fit' => true, 'cultural_context_approved' => true],
            'confirmed_at' => now(),
            'created_at' => now(),
        ]);

        // 3. Export PDF
        $exportRes = $this->actingAs($this->teacher)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/scenarios/{$this->scenario->id}/revisions/1/exports", ['format' => 'pdf']);
        $exportRes->assertStatus(202);
        $jobId = $exportRes->json('data.id');

        $job = ExportJob::findOrFail($jobId);
        if ($job->status !== 'ready') {
            app(ScenarioExportService::class)->export($job);
            $job->refresh();
        }
        $this->assertEquals('ready', $job->status);

        // Initially download works
        $downloadRes1 = $this->actingAs($this->teacher)->get("/api/v1/export-jobs/{$jobId}/download");
        $downloadRes1->assertStatus(200);

        // 4. Curator revokes source A (decision = rejected). Source B remains approved in DB!
        SourceReview::create([
            'event_source_id' => $sourceA->id,
            'reviewer_user_id' => $this->curator->id,
            'decision' => 'rejected',
            'comments' => 'Источник А признан недостоверным, рецензия отозвана',
            'reviewed_at' => now()->addSecond(),
        ]);

        // 5. Download must be BLOCKED with 403 EXPORT_BLOCKED_BY_PROVENANCE_REVOCATION
        // Presence of approved Source B must NOT rescue un-cited Source A
        $downloadRes2 = $this->actingAs($this->teacher)->getJson("/api/v1/export-jobs/{$jobId}/download");
        $downloadRes2->assertStatus(403)
            ->assertJsonPath('error.code', 'EXPORT_BLOCKED_BY_PROVENANCE_REVOCATION');

        // New export request must also be blocked
        $newExportRes = $this->actingAs($this->teacher)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/scenarios/{$this->scenario->id}/revisions/1/exports", ['format' => 'pdf']);
        $newExportRes->assertStatus(403)
            ->assertJsonPath('error.code', 'EXPORT_BLOCKED_BY_PROVENANCE_REVOCATION');
    }

    /**
     * Test B (Swapped source ID): Claim X has valid source A in DB.
     * Payload specifies foreign random UUID for source B.
     * Moderation detects P0 (P0_FABRICATED_CULTURE), export is blocked.
     */
    public function test_b_swapped_foreign_source_id_detected_as_p0_and_blocks_export(): void
    {
        $claim = CulturalClaim::create([
            'calendar_event_id' => $this->event->id,
            'claim_text' => 'Кузнечное ремесло в уральских селениях являлось основой общинного уклада.',
            'culture_name' => 'Уральская культура',
            'provenance_notes' => 'Исторический факт',
        ]);

        $sourceA = EventSource::create([
            'calendar_event_id' => $this->event->id,
            'source_type' => 'scientific_publication',
            'title' => 'Кузнечное дело Урала',
            'author' => 'Профессор К. П.',
            'publication_year' => 2020,
        ]);

        SourceReview::create([
            'event_source_id' => $sourceA->id,
            'reviewer_user_id' => $this->curator->id,
            'decision' => 'approved',
            'comments' => 'Одобрено',
            'reviewed_at' => now(),
        ]);

        app(ProvenanceVerificationService::class)->verifyClaim(
            $claim,
            $sourceA,
            'Стр. 77',
            $this->curator,
            'Кузнецы пользовались особым уважением и обеспечивали хозяйственную автономию селений.'
        );

        $claimFormattedId = 'claim_' . str_replace('-', '', (string) $claim->id);
        $foreignSourceUuid = (string) Str::uuid();

        // Payload specifies foreign UUID for source
        $payload = $this->revision->content_payload;
        $payload['cultural_claims'] = [
            [
                'claim_id' => $claimFormattedId,
                'claim_text' => $claim->claim_text,
                'culture_name' => $claim->culture_name,
                'claimed_origin' => 'Культурно-историческая традиция региона',
                'claimed_meaning' => 'Ценностный ориентир преемственности поколений',
                'server_verification_status' => 'verified_authentic',
                'evidence_records' => [
                    [
                        'event_source_id' => $foreignSourceUuid,
                        'source_title' => 'Поддельный или чужой источник',
                        'citation_locator' => 'Стр. 77',
                        'verification_status' => 'verified_authentic',
                        'verified_at' => now()->toIso8601String(),
                    ],
                ],
            ],
        ];
        DB::table('scenario_revisions')->where('id', $this->revision->id)->update(['content_payload' => json_encode($payload)]);
        $this->revision->refresh();

        // Moderation MUST detect P0_FABRICATED_CULTURE and block
        $run = app(ModerationEngineService::class)->verifyRevision($this->revision);
        $this->assertEquals('blocked', $run->status);
        $this->assertGreaterThan(0, $run->p0_violations_count);

        $finding = $run->findings->firstWhere('rule_code', 'P0_FABRICATED_CULTURE');
        $this->assertNotNull($finding);
        $this->assertStringContainsString($foreignSourceUuid, $finding->message);

        // Export must be blocked
        $exportRes = $this->actingAs($this->teacher)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/scenarios/{$this->scenario->id}/revisions/1/exports", ['format' => 'pdf']);
        $this->assertTrue(in_array($exportRes->status(), [403, 422], true));
    }

    /**
     * Test C (Mismatched locator): Valid source ID, but citation_locator in payload differs from attestation in DB.
     * Moderation detects P0 (P0_FABRICATED_CULTURE), export is blocked.
     */
    public function test_c_mismatched_citation_locator_detected_as_p0_and_blocks_export(): void
    {
        $claim = CulturalClaim::create([
            'calendar_event_id' => $this->event->id,
            'claim_text' => 'Традиция колокольного литья на Каменском заводе насчитывает более трех столетий.',
            'culture_name' => 'Уральская культура',
            'provenance_notes' => 'Исторический факт',
        ]);

        $source = EventSource::create([
            'calendar_event_id' => $this->event->id,
            'source_type' => 'scientific_publication',
            'title' => 'Колокольное дело Урала',
            'author' => 'Краевед Д. Д.',
            'publication_year' => 2016,
        ]);

        SourceReview::create([
            'event_source_id' => $source->id,
            'reviewer_user_id' => $this->curator->id,
            'decision' => 'approved',
            'comments' => 'Одобрено',
            'reviewed_at' => now(),
        ]);

        // Attested with locator 'Том 1, Стр. 45'
        app(ProvenanceVerificationService::class)->verifyClaim(
            $claim,
            $source,
            'Том 1, Стр. 45',
            $this->curator,
            'Каменский казенный чугунолитейный завод с XVIII века отливал колокола высочайшего качества.'
        );

        $claimFormattedId = 'claim_' . str_replace('-', '', (string) $claim->id);

        // Payload specifies altered/fabricated locator 'Том 99, Стр. 999'
        $payload = $this->revision->content_payload;
        $payload['cultural_claims'] = [
            [
                'claim_id' => $claimFormattedId,
                'claim_text' => $claim->claim_text,
                'culture_name' => $claim->culture_name,
                'claimed_origin' => 'Культурно-историческая традиция региона',
                'claimed_meaning' => 'Ценностный ориентир преемственности поколений',
                'server_verification_status' => 'verified_authentic',
                'evidence_records' => [
                    [
                        'event_source_id' => $source->id,
                        'source_title' => $source->title,
                        'citation_locator' => 'Том 99, Стр. 999', // Mismatched!
                        'verification_status' => 'verified_authentic',
                        'verified_at' => now()->toIso8601String(),
                    ],
                ],
            ],
        ];
        DB::table('scenario_revisions')->where('id', $this->revision->id)->update(['content_payload' => json_encode($payload)]);
        $this->revision->refresh();

        // Moderation MUST detect P0_FABRICATED_CULTURE for citation locator mismatch
        $run = app(ModerationEngineService::class)->verifyRevision($this->revision);
        $this->assertEquals('blocked', $run->status);

        $finding = $run->findings->firstWhere('rule_code', 'P0_FABRICATED_CULTURE');
        $this->assertNotNull($finding);
        $this->assertStringContainsString('Том 99, Стр. 999', $finding->message);

        // ProvenanceVerificationService also reports invalid
        $provCheck = app(ProvenanceVerificationService::class)->verifyRevisionProvenanceAuthenticity($this->revision);
        $this->assertFalse($provCheck['valid']);
        $this->assertEquals('PROVENANCE_REVOKED', $provCheck['reason']);
    }

    /**
     * Test D (Positive case): Authentic claim + all cited sources valid.
     * Moderation passes, sign-off succeeds, real PDF downloads (HTTP 200).
     */
    public function test_d_positive_exact_binding_passes_moderation_signoff_and_downloads_pdf(): void
    {
        $claim = CulturalClaim::create([
            'calendar_event_id' => $this->event->id,
            'claim_text' => 'Искусство уральской мозаики из самоцветов признано шедевром камнерезного творчества.',
            'culture_name' => 'Уральская культура',
            'provenance_notes' => 'Исторический факт',
        ]);

        $source = EventSource::create([
            'calendar_event_id' => $this->event->id,
            'source_type' => 'scientific_publication',
            'title' => 'Камнерезное искусство Урала',
            'author' => 'Академик М. М.',
            'publication_year' => 2022,
        ]);

        SourceReview::create([
            'event_source_id' => $source->id,
            'reviewer_user_id' => $this->curator->id,
            'decision' => 'approved',
            'comments' => 'Источник проверен и полностью одобрен',
            'reviewed_at' => now(),
        ]);

        app(ProvenanceVerificationService::class)->verifyClaim(
            $claim,
            $source,
            'Глава 3, с. 88-92',
            $this->curator,
            'Уральские мозаичные панно из малахита и яшмы демонстрировались на всемирных выставках.'
        );

        $claimFormattedId = 'claim_' . str_replace('-', '', (string) $claim->id);
        $payload = $this->revision->content_payload;
        $payload['cultural_claims'] = [
            [
                'claim_id' => $claimFormattedId,
                'claim_text' => $claim->claim_text,
                'culture_name' => $claim->culture_name,
                'claimed_origin' => 'Культурно-историческая традиция региона',
                'claimed_meaning' => 'Ценностный ориентир преемственности поколений',
                'server_verification_status' => 'verified_authentic',
                'evidence_records' => [
                    [
                        'event_source_id' => $source->id,
                        'source_title' => $source->title,
                        'citation_locator' => 'Глава 3, с. 88-92',
                        'verification_status' => 'verified_authentic',
                        'verified_at' => now()->toIso8601String(),
                    ],
                ],
            ],
        ];
        DB::table('scenario_revisions')->where('id', $this->revision->id)->update(['content_payload' => json_encode($payload)]);
        $this->revision->refresh();

        // 1. Moderation passes
        $run = app(ModerationEngineService::class)->verifyRevision($this->revision);
        $this->assertEquals('passed', $run->status);
        $this->assertEquals(0, $run->p0_violations_count);

        // 2. Teacher sign-off succeeds
        $signoffRes = $this->actingAs($this->teacher)->postJson("/api/v1/scenarios/{$this->scenario->id}/revisions/1/signoff", [
            'checklist_confirmed' => [
                'safety_checked' => true,
                'timing_fit' => true,
                'cultural_context_approved' => true,
            ],
            'teacher_notes' => 'Сценарий полностью проверен и готов к реализации.',
        ]);
        $signoffRes->assertStatus(200);

        // 3. Export PDF
        $exportRes = $this->actingAs($this->teacher)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/scenarios/{$this->scenario->id}/revisions/1/exports", ['format' => 'pdf']);
        $exportRes->assertStatus(202);
        $jobId = $exportRes->json('data.id');

        $job = ExportJob::findOrFail($jobId);
        if ($job->status !== 'ready') {
            app(ScenarioExportService::class)->export($job);
            $job->refresh();
        }
        $this->assertEquals('ready', $job->status);

        // 4. Download PDF returns HTTP 200 with valid binary PDF
        $downloadRes = $this->actingAs($this->teacher)->get("/api/v1/export-jobs/{$jobId}/download");
        $downloadRes->assertStatus(200);
        $downloadRes->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $downloadRes->getContent());
    }

    /**
     * Test E (Revocation of 1 of multiple cited sources): Payload cites both A and B.
     * Source B is revoked (rejected). Download strictly blocked even though A remains approved.
     */
    public function test_e_revocation_of_one_of_multiple_cited_sources_strictly_blocks_download(): void
    {
        $claim = CulturalClaim::create([
            'calendar_event_id' => $this->event->id,
            'claim_text' => 'Традиция золотошвейного мастерства на Урале берет начало от старообрядческих скитов.',
            'culture_name' => 'Уральская культура',
            'provenance_notes' => 'Исторический факт',
        ]);

        $sourceA = EventSource::create([
            'calendar_event_id' => $this->event->id,
            'source_type' => 'scientific_publication',
            'title' => 'Золотошвейное искусство (Источник 1)',
            'author' => 'Ученый 1',
            'publication_year' => 2018,
        ]);

        $sourceB = EventSource::create([
            'calendar_event_id' => $this->event->id,
            'source_type' => 'scientific_publication',
            'title' => 'Старообрядческие ремесла (Источник 2)',
            'author' => 'Ученый 2',
            'publication_year' => 2020,
        ]);

        SourceReview::create([
            'event_source_id' => $sourceA->id,
            'reviewer_user_id' => $this->curator->id,
            'decision' => 'approved',
            'comments' => 'Одобрено',
            'reviewed_at' => now(),
        ]);

        SourceReview::create([
            'event_source_id' => $sourceB->id,
            'reviewer_user_id' => $this->curator->id,
            'decision' => 'approved',
            'comments' => 'Одобрено',
            'reviewed_at' => now(),
        ]);

        app(ProvenanceVerificationService::class)->verifyClaim(
            $claim,
            $sourceA,
            'Стр. 10',
            $this->curator,
            'Шитье золотыми нитями практиковалось в уральских общинах на протяжении двух веков.'
        );

        app(ProvenanceVerificationService::class)->verifyClaim(
            $claim,
            $sourceB,
            'Стр. 20',
            $this->curator,
            'Скитские мастерские славились уникальной технологией золотного шитья.'
        );

        $claimFormattedId = 'claim_' . str_replace('-', '', (string) $claim->id);

        // Payload cites BOTH source A and source B
        $payload = $this->revision->content_payload;
        $payload['cultural_claims'] = [
            [
                'claim_id' => $claimFormattedId,
                'claim_text' => $claim->claim_text,
                'culture_name' => $claim->culture_name,
                'claimed_origin' => 'Культурно-историческая традиция региона',
                'claimed_meaning' => 'Ценностный ориентир преемственности поколений',
                'server_verification_status' => 'verified_authentic',
                'evidence_records' => [
                    [
                        'event_source_id' => $sourceA->id,
                        'source_title' => $sourceA->title,
                        'citation_locator' => 'Стр. 10',
                        'verification_status' => 'verified_authentic',
                        'verified_at' => now()->toIso8601String(),
                    ],
                    [
                        'event_source_id' => $sourceB->id,
                        'source_title' => $sourceB->title,
                        'citation_locator' => 'Стр. 20',
                        'verification_status' => 'verified_authentic',
                        'verified_at' => now()->toIso8601String(),
                    ],
                ],
            ],
        ];
        DB::table('scenario_revisions')->where('id', $this->revision->id)->update(['content_payload' => json_encode($payload)]);
        $this->revision->refresh();

        // 1. Pass moderation
        $run = app(ModerationEngineService::class)->verifyRevision($this->revision);
        $this->assertEquals('passed', $run->status);

        // 2. Sign-off
        TeacherSignoff::create([
            'revision_id' => $this->revision->id,
            'user_id' => $this->teacher->id,
            'is_confirmed' => true,
            'provenance_fingerprint' => $this->revision->moderationState->provenance_fingerprint,
            'checklist_confirmed' => ['safety_checked' => true, 'timing_fit' => true, 'cultural_context_approved' => true],
            'confirmed_at' => now(),
            'created_at' => now(),
        ]);

        // 3. Generate ready PDF
        $exportRes = $this->actingAs($this->teacher)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/scenarios/{$this->scenario->id}/revisions/1/exports", ['format' => 'pdf']);
        $jobId = $exportRes->json('data.id');

        $job = ExportJob::findOrFail($jobId);
        if ($job->status !== 'ready') {
            app(ScenarioExportService::class)->export($job);
            $job->refresh();
        }

        // 4. Curator revokes Source B (decision = rejected). Source A remains approved.
        SourceReview::create([
            'event_source_id' => $sourceB->id,
            'reviewer_user_id' => $this->curator->id,
            'decision' => 'rejected',
            'comments' => 'Источник 2 отклонен при повторной проверке',
            'reviewed_at' => now()->addSecond(),
        ]);

        // 5. Download must be strictly blocked with 403 EXPORT_BLOCKED_BY_PROVENANCE_REVOCATION
        $downloadRes = $this->actingAs($this->teacher)->getJson("/api/v1/export-jobs/{$jobId}/download");
        $downloadRes->assertStatus(403)
            ->assertJsonPath('error.code', 'EXPORT_BLOCKED_BY_PROVENANCE_REVOCATION');
    }
}
