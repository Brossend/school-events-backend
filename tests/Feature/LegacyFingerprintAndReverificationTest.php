<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CalendarEvent;
use App\Models\CulturalClaim;
use App\Models\CulturalClaimEvidenceAuditEvent;
use App\Models\CulturalClaimSource;
use App\Models\EventSource;
use App\Models\ExportedArtifact;
use App\Models\ExportJob;
use App\Models\Scenario;
use App\Models\ScenarioRequest;
use App\Models\ScenarioRevision;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\SourceReview;
use App\Models\TeacherSignoff;
use App\Models\User;
use App\Services\Export\ScenarioExportService;
use App\Services\Moderation\ModerationEngineService;
use App\Services\ProvenanceVerificationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class LegacyFingerprintAndReverificationTest extends TestCase
{
    use DatabaseTransactions;

    protected School $school;
    protected User $teacher;
    protected User $curator;
    protected CalendarEvent $event;
    protected CulturalClaim $claim;
    protected EventSource $source;
    protected Scenario $scenario;
    protected ScenarioRevision $revision;
    protected ProvenanceVerificationService $provenanceService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provenanceService = app(ProvenanceVerificationService::class);

        $this->school = School::factory()->create(['name' => 'Гимназия № 9']);

        $this->teacher = User::factory()->create([
            'name' => 'Анна Павловна',
            'email' => 'anna_teacher@test.ru',
            'system_role' => 'none',
        ]);
        SchoolMembership::factory()->create([
            'school_id' => $this->school->id,
            'user_id' => $this->teacher->id,
            'role' => 'teacher',
            'is_default' => true,
        ]);

        $this->curator = User::factory()->create([
            'name' => 'Куратор Краеведения',
            'email' => 'curator_heritage@test.ru',
            'system_role' => 'catalog_curator',
            'curator_scope' => 'global',
        ]);

        // 1. Regional event with cultural claims
        $this->event = CalendarEvent::create([
            'title' => 'День народных ремёсел Урала',
            'event_type' => 'regional',
            'region_code' => '66',
            'date_type' => 'fixed_annual',
            'fixed_month' => 11,
            'fixed_day' => 15,
            'target_age_groups' => ['basic_5_9'],
            'recommended_value_directions' => ['culture_and_traditions'],
            'description' => 'Традиционные уральские ремёсла и росписи',
            'status' => 'approved',
        ]);

        $this->source = EventSource::create([
            'calendar_event_id' => $this->event->id,
            'title' => 'Народная роспись Урала: Каталог экспедиций',
            'author' => 'Уральский краеведческий музей',
            'source_type' => 'ethnographic_archive',
            'url' => 'https://ural-museum.ru/heritage/painting.pdf',
            'publisher' => 'Издательство УрГУ',
            'publication_year' => 2018,
            'institution' => 'Уральский государственный университет',
            'confidence_level' => 'high',
            'verification_status' => 'verified',
            'verified_by_user_id' => $this->curator->id,
            'verification_date' => now()->toDateString(),
        ]);

        SourceReview::create([
            'event_source_id' => $this->source->id,
            'reviewer_user_id' => $this->curator->id,
            'decision' => 'approved',
            'comments' => 'Источник верифицирован в академическом архиве.',
            'reviewed_at' => now(),
        ]);

        $this->claim = CulturalClaim::create([
            'calendar_event_id' => $this->event->id,
            'claim_text' => 'Уральская горнозаводская роспись возникла как самобытное явление в XVIII веке.',
            'culture_name' => 'Уральская горнозаводская культура',
            'provenance_notes' => 'Мастера-красильщики Среднего Урала украшали интерьеры и утварь.',
        ]);

        CulturalClaimSource::create([
            'calendar_event_id' => $this->event->id,
            'cultural_claim_id' => $this->claim->id,
            'event_source_id' => $this->source->id,
            'citation_locator' => 'Раздел 1, стр. 14-18',
            'citation_excerpt' => 'Документальные свидетельства росписи изб на Среднем Урале известны с первой трети XVIII века.',
            'verification_status' => 'verified_authentic',
            'verified_by_user_id' => $this->curator->id,
            'verified_at' => now(),
        ]);

        // Scenario and revision linking to the claim
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
            'available_resources' => ['Краски', 'Кисти'],
            'context_snapshot' => [
                'occasion' => ['title' => 'День народных ремёсел Урала'],
            ],
        ]);

        $this->scenario = Scenario::create([
            'scenario_request_id' => $req->id,
            'school_id' => $this->school->id,
            'user_id' => $this->teacher->id,
            'title' => 'Мастер-класс: Уральская роспись',
            'status' => 'ready',
        ]);

        $payload = [
            'overview' => 'Знакомство с техникой уральской росписи',
            'cultural_claims' => [
                [
                    'claim_id' => $this->claim->id,
                    'statement' => $this->claim->claim_text,
                    'citation_locator' => 'Раздел 1, стр. 14-18',
                    'evidence_records' => [
                        [
                            'event_source_id' => $this->source->id,
                            'citation_locator' => 'Раздел 1, стр. 14-18',
                        ],
                    ],
                ],
            ],
            'materials' => [
                'presentation_slides' => ['Слайд 1: История росписи'],
                'printable_cards' => ['Карточка 1: Элементы орнамента'],
            ],
        ];

        $this->revision = ScenarioRevision::create([
            'scenario_id' => $this->scenario->id,
            'version_number' => 1,
            'created_by_user_id' => $this->teacher->id,
            'change_description' => 'Первоначальная генерация',
            'concept' => 'Знакомство с техникой и традициями уральской росписи',
            'pedagogical_goal' => 'Формирование уважения к культурному наследию',
            'pedagogical_mechanism' => ['mechanism_type' => 'comparative_cultural_study'],
            'pedagogical_tasks' => ['personal' => ['Развитие эстетического вкуса']],
            'expected_outcomes' => ['knowledge' => ['Освоение базовых элементов росписи']],
            'target_audience' => ['age_group' => 'basic_5_9'],
            'format' => ['scale' => 'class_level'],
            'total_duration_minutes' => 45,
            'teacher_instructions' => ['safety' => 'Инструктаж по технике безопасности'],
            'content_payload' => $payload,
            'created_at' => now(),
        ]);

        $this->scenario->update(['current_revision_id' => $this->revision->id]);
    }

    /**
     * Test A: Legacy PDF with NULL provenance_fingerprint is fail-closed blocked from download.
     */
    public function test_a_legacy_pdf_with_null_fingerprint_download_blocked(): void
    {
        // 1. Moderate revision cleanly
        app(ModerationEngineService::class)->verifyRevision($this->revision);

        // 2. Add valid sign-off
        TeacherSignoff::create([
            'revision_id' => $this->revision->id,
            'user_id' => $this->teacher->id,
            'is_confirmed' => true,
            'provenance_fingerprint' => $this->revision->moderationState->provenance_fingerprint,
            'checklist_confirmed' => [
                'safety_checked' => true,
                'timing_fit' => true,
                'cultural_context_approved' => true,
            ],
            'confirmed_at' => now(),
            'created_at' => now(),
        ]);

        // 3. Create a legacy PDF export job with NULL provenance_fingerprint (pre-BE-006.2 database record)
        $legacyJob = ExportJob::create([
            'revision_id' => $this->revision->id,
            'user_id' => $this->teacher->id,
            'format' => 'pdf',
            'status' => 'ready',
            'provenance_fingerprint' => null, // Explicit legacy NULL
            'created_at' => now()->subDays(5),
            'completed_at' => now()->subDays(5),
        ]);

        $fakePdfContent = '%PDF-1.4 Legacy pre-fingerprint PDF content';
        $fakePath = "exports/legacy_scenario_{$this->scenario->id}_v1.pdf";
        Storage::disk('local')->put($fakePath, $fakePdfContent);

        ExportedArtifact::create([
            'export_job_id' => $legacyJob->id,
            'file_disk' => 'local',
            'file_path' => $fakePath,
            'file_name' => "legacy_scenario_{$this->scenario->id}.pdf",
            'file_size_bytes' => strlen($fakePdfContent),
            'mime_type' => 'application/pdf',
            'sha256_hash' => hash('sha256', $fakePdfContent),
            'provenance_fingerprint' => null,
            'created_at' => now()->subDays(5),
        ]);

        // 4. Curator amends source details and re-approves
        $this->actingAs($this->curator)
            ->patchJson("/api/v1/event-sources/{$this->source->id}", [
                'url' => 'https://ural-museum.ru/heritage/painting_revised.pdf',
            ])->assertStatus(200);

        // Re-review and re-verify claim
        $this->actingAs($this->curator)
            ->postJson("/api/v1/event-sources/{$this->source->id}/reviews", [
                'decision' => 'approved',
                'comments' => 'Повторное одобрение обновлённого URL.',
            ])->assertStatus(201);

        $this->actingAs($this->curator)
            ->postJson("/api/v1/cultural-claims/{$this->claim->id}/evidence", [
                'event_source_id' => $this->source->id,
                'citation_locator' => 'Раздел 1, стр. 14-20 (расширенное)',
                'citation_excerpt' => 'Документальные свидетельства росписи изб на Среднем Урале подтверждены полевыми записями.',
                'verify' => true,
            ])->assertStatus(201);

        // 5. Attempting to download the legacy PDF must return 403 (fail-closed!)
        $response = $this->actingAs($this->teacher)
            ->withHeader('X-School-Id', $this->school->id)
            ->getJson("/api/v1/export-jobs/{$legacyJob->id}/download");

        $response->assertStatus(403)
            ->assertJsonPath('error.code', 'EXPORT_BLOCKED_BY_PROVENANCE_REVOCATION')
            ->assertJsonPath('error.details.reason', 'LEGACY_NULL_EXPORT_FINGERPRINT');
    }

    /**
     * Test B: Legacy sign-off with NULL provenance_fingerprint cannot be used for new exports.
     */
    public function test_b_legacy_signoff_with_null_fingerprint_forbidden_for_new_export(): void
    {
        // 1. Moderate revision cleanly
        app(ModerationEngineService::class)->verifyRevision($this->revision);

        // 2. Create legacy signoff with NULL provenance_fingerprint
        TeacherSignoff::create([
            'revision_id' => $this->revision->id,
            'user_id' => $this->teacher->id,
            'is_confirmed' => true,
            'provenance_fingerprint' => null, // Explicit legacy NULL
            'checklist_confirmed' => [
                'safety_checked' => true,
                'timing_fit' => true,
                'cultural_context_approved' => true,
            ],
            'confirmed_at' => now()->subDays(10),
            'created_at' => now()->subDays(10),
        ]);

        // 3. Attempt to request a new PDF export
        $response = $this->actingAs($this->teacher)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/scenarios/{$this->scenario->id}/revisions/1/exports", [
                'format' => 'pdf',
            ]);

        $response->assertStatus(403)
            ->assertJsonPath('error.code', 'EXPORT_FORBIDDEN_SIGN_OFF_REQUIRED')
            ->assertJsonPath('error.details.reason', 'LEGACY_NULL_SIGNOFF_FINGERPRINT');
    }

    /**
     * Test C: Bypass Reverification Gate: Attempting sign-off without re-moderation after evidence modification is rejected with 422.
     */
    public function test_c_bypass_reverification_signoff_rejected_without_re_moderation(): void
    {
        // 1. Initial moderation passes and writes fingerprint F1
        app(ModerationEngineService::class)->verifyRevision($this->revision);
        $initialFingerprint = $this->revision->moderationState->provenance_fingerprint;
        $this->assertNotEmpty($initialFingerprint);

        // 2. Curator modifies citation details and re-attests claim (producing new fingerprint F2)
        $this->actingAs($this->curator)
            ->postJson("/api/v1/cultural-claims/{$this->claim->id}/evidence", [
                'event_source_id' => $this->source->id,
                'citation_locator' => 'Том 2, параграф 4, стр. 99-102',
                'citation_excerpt' => 'Новая расширенная архивная выписка о мастерах росписи Нижнего Тагила.',
                'verify' => true,
            ])->assertStatus(201);

        $newFingerprint = $this->provenanceService->computeRevisionProvenanceFingerprint($this->revision);
        $this->assertNotEquals($initialFingerprint, $newFingerprint);

        // Moderation state still holds stale $initialFingerprint!
        $this->revision->refresh();
        $this->assertEquals($initialFingerprint, $this->revision->moderationState->provenance_fingerprint);

        // 3. Teacher attempts sign-off WITHOUT re-running moderation
        $response = $this->actingAs($this->teacher)
            ->postJson("/api/v1/scenarios/{$this->scenario->id}/revisions/1/signoff", [
                'checklist_confirmed' => [
                    'safety_checked' => true,
                    'timing_fit' => true,
                    'cultural_context_approved' => true,
                ],
                'teacher_notes' => 'Попытка согласования в обход модерации',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'MODERATION_REVERIFICATION_REQUIRED')
            ->assertJsonPath('error.details.moderation_fingerprint', $initialFingerprint)
            ->assertJsonPath('error.details.current_fingerprint', $newFingerprint);
    }

    /**
     * Test D: Positive Path: Re-moderation -> new sign-off -> new export -> successful download HTTP 200, old PDF remains 403.
     */
    public function test_d_positive_path_re_moderation_new_signoff_new_export_and_old_pdf_blocked(): void
    {
        // 1. Initial moderation & sign-off & export
        app(ModerationEngineService::class)->verifyRevision($this->revision);
        $fingerprint1 = $this->revision->moderationState->provenance_fingerprint;

        TeacherSignoff::create([
            'revision_id' => $this->revision->id,
            'user_id' => $this->teacher->id,
            'is_confirmed' => true,
            'provenance_fingerprint' => $fingerprint1,
            'checklist_confirmed' => ['safety_checked' => true, 'timing_fit' => true, 'cultural_context_approved' => true],
            'confirmed_at' => now(),
            'created_at' => now(),
        ]);

        $job1Res = $this->actingAs($this->teacher)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/scenarios/{$this->scenario->id}/revisions/1/exports", ['format' => 'pdf']);
        $job1Res->assertStatus(202);
        $job1Id = $job1Res->json('data.id');

        $job1 = ExportJob::findOrFail($job1Id);
        if ($job1->status !== 'ready') {
            app(ScenarioExportService::class)->export($job1);
        }

        // Initially job1 is downloadable
        $this->actingAs($this->teacher)
            ->withHeader('X-School-Id', $this->school->id)
            ->getJson("/api/v1/export-jobs/{$job1Id}/download")
            ->assertStatus(200);

        // 2. Curator modifies citation & re-verifies claim
        $this->actingAs($this->curator)
            ->postJson("/api/v1/cultural-claims/{$this->claim->id}/evidence", [
                'event_source_id' => $this->source->id,
                'citation_locator' => 'Том 3, стр. 200-205 (обновленная редакция)',
                'citation_excerpt' => 'Уточнённая архивная выписка по тагильским подносам и росписи.',
                'verify' => true,
            ])->assertStatus(201);

        // At this point, job1 download is immediately blocked because evidence changed!
        $this->actingAs($this->teacher)
            ->withHeader('X-School-Id', $this->school->id)
            ->getJson("/api/v1/export-jobs/{$job1Id}/download")
            ->assertStatus(403);

        // 3. STEP 1: Re-moderation runs
        $run = app(ModerationEngineService::class)->verifyRevision($this->revision);
        $this->assertContains($run->status, ['passed', 'passed_with_warnings']);
        $this->revision->refresh();
        $fingerprint2 = $this->revision->moderationState->provenance_fingerprint;
        $this->assertNotEquals($fingerprint1, $fingerprint2);

        // 4. STEP 2: Teacher submits fresh sign-off for fingerprint2
        $signoffRes = $this->actingAs($this->teacher)
            ->postJson("/api/v1/scenarios/{$this->scenario->id}/revisions/1/signoff", [
                'checklist_confirmed' => [
                    'safety_checked' => true,
                    'timing_fit' => true,
                    'cultural_context_approved' => true,
                    'warnings_acknowledged' => true,
                ],
                'teacher_notes' => 'Повторное согласование под обновлённую доказательную базу.',
            ]);
        $signoffRes->assertStatus(200);

        // 5. STEP 3: Teacher requests new export
        $exportKey2 = (string) Str::uuid();
        $job2Res = $this->actingAs($this->teacher)
            ->withHeader('Idempotency-Key', $exportKey2)
            ->postJson("/api/v1/scenarios/{$this->scenario->id}/revisions/1/exports", ['format' => 'pdf']);
        $job2Res->assertStatus(202);
        $job2Id = $job2Res->json('data.id');

        $job2 = ExportJob::findOrFail($job2Id);
        $this->assertEquals($fingerprint2, $job2->provenance_fingerprint);

        if ($job2->status !== 'ready') {
            app(ScenarioExportService::class)->export($job2);
        }

        // 6. STEP 4: Download new PDF succeeds with HTTP 200!
        $downloadRes = $this->actingAs($this->teacher)
            ->withHeader('X-School-Id', $this->school->id)
            ->get("/api/v1/export-jobs/{$job2Id}/download");
        $downloadRes->assertStatus(200);
        $this->assertEquals('application/pdf', $downloadRes->headers->get('Content-Type'));

        // 7. Old PDF job1 remains blocked with 403!
        $oldDownloadRes = $this->actingAs($this->teacher)
            ->withHeader('X-School-Id', $this->school->id)
            ->getJson("/api/v1/export-jobs/{$job1Id}/download");
        $oldDownloadRes->assertStatus(403)
            ->assertJsonPath('error.code', 'EXPORT_BLOCKED_BY_PROVENANCE_REVOCATION');
    }

    /**
     * Test E: Audit atomicity: failure during audit event creation rolls back CulturalClaimSource changes.
     */
    public function test_e_audit_atomicity_failure_in_audit_event_rolls_back_evidence_changes(): void
    {
        $uniqueLocator = 'Том 999, стр. ' . rand(1000, 9999);
        $uniqueExcerpt = 'Уникальная цитата для проверки атомарности транзакции.';

        // Attach an event listener to simulate an unhandled failure during audit persistence
        CulturalClaimEvidenceAuditEvent::creating(function () {
            throw new \RuntimeException('Искусственный сбой записи аудита для проверки DB::transaction rollback');
        });

        try {
            $this->actingAs($this->curator)
                ->postJson("/api/v1/cultural-claims/{$this->claim->id}/evidence", [
                    'event_source_id' => $this->source->id,
                    'citation_locator' => $uniqueLocator,
                    'citation_excerpt' => $uniqueExcerpt,
                    'verify' => true,
                ]);
        } catch (\RuntimeException $e) {
            // Expected simulation exception
            $this->assertStringContainsString('Искусственный сбой записи аудита', $e->getMessage());
        } finally {
            // Remove the failing event dispatcher closure
            CulturalClaimEvidenceAuditEvent::flushEventListeners();
        }

        // Verify that CulturalClaimSource was rolled back and NOT persisted in DB!
        $this->assertDatabaseMissing('cultural_claim_sources', [
            'citation_locator' => $uniqueLocator,
        ]);

        $this->assertDatabaseMissing('cultural_claim_evidence_audit_events', [
            'citation_locator' => $uniqueLocator,
        ]);
    }
}
