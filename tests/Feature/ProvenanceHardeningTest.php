<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ExecuteExportJob;
use App\Models\CalendarEvent;
use App\Models\CulturalClaim;
use App\Models\CulturalClaimSource;
use App\Models\EventSource;
use App\Models\ExportJob;
use App\Models\ExportedArtifact;
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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProvenanceHardeningTest extends TestCase
{
    use DatabaseTransactions;

    protected School $school;
    protected User $teacher;
    protected User $curator66;
    protected User $curator77;
    protected User $systemAdmin;
    protected CalendarEvent $event66;
    protected EventSource $source66;
    protected CulturalClaim $claim1;
    protected CulturalClaim $claim2;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->school = School::factory()->create([
            'name' => 'Гимназия № 9',
            'region_code' => '66',
        ]);

        $this->teacher = User::factory()->create([
            'email' => 'teacher66@test.ru',
            'system_role' => 'none',
        ]);

        SchoolMembership::factory()->create([
            'school_id' => $this->school->id,
            'user_id' => $this->teacher->id,
            'role' => 'teacher',
            'is_default' => true,
        ]);

        $this->curator66 = User::factory()->create([
            'email' => 'curator66@test.ru',
            'system_role' => 'catalog_curator',
            'curator_scope' => 'regional',
            'curated_region_codes' => ['66'],
        ]);

        $this->curator77 = User::factory()->create([
            'email' => 'curator77@test.ru',
            'system_role' => 'catalog_curator',
            'curator_scope' => 'regional',
            'curated_region_codes' => ['77'],
        ]);

        $this->systemAdmin = User::factory()->create([
            'email' => 'admin@test.ru',
            'system_role' => 'system_admin',
        ]);

        $this->event66 = CalendarEvent::create([
            'title' => 'День народов Среднего Урала',
            'event_type' => 'regional',
            'region_code' => '66',
            'date_type' => 'fixed_annual',
            'fixed_month' => 9,
            'fixed_day' => 1,
            'description' => 'Праздник традиций и ремесел народов Среднего Урала',
            'target_age_groups' => ['basic_5_9'],
            'recommended_value_directions' => ['culture_and_traditions'],
            'status' => 'approved',
        ]);

        $this->source66 = EventSource::create([
            'calendar_event_id' => $this->event66->id,
            'source_type' => 'scientific_publication',
            'title' => 'Этнография Урала: традиционные промыслы и обряды',
            'author' => 'Иванов И. И.',
            'url' => 'https://ural-ethnography.ru/monograph.pdf',
            'doi_or_isbn' => '978-5-7996-0001-1',
            'verified_by_user_id' => null,
            'verification_date' => null,
        ]);

        $this->claim1 = CulturalClaim::create([
            'calendar_event_id' => $this->event66->id,
            'claim_text' => 'Традиция уральской росписи по дереву бытовала в крестьянских избах с XVIII века.',
            'culture_name' => 'Уральская традиционная культура',
            'provenance_notes' => 'Зафиксировано в экспедициях музея деревянного зодчества.',
        ]);

        $this->claim2 = CulturalClaim::create([
            'calendar_event_id' => $this->event66->id,
            'claim_text' => 'Традиционная уральская берестяная утварь сохраняла прохладу воды в летний зной.',
            'culture_name' => 'Народные промыслы Среднего Урала',
            'provenance_notes' => 'Этнографические материалы по уральскому быту.',
        ]);
    }

    /**
     * Mandatory Test A:
     * Источник одобрен, два утверждения не аттестованы: оба остаются unverified.
     */
    public function test_mandatory_a_source_approved_two_claims_unverified_remain_unverified(): void
    {
        // Bind evidence 1 and 2 as unverified
        $ev1 = CulturalClaimSource::create([
            'calendar_event_id' => $this->event66->id,
            'cultural_claim_id' => $this->claim1->id,
            'event_source_id' => $this->source66->id,
            'citation_locator' => 'Глава 2, стр. 45',
            'citation_excerpt' => 'Роспись изб на Урале фиксируется источниками с первой половины XVIII века.',
            'verification_status' => 'unverified',
        ]);

        $ev2 = CulturalClaimSource::create([
            'calendar_event_id' => $this->event66->id,
            'cultural_claim_id' => $this->claim2->id,
            'event_source_id' => $this->source66->id,
            'citation_locator' => 'Глава 4, стр. 112',
            'citation_excerpt' => 'Берестяные туеса изготавливались мастерами для хранения напитков и молока.',
            'verification_status' => 'unverified',
        ]);

        // Curator approves source
        $response = $this->actingAs($this->curator66)
            ->postJson("/api/v1/event-sources/{$this->source66->id}/reviews", [
                'decision' => 'approved',
                'comments' => 'Академическая монография проверена по библиотечному каталогу, подлинность источника подтверждена.',
            ]);

        $response->assertStatus(201);

        $this->source66->refresh();
        $this->assertEquals($this->curator66->id, $this->source66->verified_by_user_id);
        $this->assertNotNull($this->source66->verification_date);

        // Assert: both claims MUST remain unverified!
        $ev1->refresh();
        $ev2->refresh();
        $this->assertEquals('unverified', $ev1->verification_status);
        $this->assertNull($ev1->verified_by_user_id);
        $this->assertEquals('unverified', $ev2->verification_status);
        $this->assertNull($ev2->verified_by_user_id);
    }

    /**
     * Mandatory Test B:
     * Источник одобрен, первое утверждение аттестовано отдельно: первое становится verified_authentic, второе остаётся unverified.
     */
    public function test_mandatory_b_source_approved_first_claim_verified_separately(): void
    {
        // Approve source
        $this->actingAs($this->curator66)
            ->postJson("/api/v1/event-sources/{$this->source66->id}/reviews", [
                'decision' => 'approved',
                'comments' => 'Источник проверен и одобрен.',
            ])->assertStatus(201);

        $this->source66->refresh();

        // Create unverified evidence for claim 2
        $ev2 = CulturalClaimSource::create([
            'calendar_event_id' => $this->event66->id,
            'cultural_claim_id' => $this->claim2->id,
            'event_source_id' => $this->source66->id,
            'citation_locator' => 'Глава 4, стр. 112',
            'citation_excerpt' => 'Берестяные туеса изготавливались мастерами для хранения напитков.',
            'verification_status' => 'unverified',
        ]);

        // Separately attest claim 1 with verify=true
        $response = $this->actingAs($this->curator66)
            ->postJson("/api/v1/cultural-claims/{$this->claim1->id}/evidence", [
                'event_source_id' => $this->source66->id,
                'citation_locator' => 'Глава 2, стр. 45-48',
                'citation_excerpt' => 'Роспись изб на Среднем Урале документально фиксируется с первой трети XVIII века.',
                'verify' => true,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.verification_status', 'verified_authentic')
            ->assertJsonPath('data.effective_status', 'verified_authentic')
            ->assertJsonPath('data.verified_by.id', $this->curator66->id);

        $ev2->refresh();
        $this->assertEquals('unverified', $ev2->verification_status);
        $this->assertNull($ev2->verified_by_user_id);
    }

    /**
     * Mandatory Test C:
     * Источник одобрен, но цитата не подтверждает заявленный факт (placeholder/generic):
     * одна лишь рецензия источника не даёт verified_authentic, привязка с плейсхолдером отклоняется.
     */
    public function test_mandatory_c_source_approved_generic_placeholder_citation_excerpt_rejected(): void
    {
        $this->actingAs($this->curator66)
            ->postJson("/api/v1/event-sources/{$this->source66->id}/reviews", [
                'decision' => 'approved',
                'comments' => 'Источник проверен и одобрен.',
            ])->assertStatus(201);

        // Attempt to attest claim with placeholder excerpt
        $response = $this->actingAs($this->curator66)
            ->postJson("/api/v1/cultural-claims/{$this->claim1->id}/evidence", [
                'event_source_id' => $this->source66->id,
                'citation_locator' => 'стр. 1',
                'citation_excerpt' => 'см. источник',
                'verify' => true,
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');

        $fields = collect($response->json('error.details'))->pluck('field')->all();
        $this->assertContains('evidence_excerpt', $fields);
    }

    /**
     * Helper to create a fully prepared signed-off scenario with compiled PDF artifact.
     */
    protected function createScenarioWithReadyExport(): array
    {
        $req = ScenarioRequest::create([
            'school_id' => $this->school->id,
            'user_id' => $this->teacher->id,
            'calendar_event_id' => $this->event66->id,
            'scale' => 'class_level',
            'format_type' => 'class_hour',
            'target_grades' => [6],
            'participants_count' => 25,
            'duration_minutes' => 45,
            'value_direction' => 'cultural_diversity',
            'family_involvement' => false,
            'context_snapshot' => ['school' => ['id' => $this->school->id]],
        ]);

        $scenario = Scenario::create([
            'school_id' => $this->school->id,
            'user_id' => $this->teacher->id,
            'scenario_request_id' => $req->id,
            'title' => 'Урок краеведения: Уральские промыслы',
            'is_archived' => false,
        ]);

        $revision = ScenarioRevision::create([
            'scenario_id' => $scenario->id,
            'version_number' => 1,
            'created_by_user_id' => $this->teacher->id,
            'concept' => 'Концепция сценария краеведения.',
            'pedagogical_goal' => 'Изучение культурного наследия Урала.',
            'pedagogical_mechanism' => ['mechanism_type' => 'comparative_cultural_study'],
            'pedagogical_tasks' => ['personal' => ['Уважение к культуре региона']],
            'expected_outcomes' => ['knowledge' => ['Традиционные промыслы']],
            'target_audience' => ['age_group' => 'basic_5_9'],
            'format' => ['scale' => 'class_level'],
            'total_duration_minutes' => 45,
            'teacher_instructions' => ['safety' => 'Инструктаж'],
            'content_payload' => [
                'concept' => 'Концепция',
                'pedagogical_goal' => 'Цель',
                'stages' => [
                    [
                        'id' => 'stage_1',
                        'order_index' => 1,
                        'title' => 'Вводный этап',
                        'stage_type' => 'motivation_introduction',
                        'duration_minutes' => 45,
                    ],
                ],
                'cultural_claims' => [
                    [
                        'claim_id' => $this->claim1->id,
                        'claim_text' => $this->claim1->claim_text,
                        'culture_name' => $this->claim1->culture_name,
                        'server_verification_status' => 'verified_authentic',
                        'evidence_records' => [
                            [
                                'event_source_id' => $this->source66->id,
                                'citation_locator' => 'Глава 2, стр. 45-48',
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $scenario->update(['current_revision_id' => $revision->id]);

        $fingerprint = app(\App\Services\ProvenanceVerificationService::class)->computeRevisionProvenanceFingerprint($revision);

        $revision->moderationState()->create([
            'status' => 'verification_passed',
            'provenance_fingerprint' => $fingerprint,
            'p0_violations_count' => 0,
            'p1_violations_count' => 0,
            'checked_at' => now(),
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
            'teacher_notes' => 'Сценарий согласован к проведению.',
            'confirmed_at' => now(),
        ]);

        $fakePdfContent = "%PDF-1.4 Fake compiled PDF binary content for testing provenance gates.";
        $fakePath = "exports/scenario_{$scenario->id}_v1.pdf";
        Storage::disk('local')->put($fakePath, $fakePdfContent);

        $job = ExportJob::create([
            'scenario_id' => $scenario->id,
            'revision_id' => $revision->id,
            'user_id' => $this->teacher->id,
            'format' => 'pdf',
            'idempotency_key' => (string) Str::uuid(),
            'status' => 'ready',
            'provenance_fingerprint' => $fingerprint,
        ]);

        $artifact = ExportedArtifact::create([
            'export_job_id' => $job->id,
            'revision_id' => $revision->id,
            'artifact_type' => 'scenario_document',
            'file_disk' => 'local',
            'file_path' => $fakePath,
            'file_name' => "scenario_v1.pdf",
            'file_size_bytes' => strlen($fakePdfContent),
            'mime_type' => 'application/pdf',
            'sha256_hash' => hash('sha256', $fakePdfContent),
            'expires_at' => now()->addDays(30),
        ]);

        $job->update(['artifact_id' => $artifact->id]);

        return compact('scenario', 'revision', 'job', 'artifact');
    }

    /**
     * Mandatory Test D:
     * Источник отозван после sign-off и готового PDF: скачивание блокируется.
     */
    public function test_mandatory_d_source_rejected_after_signoff_and_ready_pdf_blocks_download(): void
    {
        // 1. Approve source
        $this->source66->update([
            'verified_by_user_id' => $this->curator66->id,
            'verification_date' => now()->toDateString(),
        ]);
        SourceReview::create([
            'event_source_id' => $this->source66->id,
            'reviewer_user_id' => $this->curator66->id,
            'decision' => 'approved',
            'comments' => 'Первоначальное одобрение источника.',
            'reviewed_at' => now(),
        ]);

        // 2. Attest claim
        CulturalClaimSource::create([
            'calendar_event_id' => $this->event66->id,
            'cultural_claim_id' => $this->claim1->id,
            'event_source_id' => $this->source66->id,
            'citation_locator' => 'Глава 2, стр. 45-48',
            'citation_excerpt' => 'Роспись изб на Среднем Урале документально фиксируется с первой трети XVIII века.',
            'verification_status' => 'verified_authentic',
            'verified_by_user_id' => $this->curator66->id,
            'verified_at' => now(),
        ]);

        $bundle = $this->createScenarioWithReadyExport();
        $job = $bundle['job'];

        // 3. Verify PDF is initially downloadable
        $resOk = $this->actingAs($this->teacher)
            ->withHeader('X-School-Id', $this->school->id)
            ->get("/api/v1/export-jobs/{$job->id}/download");

        $resOk->assertStatus(200);

        // 4. Curator revokes/rejects source
        $this->actingAs($this->curator66)
            ->postJson("/api/v1/event-sources/{$this->source66->id}/reviews", [
                'decision' => 'rejected',
                'comments' => 'При повторной экспертизе выявлена фальсификация архивных сносок. Источник аннулирован.',
            ])->assertStatus(201);

        // 5. Subsequent download MUST be blocked by Provenance Gate!
        $resBlocked = $this->actingAs($this->teacher)
            ->withHeader('X-School-Id', $this->school->id)
            ->get("/api/v1/export-jobs/{$job->id}/download");

        $resBlocked->assertStatus(403)
            ->assertJsonPath('error.code', 'EXPORT_BLOCKED_BY_PROVENANCE_REVOCATION');
    }

    /**
     * Negative Test (P0 requirement 2):
     * Утверждение прошло модерацию, sign-off и PDF ready.
     * Куратор заменяет URL и название ранее одобренного первоисточника.
     * Старый PDF должен перестать скачиваться как подтверждённый.
     */
    public function test_negative_p0_changing_url_or_title_of_approved_source_blocks_old_pdf_download(): void
    {
        // 1. Initial valid approval
        $this->source66->update([
            'verified_by_user_id' => $this->curator66->id,
            'verification_date' => now()->toDateString(),
        ]);
        SourceReview::create([
            'event_source_id' => $this->source66->id,
            'reviewer_user_id' => $this->curator66->id,
            'decision' => 'approved',
            'comments' => 'Первоначальное одобрение.',
            'reviewed_at' => now(),
        ]);

        CulturalClaimSource::create([
            'calendar_event_id' => $this->event66->id,
            'cultural_claim_id' => $this->claim1->id,
            'event_source_id' => $this->source66->id,
            'citation_locator' => 'Глава 2, стр. 45-48',
            'citation_excerpt' => 'Роспись изб на Среднем Урале документально фиксируется с первой трети XVIII века.',
            'verification_status' => 'verified_authentic',
            'verified_by_user_id' => $this->curator66->id,
            'verified_at' => now(),
        ]);

        $bundle = $this->createScenarioWithReadyExport();
        $job = $bundle['job'];

        // Initial download succeeds
        $this->actingAs($this->teacher)
            ->withHeader('X-School-Id', $this->school->id)
            ->get("/api/v1/export-jobs/{$job->id}/download")
            ->assertStatus(200);

        // 2. Curator modifies URL and title of the source
        $patchRes = $this->actingAs($this->curator66)
            ->patchJson("/api/v1/event-sources/{$this->source66->id}", [
                'title' => 'Новая редакция: Этнография Урала (измененная версия)',
                'url' => 'https://ural-ethnography.ru/new_changed_edition.pdf',
            ]);

        $patchRes->assertStatus(200);

        // Verify DB mutations
        $this->source66->refresh();
        $this->assertNull($this->source66->verified_by_user_id, 'Source verifier must be cleared upon content modification');
        $this->assertNull($this->source66->verification_date);

        // Verify system audit review was created
        $this->assertDatabaseHas('source_reviews', [
            'event_source_id' => $this->source66->id,
            'decision' => 'requires_amendment',
        ]);

        // Verify linked claim was reset to unverified
        $this->assertDatabaseHas('cultural_claim_sources', [
            'event_source_id' => $this->source66->id,
            'cultural_claim_id' => $this->claim1->id,
            'verification_status' => 'unverified',
            'verified_by_user_id' => null,
        ]);

        // 3. Attempting to download previously generated PDF MUST be blocked!
        $downloadBlocked = $this->actingAs($this->teacher)
            ->withHeader('X-School-Id', $this->school->id)
            ->get("/api/v1/export-jobs/{$job->id}/download");

        $downloadBlocked->assertStatus(403)
            ->assertJsonPath('error.code', 'EXPORT_BLOCKED_BY_PROVENANCE_REVOCATION');

        // 4. If curator now re-approves the source, claim does NOT auto-restore, so PDF remains blocked
        $this->actingAs($this->curator66)
            ->postJson("/api/v1/event-sources/{$this->source66->id}/reviews", [
                'decision' => 'approved',
                'comments' => 'Новая редакция проверена.',
            ])->assertStatus(201);

        $downloadStillBlocked = $this->actingAs($this->teacher)
            ->withHeader('X-School-Id', $this->school->id)
            ->get("/api/v1/export-jobs/{$job->id}/download");

        $downloadStillBlocked->assertStatus(403)
            ->assertJsonPath('error.code', 'EXPORT_BLOCKED_BY_PROVENANCE_REVOCATION');
    }

    /**
     * P1 Test: Sequence requires_amendment -> approved deterministic queue filtering.
     */
    public function test_p1_sequence_requires_amendment_then_approved_queue_filtering(): void
    {
        // 1. Initial review: requires_amendment
        SourceReview::create([
            'event_source_id' => $this->source66->id,
            'reviewer_user_id' => $this->curator66->id,
            'decision' => 'requires_amendment',
            'comments' => 'Уточнить выходные данные издания.',
            'reviewed_at' => Carbon::parse('2026-10-22 10:00:00'),
        ]);

        // Query pending queue: MUST contain source
        $resPending1 = $this->actingAs($this->curator66)
            ->getJson('/api/v1/catalog/review-queue?status=pending');
        $resPending1->assertStatus(200);
        $ids1 = collect($resPending1->json('data'))->pluck('id')->all();
        $this->assertContains($this->source66->id, $ids1);

        // Query approved queue: MUST NOT contain source
        $resApproved1 = $this->actingAs($this->curator66)
            ->getJson('/api/v1/catalog/review-queue?status=approved');
        $resApproved1->assertStatus(200);
        $idsApproved1 = collect($resApproved1->json('data'))->pluck('id')->all();
        $this->assertNotContains($this->source66->id, $idsApproved1);

        // 2. Later review: approved (reviewed_at is later)
        SourceReview::create([
            'event_source_id' => $this->source66->id,
            'reviewer_user_id' => $this->curator66->id,
            'decision' => 'approved',
            'comments' => 'Выходные данные уточнены, источник утвержден.',
            'reviewed_at' => Carbon::parse('2026-10-22 12:00:00'),
        ]);
        $this->source66->update([
            'verified_by_user_id' => $this->curator66->id,
            'verification_date' => '2026-10-22',
        ]);

        // Query pending queue: MUST NO LONGER contain source
        $resPending2 = $this->actingAs($this->curator66)
            ->getJson('/api/v1/catalog/review-queue?status=pending');
        $resPending2->assertStatus(200);
        $ids2 = collect($resPending2->json('data'))->pluck('id')->all();
        $this->assertNotContains($this->source66->id, $ids2);

        // Query approved queue: MUST contain source
        $resApproved2 = $this->actingAs($this->curator66)
            ->getJson('/api/v1/catalog/review-queue?status=approved');
        $resApproved2->assertStatus(200);
        $idsApproved2 = collect($resApproved2->json('data'))->pluck('id')->all();
        $this->assertContains($this->source66->id, $idsApproved2);
    }

    /**
     * P1 Test: Regional curator scope prevents viewing reviews outside zone.
     */
    public function test_p1_regional_curator_cannot_list_reviews_outside_curator_scope(): void
    {
        SourceReview::create([
            'event_source_id' => $this->source66->id,
            'reviewer_user_id' => $this->curator66->id,
            'decision' => 'approved',
            'comments' => 'Одобрено куратором региона 66.',
            'reviewed_at' => now(),
        ]);

        // Curator of region 77 tries to view reviews of source in region 66
        $resForbidden = $this->actingAs($this->curator77)
            ->getJson("/api/v1/event-sources/{$this->source66->id}/reviews");

        $resForbidden->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN_REGIONAL_SCOPE');

        // System admin CAN view reviews of all regions
        $resAdmin = $this->actingAs($this->systemAdmin)
            ->getJson("/api/v1/event-sources/{$this->source66->id}/reviews");

        $resAdmin->assertStatus(200);
        $this->assertCount(1, $resAdmin->json('data'));
    }

    /**
     * P1 Test: Detaching evidence preserves audit trail and blocks export.
     */
    public function test_p1_detaching_evidence_preserves_audit_history_and_blocks_export(): void
    {
        $evidence = CulturalClaimSource::create([
            'calendar_event_id' => $this->event66->id,
            'cultural_claim_id' => $this->claim1->id,
            'event_source_id' => $this->source66->id,
            'citation_locator' => 'Глава 2, стр. 45-48',
            'citation_excerpt' => 'Роспись изб на Среднем Урале документально фиксируется с первой трети XVIII века.',
            'verification_status' => 'verified_authentic',
            'verified_by_user_id' => $this->curator66->id,
            'verified_at' => now(),
        ]);

        $response = $this->actingAs($this->curator66)
            ->deleteJson("/api/v1/cultural-claims/{$this->claim1->id}/evidence/{$this->source66->id}", [
                'reason' => 'Устаревшее издание, ссылка заменена на академическое собрание сочинений.',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.message', 'Свидетельство первоисточника успешно отвязано от утверждения.');

        // Record MUST NOT be erased from database!
        $this->assertDatabaseHas('cultural_claim_sources', [
            'id' => $evidence->id,
            'is_detached' => true,
            'detached_by_user_id' => $this->curator66->id,
            'detachment_reason' => 'Устаревшее издание, ссылка заменена на академическое собрание сочинений.',
            'previous_verification_status' => 'verified_authentic',
            'verification_status' => 'unverified',
        ]);

        $evidence->refresh();
        $this->assertEquals('unverified', app(ProvenanceVerificationService::class)->evaluateStatus($evidence));
    }

    /**
     * P1 Test: Scenario status is completed (not draft) when signed off.
     */
    public function test_p1_scenario_status_completed_not_draft_when_signed_off(): void
    {
        $bundle = $this->createScenarioWithReadyExport();
        $scenario = $bundle['scenario'];

        // 1. GET /scenarios
        $resList = $this->actingAs($this->teacher)
            ->withHeader('X-School-Id', $this->school->id)
            ->getJson('/api/v1/scenarios');

        $resList->assertStatus(200);
        $item = collect($resList->json('data'))->firstWhere('id', $scenario->id);
        $this->assertNotNull($item);
        $this->assertEquals('completed', $item['status'], 'Signed-off scenario must have status=completed in list');

        // 2. GET /scenarios/{id}
        $resDetail = $this->actingAs($this->teacher)
            ->withHeader('X-School-Id', $this->school->id)
            ->getJson("/api/v1/scenarios/{$scenario->id}");

        $resDetail->assertStatus(200)
            ->assertJsonPath('data.status', 'completed');

        // 3. GET /dashboard/summary
        $resDash = $this->actingAs($this->teacher)
            ->withHeader('X-School-Id', $this->school->id)
            ->getJson('/api/v1/dashboard/summary');

        $resDash->assertStatus(200);
        $dashItem = collect($resDash->json('data.recent_scenarios'))->firstWhere('id', $scenario->id);
        $this->assertNotNull($dashItem);
        $this->assertEquals('completed', $dashItem['status']);
    }

    /**
     * P1 Test: Date range 'to' includes the entire calendar day (up to 23:59:59).
     */
    public function test_p1_date_range_to_includes_entire_calendar_day(): void
    {
        $todayStr = now()->format('Y-m-d');

        $req = ScenarioRequest::create([
            'school_id' => $this->school->id,
            'user_id' => $this->teacher->id,
            'calendar_event_id' => $this->event66->id,
            'scale' => 'class_level',
            'format_type' => 'class_hour',
            'target_grades' => [7],
            'participants_count' => 20,
            'duration_minutes' => 45,
            'value_direction' => 'cultural_diversity',
            'family_involvement' => false,
            'context_snapshot' => [],
        ]);

        $lateScenario = Scenario::create([
            'school_id' => $this->school->id,
            'user_id' => $this->teacher->id,
            'scenario_request_id' => $req->id,
            'title' => 'Поздний вечерний сценарий дня',
            'is_archived' => false,
            'created_at' => Carbon::parse("{$todayStr} 23:45:00"),
        ]);

        // Query with ?to=YYYY-MM-DD
        $response = $this->actingAs($this->teacher)
            ->withHeader('X-School-Id', $this->school->id)
            ->getJson("/api/v1/scenarios?to={$todayStr}");

        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($lateScenario->id, $ids, 'Date range to=YYYY-MM-DD must include items created at late evening');
    }

    /**
     * P1 Test: Upcoming calendar events take into account current date and cross-year wraparound.
     */
    public function test_p1_upcoming_calendar_events_cross_year_ordering(): void
    {
        // Clean existing events for clarity
        CalendarEvent::where('school_id', $this->school->id)->delete();

        $curMonth = (int) now()->format('n');
        $curDay = (int) now()->format('j');

        // Create 3 events:
        // Event 1: in 2 months (current year)
        $m1 = ($curMonth % 12) + 1;
        $evUpcoming = CalendarEvent::create([
            'school_id' => $this->school->id,
            'title' => 'Ближайшее событие года',
            'description' => 'Описание ближайшего события',
            'event_type' => 'school_custom',
            'date_type' => 'fixed_annual',
            'fixed_month' => $m1,
            'fixed_day' => 15,
            'target_age_groups' => ['basic_5_9'],
            'recommended_value_directions' => ['culture_and_traditions'],
            'status' => 'approved',
        ]);

        // Event 2: occurred 2 months ago (next occurrence is next year)
        $m2 = (($curMonth - 3 + 12) % 12) + 1;
        $evPast = CalendarEvent::create([
            'school_id' => $this->school->id,
            'title' => 'Прошедшее в этом году событие',
            'description' => 'Описание прошедшего события',
            'event_type' => 'school_custom',
            'date_type' => 'fixed_annual',
            'fixed_month' => $m2,
            'fixed_day' => 10,
            'target_age_groups' => ['basic_5_9'],
            'recommended_value_directions' => ['culture_and_traditions'],
            'status' => 'approved',
        ]);

        $response = $this->actingAs($this->teacher)
            ->withHeader('X-School-Id', $this->school->id)
            ->getJson('/api/v1/dashboard/summary');

        $response->assertStatus(200);
        $upcoming = $response->json('data.upcoming_events');
        $this->assertNotEmpty($upcoming);

        // If both exist in top results, evUpcoming MUST come before evPast
        $idxUpcoming = null;
        $idxPast = null;
        foreach ($upcoming as $i => $ev) {
            if ($ev['id'] === $evUpcoming->id) {
                $idxUpcoming = $i;
            }
            if ($ev['id'] === $evPast->id) {
                $idxPast = $i;
            }
        }

        if ($idxUpcoming !== null && $idxPast !== null) {
            $this->assertLessThan($idxPast, $idxUpcoming, 'Events remaining in the current calendar year must appear before events that already passed');
        }
    }
}
