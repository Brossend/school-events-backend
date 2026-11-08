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
use App\Models\RevisionModerationState;
use App\Models\Scenario;
use App\Models\ScenarioRequest;
use App\Models\ScenarioRevision;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\SourceReview;
use App\Models\TeacherSignoff;
use App\Models\User;
use App\Services\ProvenanceVerificationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImmutableEvidenceAuditAndStaleExportTest extends TestCase
{
    use DatabaseTransactions;

    protected School $school;

    protected User $teacher;

    protected User $curator;

    protected CalendarEvent $event;

    protected EventSource $source;

    protected CulturalClaim $claim;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->school = School::factory()->create([
            'name' => 'Лицей № 130 г. Екатеринбург',
            'short_name' => 'Лицей 130',
            'region_code' => '66',
            'city' => 'Екатеринбург',
        ]);

        $this->teacher = User::factory()->create([
            'name' => 'Иванова Мария Сергеевна',
            'email' => 'teacher_imm_'.uniqid().'@school130.ru',
            'system_role' => 'none',
            'is_active' => true,
        ]);
        SchoolMembership::factory()->create([
            'school_id' => $this->school->id,
            'user_id' => $this->teacher->id,
            'role' => 'teacher',
            'is_default' => true,
        ]);

        $this->curator = User::factory()->curator('global')->create([
            'name' => 'Академический Эксперт Куратор',
            'email' => 'curator_imm_'.uniqid().'@catalog.ru',
            'is_active' => true,
        ]);

        $this->event = CalendarEvent::create([
            'title' => 'День народного единства',
            'event_type' => 'federal',
            'date_type' => 'fixed_annual',
            'fixed_month' => 11,
            'fixed_day' => 4,
            'status' => 'approved',
            'description' => 'Государственный праздник Российской Федерации',
            'target_age_groups' => ['basic_5_9'],
            'recommended_value_directions' => ['historical_memory'],
        ]);

        $this->source = EventSource::create([
            'calendar_event_id' => $this->event->id,
            'source_type' => 'normative_act',
            'title' => 'Федеральный закон от 29.12.2004 № 201-ФЗ',
            'author' => 'Государственная Дума РФ',
            'url' => 'http://pravo.gov.ru/proxy/ips/?docbody=&nd=102090518',
            'verified_by_user_id' => $this->curator->id,
            'verification_date' => now(),
        ]);

        SourceReview::create([
            'event_source_id' => $this->source->id,
            'reviewer_user_id' => $this->curator->id,
            'decision' => 'approved',
            'comments' => 'Эталонный нормативный правовой акт РФ.',
            'reviewed_at' => now(),
        ]);

        $this->claim = CulturalClaim::create([
            'calendar_event_id' => $this->event->id,
            'claim_text' => '4 ноября отмечается День народного единства в соответствии с ТК РФ.',
            'culture_name' => 'Российская Федерация',
            'provenance_notes' => 'Подтверждено Федеральным законом № 201-ФЗ.',
            'created_at' => now(),
        ]);
    }

    /**
     * Test A: Immutable Evidence History: detach -> reattach preserves full audit trail.
     * Initial author, timestamp, detachment reason, and previous verification status are preserved.
     */
    public function test_mandatory_a_immutable_evidence_history_detach_then_reattach(): void
    {
        // 1. Initial attachment and attestation
        $attachRes = $this->actingAs($this->curator)
            ->postJson("/api/v1/cultural-claims/{$this->claim->id}/evidence", [
                'event_source_id' => $this->source->id,
                'citation_locator' => 'ст. 112 ТК РФ',
                'citation_excerpt' => '4 ноября — День народного единства в редакции ФЗ № 201-ФЗ',
                'verify' => true,
            ]);

        $attachRes->assertStatus(201);
        $this->assertEquals(1, CulturalClaimEvidenceAuditEvent::where('cultural_claim_id', $this->claim->id)->count());
        $initialEvent = CulturalClaimEvidenceAuditEvent::where('cultural_claim_id', $this->claim->id)->first();
        $this->assertEquals('attached', $initialEvent->action);
        $this->assertEquals($this->curator->id, $initialEvent->actor_user_id);
        $this->assertEquals('verified_authentic', $initialEvent->new_verification_status);

        // 2. Detach evidence with a specific reason
        $detachReason = 'Обнаружена неточность в цитатном локаторе, требуется перепроверка';
        $detachRes = $this->actingAs($this->curator)
            ->deleteJson("/api/v1/cultural-claims/{$this->claim->id}/evidence/{$this->source->id}", [
                'reason' => $detachReason,
            ]);

        $detachRes->assertStatus(200);

        // Verify detach event was recorded
        $this->assertEquals(2, CulturalClaimEvidenceAuditEvent::where('cultural_claim_id', $this->claim->id)->count());
        $detachEvent = CulturalClaimEvidenceAuditEvent::where('cultural_claim_id', $this->claim->id)->where('action', 'detached')->first();
        $this->assertNotNull($detachEvent);
        $this->assertEquals($this->curator->id, $detachEvent->actor_user_id);
        $this->assertEquals($detachReason, $detachEvent->reason);
        $this->assertEquals('verified_authentic', $detachEvent->previous_verification_status);
        $this->assertEquals('unverified', $detachEvent->new_verification_status);
        $this->assertEquals('ст. 112 ТК РФ', $detachEvent->citation_locator);

        // 3. Reattach and reverify evidence
        $reattachRes = $this->actingAs($this->curator)
            ->postJson("/api/v1/cultural-claims/{$this->claim->id}/evidence", [
                'event_source_id' => $this->source->id,
                'citation_locator' => 'ст. 112 ТК РФ, пункт 1',
                'citation_excerpt' => '4 ноября — День народного единства, нерабочий праздничный день',
                'verify' => true,
                'notes' => 'Уточнён пункт статьи нормативного акта',
            ]);

        $reattachRes->assertStatus(201);

        // SQL assertion: Both operations preserved, initial history NOT overwritten
        $allEvents = CulturalClaimEvidenceAuditEvent::where('cultural_claim_id', $this->claim->id)->orderBy('created_at', 'asc')->orderBy('id')->get();
        $this->assertCount(3, $allEvents, 'All 3 audit events (attached, detached, reverified) must be preserved');

        $this->assertEquals('attached', $allEvents[0]->action);
        $this->assertEquals('detached', $allEvents[1]->action);
        $this->assertEquals($detachReason, $allEvents[1]->reason, 'Original detachment reason must be preserved');
        $this->assertEquals($this->curator->id, $allEvents[1]->actor_user_id);

        $this->assertContains($allEvents[2]->action, ['reverified', 'reattached']);
        $this->assertEquals('ст. 112 ТК РФ, пункт 1', $allEvents[2]->citation_locator);
        $this->assertEquals('verified_authentic', $allEvents[2]->new_verification_status);

        // Verify claim show endpoint exposes immutable audit events
        $showRes = $this->actingAs($this->curator)
            ->getJson("/api/v1/cultural-claims/{$this->claim->id}");

        $showRes->assertStatus(200);
        $showRes->assertJsonCount(3, 'data.evidence_audit_events');
    }

    /**
     * Test B: Source Modification & Re-approval Gate:
     * PDF ready -> change URL/title -> re-approved -> re-attested -> OLD PDF does NOT become downloadable.
     */
    public function test_mandatory_b_stale_pdf_blocked_after_source_modification_and_reapproval(): void
    {
        $bundle = $this->setupValidScenarioWithPDF();
        $oldJob = $bundle['job'];
        $scenario = $bundle['scenario'];
        $revision = $bundle['revision'];

        // 1. Initial state: PDF is ready and downloadable
        $downloadRes = $this->actingAs($this->teacher)
            ->withHeader('X-School-Id', $this->school->id)
            ->get("/api/v1/export-jobs/{$oldJob->id}/download");

        $downloadRes->assertStatus(200);
        $this->assertEquals('application/pdf', $downloadRes->headers->get('Content-Type'));

        // 2. Modify source URL and title
        $patchRes = $this->actingAs($this->curator)
            ->patchJson("/api/v1/event-sources/{$this->source->id}", [
                'title' => 'Федеральный закон № 201-ФЗ (Новая эталонная редакция)',
                'url' => 'http://publication.pravo.gov.ru/Document/View/0001200412300001',
            ]);

        $patchRes->assertStatus(200);

        // Verification is invalidated, old PDF download immediately blocked
        $blockedRes1 = $this->actingAs($this->teacher)
            ->withHeader('X-School-Id', $this->school->id)
            ->get("/api/v1/export-jobs/{$oldJob->id}/download");

        $blockedRes1->assertStatus(403);
        $this->assertEquals('EXPORT_BLOCKED_BY_PROVENANCE_REVOCATION', $blockedRes1->json('error.code'));

        // 3. Curator re-reviews source as approved
        $reviewRes = $this->actingAs($this->curator)
            ->postJson("/api/v1/event-sources/{$this->source->id}/reviews", [
                'decision' => 'approved',
                'comments' => 'Новая официальная ссылка проверена и подтверждена.',
            ]);

        $reviewRes->assertStatus(201);

        // 4. Curator re-attests the claim
        $reAttestRes = $this->actingAs($this->curator)
            ->postJson("/api/v1/cultural-claims/{$this->claim->id}/evidence", [
                'event_source_id' => $this->source->id,
                'citation_locator' => 'ст. 112 ТК РФ',
                'citation_excerpt' => '4 ноября — День народного единства в обновлённой редакции',
                'verify' => true,
            ]);

        $reAttestRes->assertStatus(201);

        // 5. CRUCIAL TEST B ASSERTION: Attempt to download the OLD PDF (Job 1)
        // The old PDF was generated under the superseded evidence state.
        // It MUST NOT automatically become available!
        $blockedRes2 = $this->actingAs($this->teacher)
            ->withHeader('X-School-Id', $this->school->id)
            ->get("/api/v1/export-jobs/{$oldJob->id}/download");

        $blockedRes2->assertStatus(403);
        $this->assertEquals('EXPORT_BLOCKED_BY_PROVENANCE_REVOCATION', $blockedRes2->json('error.code'));
        $this->assertStringContainsString('доказательная база', $blockedRes2->json('error.message'));
    }

    /**
     * Test C: New Valid Export:
     * After prescribed re-confirmation of the current scenario, a new PDF is compiled and downloads successfully,
     * while the old PDF remains strictly blocked as a historical artifact.
     */
    public function test_mandatory_c_new_valid_export_after_reconfirmation(): void
    {
        $bundle = $this->setupValidScenarioWithPDF();
        $oldJob = $bundle['job'];
        $scenario = $bundle['scenario'];
        $revision = $bundle['revision'];

        // Modify source URL
        $this->actingAs($this->curator)
            ->patchJson("/api/v1/event-sources/{$this->source->id}", [
                'url' => 'http://publication.pravo.gov.ru/Document/View/0001200412300001',
            ]);

        // Re-approve source and re-attest claim
        $this->actingAs($this->curator)
            ->postJson("/api/v1/event-sources/{$this->source->id}/reviews", [
                'decision' => 'approved',
                'comments' => 'Одобрено повторно.',
            ]);

        $this->actingAs($this->curator)
            ->postJson("/api/v1/cultural-claims/{$this->claim->id}/evidence", [
                'event_source_id' => $this->source->id,
                'citation_locator' => 'ст. 112 ТК РФ',
                'citation_excerpt' => '4 ноября — День народного единства в обновлённой редакции',
                'verify' => true,
            ]);

        // Old PDF is blocked
        $this->actingAs($this->teacher)
            ->withHeader('X-School-Id', $this->school->id)
            ->get("/api/v1/export-jobs/{$oldJob->id}/download")
            ->assertStatus(403);

        // 1. Prescribed Re-Confirmation Step 1: Re-trigger automated verification
        $verifyRes = $this->actingAs($this->teacher)
            ->withHeader('X-School-Id', $this->school->id)
            ->postJson("/api/v1/scenarios/{$scenario->id}/revisions/{$revision->version_number}/verify");

        $verifyRes->assertStatus(202);
        $this->assertContains($verifyRes->json('data.status'), ['passed', 'passed_with_warnings']);

        // 2. Prescribed Re-Confirmation Step 2: Teacher re-confirms with sign-off for current evidence state
        $signoffRes = $this->actingAs($this->teacher)
            ->withHeader('X-School-Id', $this->school->id)
            ->postJson("/api/v1/scenarios/{$scenario->id}/revisions/{$revision->version_number}/signoff", [
                'checklist_confirmed' => [
                    'safety_checked' => true,
                    'timing_fit' => true,
                    'cultural_context_approved' => true,
                ],
                'warnings_acknowledged' => true,
                'teacher_notes' => 'Повторно подтверждено с учётом обновлённой ссылки первоисточника.',
            ]);

        $signoffRes->assertStatus(200);

        // 3. Request NEW PDF export
        $newExportRes = $this->actingAs($this->teacher)
            ->withHeader('X-School-Id', $this->school->id)
            ->withHeader('Idempotency-Key', 'idemp_new_export_after_reconfirmation_'.uniqid())
            ->postJson("/api/v1/scenarios/{$scenario->id}/revisions/{$revision->version_number}/exports", [
                'format' => 'pdf',
            ]);

        $newExportRes->assertStatus(202);
        $newJobId = $newExportRes->json('data.id');
        $this->assertNotEquals($oldJob->id, $newJobId);

        // 4. Download NEW PDF -> SUCCESS HTTP 200
        $newDownloadRes = $this->actingAs($this->teacher)
            ->withHeader('X-School-Id', $this->school->id)
            ->get("/api/v1/export-jobs/{$newJobId}/download");

        $newDownloadRes->assertStatus(200);
        $this->assertEquals('application/pdf', $newDownloadRes->headers->get('Content-Type'));

        // 5. Old PDF STILL BLOCKED
        $oldDownloadRes = $this->actingAs($this->teacher)
            ->withHeader('X-School-Id', $this->school->id)
            ->get("/api/v1/export-jobs/{$oldJob->id}/download");

        $oldDownloadRes->assertStatus(403);
        $this->assertEquals('EXPORT_BLOCKED_BY_PROVENANCE_REVOCATION', $oldDownloadRes->json('error.code'));
    }

    /**
     * Section 4 Test: Lifecycle status 'completed' must strictly evaluate the current active revision.
     * When v1 was signed off, but a new v2 is created and not yet confirmed, scenario status is 'draft'.
     */
    public function test_section_4_scenario_status_draft_when_current_v2_not_signed_off(): void
    {
        $bundle = $this->setupValidScenarioWithPDF();
        $scenario = $bundle['scenario'];
        $rev1 = $bundle['revision'];

        // Initially with signed-off v1, scenario is completed
        $show1 = $this->actingAs($this->teacher)
            ->withHeader('X-School-Id', $this->school->id)
            ->getJson("/api/v1/scenarios/{$scenario->id}");

        $show1->assertStatus(200)->assertJsonPath('data.status', 'completed');

        // Create a new revision v2 (active current revision)
        $rev2 = ScenarioRevision::create([
            'scenario_id' => $scenario->id,
            'version_number' => 2,
            'created_by_user_id' => $this->teacher->id,
            'change_description' => 'Редактирование педагогом этапов сценария',
            'concept' => 'Обновленная концепция',
            'pedagogical_goal' => 'Развитие гражданской ответственности',
            'pedagogical_mechanism' => ['mechanism_type' => 'comparative_cultural_study'],
            'pedagogical_tasks' => ['personal' => ['Уважение к культуре региона']],
            'expected_outcomes' => ['knowledge' => ['Традиционные промыслы']],
            'target_audience' => ['age_group' => 'basic_5_9'],
            'format' => ['scale' => 'class_level'],
            'total_duration_minutes' => 45,
            'teacher_instructions' => ['safety' => 'Инструктаж'],
            'content_payload' => $rev1->content_payload,
            'created_at' => now(),
        ]);

        RevisionModerationState::create([
            'revision_id' => $rev2->id,
            'status' => 'pending_verification',
            'p0_violations_count' => 0,
            'p1_violations_count' => 0,
            'updated_at' => now(),
        ]);

        $scenario->update([
            'current_revision_id' => $rev2->id,
            'optimistic_lock_version' => 2,
        ]);

        // 1. GET /scenarios/{id} MUST be 'draft' because current v2 is not signed off
        $show2 = $this->actingAs($this->teacher)
            ->withHeader('X-School-Id', $this->school->id)
            ->getJson("/api/v1/scenarios/{$scenario->id}");

        $show2->assertStatus(200);
        $this->assertEquals('draft', $show2->json('data.status'), 'Scenario must be draft when current revision is unconfirmed');

        // 2. GET /scenarios list query
        $listRes = $this->actingAs($this->teacher)
            ->withHeader('X-School-Id', $this->school->id)
            ->getJson('/api/v1/scenarios?status=draft');

        $listRes->assertStatus(200);
        $draftItem = collect($listRes->json('data'))->firstWhere('id', $scenario->id);
        $this->assertNotNull($draftItem, 'Scenario must appear in status=draft filter');
        $this->assertEquals('draft', $draftItem['status']);

        // Check it does NOT appear in completed filter
        $compListRes = $this->actingAs($this->teacher)
            ->withHeader('X-School-Id', $this->school->id)
            ->getJson('/api/v1/scenarios?status=completed');

        $compListRes->assertStatus(200);
        $compItem = collect($compListRes->json('data'))->firstWhere('id', $scenario->id);
        $this->assertNull($compItem, 'Scenario must NOT appear in status=completed filter when current revision is unconfirmed');

        // 3. Dashboard counts
        $dashRes = $this->actingAs($this->teacher)
            ->withHeader('X-School-Id', $this->school->id)
            ->getJson('/api/v1/dashboard/summary');

        $dashRes->assertStatus(200);
        $this->assertEquals(1, $dashRes->json('data.counts.draft_scenarios'));
        $this->assertEquals(0, $dashRes->json('data.counts.completed_scenarios'));
    }

    /**
     * Helper to set up a complete scenario with approved evidence, sign-off, and ready PDF artifact.
     */
    protected function setupValidScenarioWithPDF(): array
    {
        // Attach and verify claim
        CulturalClaimSource::create([
            'calendar_event_id' => $this->event->id,
            'cultural_claim_id' => $this->claim->id,
            'event_source_id' => $this->source->id,
            'citation_locator' => 'ст. 112 ТК РФ',
            'citation_excerpt' => '4 ноября — День народного единства в редакции ФЗ № 201-ФЗ',
            'verification_status' => 'verified_authentic',
            'verified_by_user_id' => $this->curator->id,
            'verified_at' => now(),
            'is_detached' => false,
            'created_at' => now(),
        ]);

        CulturalClaimEvidenceAuditEvent::create([
            'cultural_claim_id' => $this->claim->id,
            'event_source_id' => $this->source->id,
            'action' => 'attached',
            'actor_user_id' => $this->curator->id,
            'new_verification_status' => 'verified_authentic',
            'citation_locator' => 'ст. 112 ТК РФ',
            'citation_excerpt' => '4 ноября — День народного единства в редакции ФЗ № 201-ФЗ',
            'created_at' => now(),
        ]);

        $req = ScenarioRequest::create([
            'school_id' => $this->school->id,
            'user_id' => $this->teacher->id,
            'calendar_event_id' => $this->event->id,
            'scale' => 'class_level',
            'format_type' => 'class_hour',
            'target_grades' => [6],
            'participants_count' => 25,
            'duration_minutes' => 45,
            'value_direction' => 'historical_memory',
            'family_involvement' => false,
            'context_snapshot' => ['school' => ['id' => $this->school->id]],
            'created_at' => now(),
        ]);

        $payload = [
            'theme' => 'Единство народов России',
            'cultural_claims' => [
                [
                    'claim_id' => $this->claim->id,
                    'claim_text' => $this->claim->claim_text,
                    'server_verification_status' => 'verified_authentic',
                    'evidence_records' => [
                        [
                            'event_source_id' => $this->source->id,
                            'citation_locator' => 'ст. 112 ТК РФ',
                            'citation_excerpt' => '4 ноября — День народного единства в редакции ФЗ № 201-ФЗ',
                        ],
                    ],
                ],
            ],
            'stages' => [
                [
                    'id' => 'st-1',
                    'title' => 'Введение',
                    'duration_minutes' => 45,
                    'description' => 'Вступительное слово',
                ],
            ],
        ];

        $scenario = Scenario::create([
            'school_id' => $this->school->id,
            'user_id' => $this->teacher->id,
            'scenario_request_id' => $req->id,
            'title' => 'Единство народов России — Классный час',
            'optimistic_lock_version' => 1,
            'is_archived' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $revision = ScenarioRevision::create([
            'scenario_id' => $scenario->id,
            'version_number' => 1,
            'created_by_user_id' => $this->teacher->id,
            'change_description' => 'Первоначальная генерация',
            'concept' => 'Концепция сценария',
            'pedagogical_goal' => 'Воспитание уважения к истории Отечества',
            'pedagogical_mechanism' => ['mechanism_type' => 'comparative_cultural_study'],
            'pedagogical_tasks' => ['personal' => ['Уважение к культуре региона']],
            'expected_outcomes' => ['knowledge' => ['Традиционные промыслы']],
            'target_audience' => ['age_group' => 'basic_5_9'],
            'format' => ['scale' => 'class_level'],
            'total_duration_minutes' => 45,
            'teacher_instructions' => ['safety' => 'Инструктаж'],
            'content_payload' => $payload,
            'created_at' => now(),
        ]);

        $scenario->update(['current_revision_id' => $revision->id]);

        $fingerprint = app(ProvenanceVerificationService::class)->computeRevisionProvenanceFingerprint($revision);

        RevisionModerationState::create([
            'revision_id' => $revision->id,
            'status' => 'verification_passed',
            'provenance_fingerprint' => $fingerprint,
            'p0_violations_count' => 0,
            'p1_violations_count' => 0,
            'updated_at' => now(),
        ]);

        TeacherSignoff::create([
            'revision_id' => $revision->id,
            'user_id' => $this->teacher->id,
            'is_confirmed' => true,
            'provenance_fingerprint' => $fingerprint,
            'checklist_confirmed' => [
                'safety_checked' => true,
                'timing_fit' => true,
                'cultural_context_approved' => true,
            ],
            'teacher_notes' => 'Подтверждено педагогом',
            'ip_address' => '127.0.0.1',
            'confirmed_at' => now(),
            'created_at' => now(),
        ]);

        $job = ExportJob::create([
            'revision_id' => $revision->id,
            'user_id' => $this->teacher->id,
            'format' => 'pdf',
            'status' => 'ready',
            'provenance_fingerprint' => $fingerprint,
            'created_at' => now(),
            'completed_at' => now(),
        ]);

        $pdfBytes = '%PDF-1.4 Mock Binary Content for Testing';
        $path = "exports/test-scenario-{$scenario->id}.pdf";
        Storage::disk('local')->put($path, $pdfBytes);

        $artifact = ExportedArtifact::create([
            'export_job_id' => $job->id,
            'file_disk' => 'local',
            'file_path' => $path,
            'file_name' => 'scenario-export.pdf',
            'file_size_bytes' => strlen($pdfBytes),
            'mime_type' => 'application/pdf',
            'sha256_hash' => hash('sha256', $pdfBytes),
            'provenance_fingerprint' => $fingerprint,
            'expires_at' => now()->addDays(7),
            'created_at' => now(),
        ]);

        return [
            'scenario' => $scenario,
            'revision' => $revision,
            'job' => $job,
            'artifact' => $artifact,
        ];
    }
}
