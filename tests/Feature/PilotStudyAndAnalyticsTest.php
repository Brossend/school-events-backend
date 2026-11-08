<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CalendarEvent;
use App\Models\PilotParticipant;
use App\Models\PilotResponse;
use App\Models\PilotReviewFinding;
use App\Models\Scenario;
use App\Models\ScenarioComparison;
use App\Models\ScenarioRequest;
use App\Models\ScenarioRevision;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Services\PilotService;
use App\Services\ScenarioComparisonService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PilotStudyAndAnalyticsTest extends TestCase
{
    use DatabaseTransactions;

    protected User $admin;
    protected User $curator;
    protected User $otherRegionCurator;
    protected User $schoolAdmin;
    protected User $otherSchoolAdmin;
    protected User $teacher;
    protected School $school;
    protected School $otherSchool;
    protected Scenario $scenario;
    protected Scenario $otherScenario;
    protected ScenarioRequest $scenarioRequest;

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
            'short_name' => 'Гимназия 9',
            'region_code' => '66',
            'city' => 'Екатеринбург',
            'inn' => '6670054321',
            'settings' => [],
        ]);

        $this->admin = User::factory()->create([
            'system_role' => 'system_admin',
        ]);

        $this->curator = User::factory()->create([
            'system_role' => 'catalog_curator',
            'curator_scope' => 'regional',
            'curated_region_codes' => ['66'],
        ]);

        $this->otherRegionCurator = User::factory()->create([
            'system_role' => 'catalog_curator',
            'curator_scope' => 'regional',
            'curated_region_codes' => ['77'],
        ]);

        $this->schoolAdmin = User::factory()->create();
        SchoolMembership::create([
            'school_id' => $this->school->id,
            'user_id' => $this->schoolAdmin->id,
            'role' => 'school_admin',
            'is_default' => true,
        ]);

        $this->otherSchoolAdmin = User::factory()->create();
        SchoolMembership::create([
            'school_id' => $this->otherSchool->id,
            'user_id' => $this->otherSchoolAdmin->id,
            'role' => 'school_admin',
            'is_default' => true,
        ]);

        $this->teacher = User::factory()->create();
        SchoolMembership::create([
            'school_id' => $this->school->id,
            'user_id' => $this->teacher->id,
            'role' => 'teacher',
            'is_default' => true,
        ]);

        $event = CalendarEvent::create([
            'title' => 'День знаний',
            'event_type' => 'federal',
            'date_type' => 'fixed_annual',
            'fixed_month' => 9,
            'fixed_day' => 1,
            'description' => '1 сентября',
            'target_age_groups' => ['basic_5_9'],
            'recommended_value_directions' => ['labor_and_creation'],
            'status' => 'approved',
        ]);

        $this->scenarioRequest = ScenarioRequest::create([
            'school_id' => $this->school->id,
            'user_id' => $this->teacher->id,
            'calendar_event_id' => $event->id,
            'scale' => 'class_level',
            'format_type' => 'class_hour',
            'target_grades' => [5],
            'participants_count' => 25,
            'duration_minutes' => 45,
            'value_direction' => 'labor_and_creation',
            'family_involvement' => false,
            'available_resources' => ['Доска'],
            'context_snapshot' => ['occasion' => ['title' => 'День знаний']],
            'created_at' => now(),
        ]);

        $this->scenario = Scenario::create([
            'school_id' => $this->school->id,
            'user_id' => $this->teacher->id,
            'scenario_request_id' => $this->scenarioRequest->id,
            'title' => 'Сценарий классного часа',
            'optimistic_lock_version' => 1,
            'is_archived' => false,
        ]);

        $rev = ScenarioRevision::create([
            'scenario_id' => $this->scenario->id,
            'version_number' => 1,
            'source_generation_run_id' => null,
            'created_by_user_id' => $this->teacher->id,
            'concept' => 'Концепция',
            'pedagogical_goal' => 'Цель',
            'pedagogical_mechanism' => ['type' => 'collaborative_creative_product', 'title' => 'Продукт'],
            'pedagogical_tasks' => ['personal' => [], 'meta_subject' => [], 'subject_specific' => []],
            'expected_outcomes' => ['knowledge' => [], 'skills' => [], 'values' => []],
            'target_audience' => ['age_group' => 'basic_5_9'],
            'format' => ['type' => 'class_hour', 'total_duration_minutes' => 45],
            'total_duration_minutes' => 45,
            'content_payload' => ['concept' => 'Концепция'],
            'teacher_instructions' => [],
            'created_at' => now(),
        ]);

        $this->scenario->current_revision_id = $rev->id;
        $this->scenario->save();

        // Other school scenario
        $otherReq = ScenarioRequest::create([
            'school_id' => $this->otherSchool->id,
            'user_id' => $this->otherSchoolAdmin->id,
            'calendar_event_id' => $event->id,
            'scale' => 'class_level',
            'format_type' => 'class_hour',
            'target_grades' => [5],
            'participants_count' => 20,
            'duration_minutes' => 45,
            'value_direction' => 'labor_and_creation',
            'family_involvement' => false,
            'available_resources' => [],
            'context_snapshot' => ['occasion' => ['title' => 'День знаний']],
            'created_at' => now(),
        ]);

        $this->otherScenario = Scenario::create([
            'school_id' => $this->otherSchool->id,
            'user_id' => $this->otherSchoolAdmin->id,
            'scenario_request_id' => $otherReq->id,
            'title' => 'Чужой сценарий',
            'optimistic_lock_version' => 1,
            'is_archived' => false,
        ]);
    }

    public function test_can_create_pilot_participant_invite_and_verify_token_hashing(): void
    {
        $res = $this->actingAs($this->schoolAdmin)
            ->withHeader('X-School-Id', $this->school->id)
            ->postJson('/api/v1/pilot/participants', [
                'subject_area' => 'История и обществознание',
                'teaching_experience_years' => 14,
                'valid_days' => 30,
            ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.subject_area', 'История и обществознание')
            ->assertJsonPath('data.teaching_experience_years', 14);

        $plainToken = $res->json('data.invite_token');
        $participantId = $res->json('data.id');
        $this->assertNotEmpty($plainToken);

        $participant = PilotParticipant::find($participantId);
        $this->assertNotNull($participant);
        // Assert plain token is NOT stored in DB, only hash is stored
        $this->assertNotEquals($plainToken, $participant->invite_token_hash);
        $this->assertEquals(hash('sha256', $plainToken), $participant->invite_token_hash);
    }

    public function test_can_submit_pilot_response_with_single_use_token_policy(): void
    {
        $inviteRes = $this->actingAs($this->schoolAdmin)
            ->withHeader('X-School-Id', $this->school->id)
            ->postJson('/api/v1/pilot/participants', [
                'subject_area' => 'Литература',
                'teaching_experience_years' => 8,
            ]);

        $plainToken = $inviteRes->json('data.invite_token');
        $participantId = $inviteRes->json('data.id');

        // Submit response using invite token
        $submitRes = $this->postJson('/api/v1/pilot/responses', [
            'invite_token' => $plainToken,
            'scenario_id' => $this->scenario->id,
            'prep_time_baseline_minutes' => 60,
            'prep_time_ai_minutes' => 20,
            'personalization_selected' => true,
            'manual_edit_percentage' => 15.50,
            'cultural_context_used' => true,
            'cultural_usefulness_score' => 5,
            'feedback_text' => 'Сценарий сэкономил 40 минут подготовки, ролевая модель отличная!',
        ]);

        $submitRes->assertStatus(201)
            ->assertJsonPath('data.prep_time_baseline_minutes', 60)
            ->assertJsonPath('data.prep_time_ai_minutes', 20)
            ->assertJsonPath('data.relative_time_saving', 0.6667)
            ->assertJsonPath('data.relative_time_saving_percent', 66.67)
            ->assertJsonPath('data.personalization_selected', true);

        $participant = PilotParticipant::find($participantId);
        $this->assertTrue($participant->is_submitted);
        $this->assertNotNull($participant->used_at);

        // Second submission with same token MUST return 409 Conflict
        $secondSubmit = $this->postJson('/api/v1/pilot/responses', [
            'invite_token' => $plainToken,
            'scenario_id' => $this->scenario->id,
            'prep_time_baseline_minutes' => 60,
            'prep_time_ai_minutes' => 20,
            'personalization_selected' => false,
            'manual_edit_percentage' => 10,
            'cultural_context_used' => false,
        ]);

        $secondSubmit->assertStatus(409);
    }

    public function test_token_reissue_and_revocation_lifecycle(): void
    {
        $inviteRes = $this->actingAs($this->schoolAdmin)
            ->withHeader('X-School-Id', $this->school->id)
            ->postJson('/api/v1/pilot/participants', [
                'subject_area' => 'Физика',
                'teaching_experience_years' => 5,
            ]);

        $oldToken = $inviteRes->json('data.invite_token');
        $participantId = $inviteRes->json('data.id');

        // Reissue token
        $reissueRes = $this->actingAs($this->schoolAdmin)
            ->withHeader('X-School-Id', $this->school->id)
            ->postJson("/api/v1/pilot/participants/{$participantId}/reissue");

        $reissueRes->assertStatus(200);
        $newToken = $reissueRes->json('data.invite_token');
        $this->assertNotEquals($oldToken, $newToken);

        // Old token cannot be used
        $this->postJson('/api/v1/pilot/responses', [
            'invite_token' => $oldToken,
            'scenario_id' => $this->scenario->id,
            'prep_time_baseline_minutes' => 60,
            'prep_time_ai_minutes' => 30,
            'personalization_selected' => true,
            'manual_edit_percentage' => 10,
            'cultural_context_used' => false,
        ])->assertStatus(404);

        // Revoke participant
        $this->actingAs($this->schoolAdmin)
            ->withHeader('X-School-Id', $this->school->id)
            ->postJson("/api/v1/pilot/participants/{$participantId}/revoke")
            ->assertStatus(200);

        // Revoked token cannot be used
        $this->postJson('/api/v1/pilot/responses', [
            'invite_token' => $newToken,
            'scenario_id' => $this->scenario->id,
            'prep_time_baseline_minutes' => 60,
            'prep_time_ai_minutes' => 30,
            'personalization_selected' => true,
            'manual_edit_percentage' => 10,
            'cultural_context_used' => false,
        ])->assertStatus(403);
    }

    public function test_cannot_submit_response_for_scenario_of_another_school(): void
    {
        $inviteRes = $this->actingAs($this->schoolAdmin)
            ->withHeader('X-School-Id', $this->school->id)
            ->postJson('/api/v1/pilot/participants', [
                'subject_area' => 'Биология',
                'teaching_experience_years' => 3,
            ]);

        $token = $inviteRes->json('data.invite_token');

        // Attempting to evaluate scenario belonging to other school returns 403 Forbidden
        $res = $this->postJson('/api/v1/pilot/responses', [
            'invite_token' => $token,
            'scenario_id' => $this->otherScenario->id,
            'prep_time_baseline_minutes' => 60,
            'prep_time_ai_minutes' => 30,
            'personalization_selected' => true,
            'manual_edit_percentage' => 10,
            'cultural_context_used' => false,
        ]);

        $res->assertStatus(403);
    }

    // MANDATORY TEST A: Cross-school review forbidden for other school admin
    public function test_cross_school_review_forbidden_for_other_school_admin(): void
    {
        $service = app(PilotService::class);
        $invite = $service->createParticipant($this->school, [
            'subject_area' => 'История',
            'teaching_experience_years' => 10,
        ]);

        $response = $service->submitResponse($invite['invite_token'], [
            'scenario_id' => $this->scenario->id,
            'prep_time_baseline_minutes' => 60,
            'prep_time_ai_minutes' => 30,
            'personalization_selected' => true,
            'manual_edit_percentage' => 15.0,
            'cultural_context_used' => false,
        ]);

        $findingsCountBefore = PilotReviewFinding::count();

        // School Admin of School B attempts to review School A's response -> 403 Forbidden
        $res = $this->actingAs($this->otherSchoolAdmin)
            ->withHeader('X-School-Id', $this->otherSchool->id)
            ->postJson("/api/v1/pilot/responses/{$response->id}/reviews", [
                'expert_audit_status' => 'audited',
                'critical_cultural_errors' => 0,
                'methodological_remarks' => 'Неправомерная попытка аудита чужой школы',
            ]);

        $res->assertStatus(403);
        $this->assertEquals($findingsCountBefore, PilotReviewFinding::count(), 'Zero review findings must be created on cross-school attempt');
    }

    // MANDATORY TEST B: Teacher cannot manage pilot participants (403)
    public function test_teacher_cannot_manage_pilot_participants(): void
    {
        // 1. List participants
        $this->actingAs($this->teacher)
            ->withHeader('X-School-Id', $this->school->id)
            ->getJson('/api/v1/pilot/participants')
            ->assertStatus(403);

        // 2. Create participant
        $this->actingAs($this->teacher)
            ->withHeader('X-School-Id', $this->school->id)
            ->postJson('/api/v1/pilot/participants', [
                'subject_area' => 'География',
                'teaching_experience_years' => 5,
            ])
            ->assertStatus(403);

        // Create participant as admin for reissue/revoke tests
        $service = app(PilotService::class);
        $invite = $service->createParticipant($this->school, [
            'subject_area' => 'География',
            'teaching_experience_years' => 5,
        ]);
        $partId = $invite['participant']->id;

        // 3. Reissue token
        $this->actingAs($this->teacher)
            ->withHeader('X-School-Id', $this->school->id)
            ->postJson("/api/v1/pilot/participants/{$partId}/reissue")
            ->assertStatus(403);

        // 4. Revoke token
        $this->actingAs($this->teacher)
            ->withHeader('X-School-Id', $this->school->id)
            ->postJson("/api/v1/pilot/participants/{$partId}/revoke")
            ->assertStatus(403);
    }

    // MANDATORY TEST C: Teacher cannot read individual responses or export, but can view aggregate metrics
    public function test_teacher_cannot_read_individual_responses_or_export(): void
    {
        $service = app(PilotService::class);
        $invite = $service->createParticipant($this->school, [
            'subject_area' => 'Химия',
            'teaching_experience_years' => 7,
        ]);

        $response = $service->submitResponse($invite['invite_token'], [
            'scenario_id' => $this->scenario->id,
            'prep_time_baseline_minutes' => 90,
            'prep_time_ai_minutes' => 45,
            'personalization_selected' => true,
            'manual_edit_percentage' => 10.0,
            'cultural_context_used' => false,
        ]);

        // GET individual response -> 403 Forbidden
        $this->actingAs($this->teacher)
            ->withHeader('X-School-Id', $this->school->id)
            ->getJson("/api/v1/pilot/responses/{$response->id}")
            ->assertStatus(403);

        // GET export -> 403 Forbidden
        $this->actingAs($this->teacher)
            ->withHeader('X-School-Id', $this->school->id)
            ->getJson('/api/v1/pilot/export')
            ->assertStatus(403);

        // GET aggregate summary -> 200 OK (Allowed for teachers)
        $this->actingAs($this->teacher)
            ->withHeader('X-School-Id', $this->school->id)
            ->getJson('/api/v1/pilot/metrics')
            ->assertStatus(200)
            ->assertJsonPath('data.total_participants', 1);
    }

    // MANDATORY TEST D: Review history append-only versioning
    public function test_pilot_review_finding_append_only_history(): void
    {
        $service = app(PilotService::class);
        $invite = $service->createParticipant($this->school, [
            'subject_area' => 'Краеведение',
            'teaching_experience_years' => 12,
        ]);

        $response = $service->submitResponse($invite['invite_token'], [
            'scenario_id' => $this->scenario->id,
            'prep_time_baseline_minutes' => 60,
            'prep_time_ai_minutes' => 20,
            'personalization_selected' => true,
            'manual_edit_percentage' => 10.0,
            'cultural_context_used' => true,
            'cultural_usefulness_score' => 5,
        ]);

        // 1. Initial audit finding: 0 critical errors
        $rev1 = $this->actingAs($this->curator)
            ->postJson("/api/v1/pilot/responses/{$response->id}/reviews", [
                'expert_audit_status' => 'audited',
                'critical_cultural_errors' => 0,
                'methodological_remarks' => 'Первичная проверка: ошибок нет',
            ]);

        $rev1->assertStatus(200)
            ->assertJsonPath('data.finding_version', 1)
            ->assertJsonPath('data.is_effective', true)
            ->assertJsonPath('data.critical_cultural_errors', 0);

        // 2. Updated audit finding: 1 critical error found
        $rev2 = $this->actingAs($this->curator)
            ->postJson("/api/v1/pilot/responses/{$response->id}/reviews", [
                'expert_audit_status' => 'audited',
                'critical_cultural_errors' => 1,
                'methodological_remarks' => 'Корректировка: обнаружена фактическая ошибка в датировке',
            ]);

        $rev2->assertStatus(200)
            ->assertJsonPath('data.finding_version', 2)
            ->assertJsonPath('data.is_effective', true)
            ->assertJsonPath('data.critical_cultural_errors', 1);

        // Assert DB state: exactly 2 records exist for this response
        $allFindings = PilotReviewFinding::where('pilot_response_id', $response->id)
            ->orderBy('finding_version')
            ->get();

        $this->assertCount(2, $allFindings);
        $this->assertEquals(1, $allFindings[0]->finding_version);
        $this->assertFalse($allFindings[0]->is_effective, 'Previous finding must be marked not effective');
        $this->assertEquals(0, $allFindings[0]->critical_cultural_errors);

        $this->assertEquals(2, $allFindings[1]->finding_version);
        $this->assertTrue($allFindings[1]->is_effective, 'Latest finding must be effective');
        $this->assertEquals(1, $allFindings[1]->critical_cultural_errors);

        // Assert metrics: audited responses count is NOT doubled (must remain 1)
        $metrics = $service->calculateMetrics($this->school->id, 'school');
        $this->assertEquals(1, $metrics['audited_responses_count'], 'Audited responses count must not be doubled');
        $this->assertEquals(1, $metrics['critical_cultural_errors_count'], 'Effective critical errors must be 1');
    }

    // MANDATORY TEST E: Incomplete audit (5 of 20, 0 errors): does not pass
    public function test_incomplete_audit_5_of_20_does_not_pass_criteria(): void
    {
        $service = app(PilotService::class);
        $responses = [];

        for ($i = 0; $i < 20; $i++) {
            $invite = $service->createParticipant($this->school, [
                'subject_area' => 'Предмет ' . $i,
                'teaching_experience_years' => 5,
            ]);

            $responses[] = $service->submitResponse($invite['invite_token'], [
                'scenario_id' => $this->scenario->id,
                'prep_time_baseline_minutes' => 60,
                'prep_time_ai_minutes' => 25,
                'personalization_selected' => true,
                'manual_edit_percentage' => 10.0,
                'cultural_context_used' => true,
                'cultural_usefulness_score' => 5,
            ]);
        }

        // Audit only 5 of 20 responses with 0 errors
        for ($i = 0; $i < 5; $i++) {
            $service->submitReviewFinding($responses[$i], $this->curator, [
                'expert_audit_status' => 'audited',
                'critical_cultural_errors' => 0,
            ]);
        }

        $metrics = $service->calculateMetrics($this->school->id, 'school');

        $this->assertEquals(20, $metrics['total_participants']);
        $this->assertEquals(5, $metrics['audited_responses_count']);
        $this->assertEquals(15, $metrics['pending_expert_reviews_count']);
        $this->assertEquals(25.0, $metrics['expert_review_coverage_percent']);
        $this->assertSame(0, $metrics['critical_cultural_errors_count']);

        // CRITICAL P0: Incomplete audit MUST NOT pass zero_critical_cultural_errors
        $this->assertFalse($metrics['criteria_progress']['zero_critical_cultural_errors'], 'Criterion must NOT pass when audit is incomplete');
        $this->assertEquals('pending_review', $metrics['criteria_status']['zero_critical_cultural_errors']);
        $this->assertTrue($metrics['is_survey_completed']);
        $this->assertFalse($metrics['is_expert_evaluation_completed']);
        $this->assertFalse($metrics['is_pilot_completed']);
        $this->assertEquals('active_pilot', $metrics['status']);
    }

    // MANDATORY TEST F: Complete audit (20 of 20, 0 errors): passes
    public function test_complete_audit_20_of_20_zero_errors_passes_and_completes_pilot(): void
    {
        $service = app(PilotService::class);
        $responses = [];

        for ($i = 0; $i < 20; $i++) {
            $invite = $service->createParticipant($this->school, [
                'subject_area' => 'Предмет ' . $i,
                'teaching_experience_years' => 5,
            ]);

            $responses[] = $service->submitResponse($invite['invite_token'], [
                'scenario_id' => $this->scenario->id,
                'prep_time_baseline_minutes' => 60,
                'prep_time_ai_minutes' => 25,
                'personalization_selected' => true,
                'manual_edit_percentage' => 10.0,
                'cultural_context_used' => true,
                'cultural_usefulness_score' => 5,
            ]);
        }

        // Audit all 20 responses with 0 errors
        foreach ($responses as $resp) {
            $service->submitReviewFinding($resp, $this->curator, [
                'expert_audit_status' => 'audited',
                'critical_cultural_errors' => 0,
            ]);
        }

        $metrics = $service->calculateMetrics($this->school->id, 'school');

        $this->assertEquals(20, $metrics['total_participants']);
        $this->assertEquals(20, $metrics['audited_responses_count']);
        $this->assertEquals(0, $metrics['pending_expert_reviews_count']);
        $this->assertEquals(100.0, $metrics['expert_review_coverage_percent']);
        $this->assertSame(0, $metrics['critical_cultural_errors_count']);

        // 100% audit with 0 errors passes criterion and completes pilot
        $this->assertTrue($metrics['criteria_progress']['zero_critical_cultural_errors']);
        $this->assertEquals('pass', $metrics['criteria_status']['zero_critical_cultural_errors']);
        $this->assertTrue($metrics['is_survey_completed']);
        $this->assertTrue($metrics['is_expert_evaluation_completed']);
        $this->assertTrue($metrics['is_pilot_completed']);
        $this->assertEquals('completed', $metrics['status']);
    }

    // MANDATORY TEST G: Complete audit with 1 error: fails
    public function test_complete_audit_20_of_20_with_errors_fails_criterion_and_pilot(): void
    {
        $service = app(PilotService::class);
        $responses = [];

        for ($i = 0; $i < 20; $i++) {
            $invite = $service->createParticipant($this->school, [
                'subject_area' => 'Предмет ' . $i,
                'teaching_experience_years' => 5,
            ]);

            $responses[] = $service->submitResponse($invite['invite_token'], [
                'scenario_id' => $this->scenario->id,
                'prep_time_baseline_minutes' => 60,
                'prep_time_ai_minutes' => 25,
                'personalization_selected' => true,
                'manual_edit_percentage' => 10.0,
                'cultural_context_used' => true,
                'cultural_usefulness_score' => 5,
            ]);
        }

        // 19 with 0 errors, 1 with 1 error
        for ($i = 0; $i < 19; $i++) {
            $service->submitReviewFinding($responses[$i], $this->curator, [
                'expert_audit_status' => 'audited',
                'critical_cultural_errors' => 0,
            ]);
        }
        $service->submitReviewFinding($responses[19], $this->curator, [
            'expert_audit_status' => 'audited',
            'critical_cultural_errors' => 1,
        ]);

        $metrics = $service->calculateMetrics($this->school->id, 'school');

        $this->assertEquals(20, $metrics['total_participants']);
        $this->assertEquals(20, $metrics['audited_responses_count']);
        $this->assertEquals(1, $metrics['critical_cultural_errors_count']);

        // Fails zero_critical_cultural_errors and blocks pilot completion
        $this->assertFalse($metrics['criteria_progress']['zero_critical_cultural_errors']);
        $this->assertEquals('fail', $metrics['criteria_status']['zero_critical_cultural_errors']);
        $this->assertTrue($metrics['is_survey_completed']);
        $this->assertTrue($metrics['is_expert_evaluation_completed']);
        $this->assertFalse($metrics['is_pilot_completed'], 'Pilot must not be completed when critical errors exist');
        $this->assertEquals('active_pilot', $metrics['status']);
    }

    // MANDATORY TEST H: Synthetic data separation
    public function test_synthetic_responses_excluded_by_default_and_included_with_flag(): void
    {
        $service = app(PilotService::class);

        // 1 Real response
        $realInvite = $service->createParticipant($this->school, [
            'subject_area' => 'Реальный предмет',
            'teaching_experience_years' => 10,
            'is_synthetic' => false,
        ]);
        $service->submitResponse($realInvite['invite_token'], [
            'scenario_id' => $this->scenario->id,
            'prep_time_baseline_minutes' => 60,
            'prep_time_ai_minutes' => 30,
            'personalization_selected' => true,
            'manual_edit_percentage' => 10.0,
            'cultural_context_used' => false,
            'is_synthetic' => false,
        ]);

        // 2 Synthetic responses
        for ($s = 1; $s <= 2; $s++) {
            $synthInvite = $service->createParticipant($this->school, [
                'subject_area' => 'Синтетический предмет ' . $s,
                'teaching_experience_years' => 5,
                'is_synthetic' => true,
            ]);
            $service->submitResponse($synthInvite['invite_token'], [
                'scenario_id' => $this->scenario->id,
                'prep_time_baseline_minutes' => 60,
                'prep_time_ai_minutes' => 20,
                'personalization_selected' => true,
                'manual_edit_percentage' => 5.0,
                'cultural_context_used' => false,
                'is_synthetic' => true,
            ]);
        }

        // By default: synthetic is excluded -> total = 1
        $metricsReal = $service->calculateMetrics($this->school->id, 'school', false);
        $this->assertEquals(1, $metricsReal['total_participants']);

        // With includeSynthetic = true -> total = 3
        $metricsAll = $service->calculateMetrics($this->school->id, 'school', true);
        $this->assertEquals(3, $metricsAll['total_participants']);

        // Test via HTTP endpoint
        $httpReal = $this->actingAs($this->schoolAdmin)
            ->withHeader('X-School-Id', $this->school->id)
            ->getJson('/api/v1/pilot/metrics?scope=school');
        $httpReal->assertStatus(200)
            ->assertJsonPath('data.total_participants', 1);

        $httpSynthetic = $this->actingAs($this->schoolAdmin)
            ->withHeader('X-School-Id', $this->school->id)
            ->getJson('/api/v1/pilot/metrics?scope=school&include_synthetic=1');
        $httpSynthetic->assertStatus(200)
            ->assertJsonPath('data.total_participants', 3);
    }

    // MANDATORY TEST I: Atomic concurrent selection rejects repeated choice with 409 Conflict
    public function test_atomic_concurrent_selection_rejects_second_choice_with_409(): void
    {
        $compService = app(ScenarioComparisonService::class);
        $comparison = $compService->createComparison(
            $this->school,
            $this->teacher,
            $this->scenarioRequest,
            'universal_vs_personalized'
        );

        // First selection succeeds
        $res1 = $this->actingAs($this->teacher)
            ->withHeader('X-School-Id', $this->school->id)
            ->postJson("/api/v1/scenario-comparisons/{$comparison->id}/selection", [
                'selected_variant' => 'B',
                'selection_reason' => 'Персонализированный сценарий предпочтительнее',
                'cultural_usefulness_rating' => 5,
            ]);

        $res1->assertStatus(200)
            ->assertJsonPath('data.selected_variant', 'B')
            ->assertJsonPath('data.selected_by_user_id', $this->teacher->id);

        // Second selection attempt on same comparison MUST fail with 409 Conflict
        $res2 = $this->actingAs($this->teacher)
            ->withHeader('X-School-Id', $this->school->id)
            ->postJson("/api/v1/scenario-comparisons/{$comparison->id}/selection", [
                'selected_variant' => 'A',
                'selection_reason' => 'Попытка повторного изменения выбора',
            ]);

        $res2->assertStatus(409);

        // Assert record remained intact
        $freshComp = ScenarioComparison::find($comparison->id);
        $this->assertEquals('B', $freshComp->selected_variant);
        $this->assertEquals($this->teacher->id, $freshComp->selected_by_user_id);
    }

    // MANDATORY TEST J: Regional curator RBAC enforcement
    public function test_regional_curator_rbac_enforcement(): void
    {
        $service = app(PilotService::class);
        $invite = $service->createParticipant($this->school, [
            'subject_area' => 'Краеведение',
            'teaching_experience_years' => 15,
        ]);

        $response = $service->submitResponse($invite['invite_token'], [
            'scenario_id' => $this->scenario->id,
            'prep_time_baseline_minutes' => 60,
            'prep_time_ai_minutes' => 30,
            'personalization_selected' => true,
            'manual_edit_percentage' => 10.0,
            'cultural_context_used' => true,
            'cultural_usefulness_score' => 5,
        ]);

        // Curator of region 77 attempts to audit school in region 66 -> 403 Forbidden
        $this->actingAs($this->otherRegionCurator)
            ->postJson("/api/v1/pilot/responses/{$response->id}/reviews", [
                'expert_audit_status' => 'audited',
                'critical_cultural_errors' => 0,
            ])
            ->assertStatus(403);

        // Curator of region 66 audits school in region 66 -> 200 OK
        $this->actingAs($this->curator)
            ->postJson("/api/v1/pilot/responses/{$response->id}/reviews", [
                'expert_audit_status' => 'audited',
                'critical_cultural_errors' => 0,
            ])
            ->assertStatus(200);
    }

    // MANDATORY TEST K: Anonymous client cannot tamper response origin (Scenario A & B)
    public function test_anonymous_client_cannot_tamper_response_synthetic_origin(): void
    {
        $service = app(PilotService::class);

        // 1. Synthetic participant: client attempts to downgrade by passing is_synthetic = false
        $synthInvite = $service->createParticipant($this->school, [
            'subject_area' => 'Информатика',
            'teaching_experience_years' => 4,
            'is_synthetic' => true,
        ]);
        $this->assertTrue($synthInvite['participant']->is_synthetic);

        $res1 = $this->postJson('/api/v1/pilot/responses', [
            'invite_token' => $synthInvite['invite_token'],
            'scenario_id' => $this->scenario->id,
            'prep_time_baseline_minutes' => 60,
            'prep_time_ai_minutes' => 25,
            'personalization_selected' => true,
            'manual_edit_percentage' => 10.0,
            'cultural_context_used' => true,
            'cultural_usefulness_score' => 5,
            'is_synthetic' => false, // Attempted downgrade
        ]);

        $res1->assertStatus(201)
            ->assertJsonPath('data.is_synthetic', true);

        $saved1 = PilotResponse::findOrFail($res1->json('data.id'));
        $this->assertTrue((bool) $saved1->is_synthetic, 'Synthetic token must strictly produce synthetic response');

        // 2. Real participant: client attempts to upgrade by passing is_synthetic = true
        $realInvite = $service->createParticipant($this->school, [
            'subject_area' => 'Математика',
            'teaching_experience_years' => 12,
            'is_synthetic' => false,
        ]);
        $this->assertFalse($realInvite['participant']->is_synthetic);

        $res2 = $this->postJson('/api/v1/pilot/responses', [
            'invite_token' => $realInvite['invite_token'],
            'scenario_id' => $this->scenario->id,
            'prep_time_baseline_minutes' => 60,
            'prep_time_ai_minutes' => 20,
            'personalization_selected' => true,
            'manual_edit_percentage' => 15.0,
            'cultural_context_used' => false,
            'is_synthetic' => true, // Attempted upgrade
        ]);

        $res2->assertStatus(201)
            ->assertJsonPath('data.is_synthetic', false);

        $saved2 = PilotResponse::findOrFail($res2->json('data.id'));
        $this->assertFalse((bool) $saved2->is_synthetic, 'Real token must strictly produce real response');
    }

    // MANDATORY TEST L: Regional curator cannot access global metrics or cross-region schools (Scenario F)
    public function test_regional_curator_cannot_access_global_metrics_or_cross_region_schools(): void
    {
        $schoolMoscow = School::create([
            'name' => 'Школа №1502 Москва',
            'short_name' => 'Школа 1502',
            'region_code' => '77',
            'city' => 'Москва',
            'inn' => '7720011223',
            'settings' => [],
        ]);

        // Regional curator (region 66) requests global scope -> 403 Forbidden
        $this->actingAs($this->curator)
            ->getJson('/api/v1/pilot/metrics?scope=global')
            ->assertStatus(403);

        $this->actingAs($this->curator)
            ->getJson('/api/v1/pilot/export?scope=global')
            ->assertStatus(403);

        // Regional curator (region 66) requests school in region 77 -> 403 Forbidden
        $this->actingAs($this->curator)
            ->withHeader('X-School-Id', $schoolMoscow->id)
            ->getJson('/api/v1/pilot/metrics?scope=school')
            ->assertStatus(403);

        $this->actingAs($this->curator)
            ->withHeader('X-School-Id', $schoolMoscow->id)
            ->getJson('/api/v1/pilot/export?scope=school')
            ->assertStatus(403);

        // Regional curator (region 66) requests school in region 66 -> 200 OK
        $this->actingAs($this->curator)
            ->withHeader('X-School-Id', $this->school->id)
            ->getJson('/api/v1/pilot/metrics?scope=school')
            ->assertStatus(200);

        // System admin can access global scope -> 200 OK
        $this->actingAs($this->admin)
            ->getJson('/api/v1/pilot/metrics?scope=global')
            ->assertStatus(200);

        $this->actingAs($this->admin)
            ->getJson('/api/v1/pilot/export?scope=global')
            ->assertStatus(200);
    }

    // MANDATORY TEST M: Partial unique index prevents duplicate effective reviews (Scenario G)
    public function test_partial_unique_index_prevents_duplicate_effective_reviews(): void
    {
        $service = app(PilotService::class);
        $invite = $service->createParticipant($this->school, [
            'subject_area' => 'Музыка',
            'teaching_experience_years' => 6,
        ]);

        $response = $service->submitResponse($invite['invite_token'], [
            'scenario_id' => $this->scenario->id,
            'prep_time_baseline_minutes' => 45,
            'prep_time_ai_minutes' => 15,
            'personalization_selected' => true,
            'manual_edit_percentage' => 10.0,
            'cultural_context_used' => false,
        ]);

        // Submit first review finding
        $finding1 = $service->submitReviewFinding($response, $this->curator, [
            'expert_audit_status' => 'audited',
            'critical_cultural_errors' => 0,
            'methodological_remarks' => 'Ревизия 1',
        ]);
        $this->assertTrue($finding1->is_effective);
        $this->assertEquals(1, $finding1->finding_version);

        // Submit second review finding via service (updates effectively)
        $finding2 = $service->submitReviewFinding($response, $this->curator, [
            'expert_audit_status' => 'audited',
            'critical_cultural_errors' => 0,
            'methodological_remarks' => 'Ревизия 2',
        ]);
        $this->assertTrue($finding2->is_effective);
        $this->assertEquals(2, $finding2->finding_version);

        // Verify finding 1 is no longer effective
        $this->assertFalse($finding1->fresh()->is_effective);

        // Attempting to directly insert a second effective finding in DB violates partial unique index
        $this->expectException(\Illuminate\Database\QueryException::class);
        PilotReviewFinding::create([
            'pilot_response_id' => $response->id,
            'reviewed_by_user_id' => $this->curator->id,
            'expert_audit_status' => 'audited',
            'critical_cultural_errors' => 0,
            'is_effective' => true, // Concurrent or illegitimate effective review
            'finding_version' => 3,
        ]);
    }

    // MANDATORY TEST N: Diagnostic dataset command and heuristic integrity (Scenario E)
    public function test_diagnose_pilot_dataset_command_and_heuristics(): void
    {
        $service = app(PilotService::class);
        $report = $service->diagnoseDataset();

        $this->assertArrayHasKey('confirmed_synthetic', $report);
        $this->assertArrayHasKey('suspected_synthetic', $report);
        $this->assertArrayHasKey('unclassified', $report);
        $this->assertArrayHasKey('requires_review', $report);
        $this->assertArrayHasKey('verified_real', $report);
        $this->assertArrayHasKey('total_responses_scanned', $report);
        $this->assertArrayHasKey('summary', $report);
        $this->assertArrayHasKey('safety_notice', $report);

        // Test artisan command execution
        $this->artisan('pilot:diagnose-dataset', ['--format' => 'json'])
            ->assertExitCode(0);
    }

    // MANDATORY TEST O: Regional curator without membership cannot create traditions (BE-008.3 Check A)
    public function test_regional_curator_without_membership_cannot_create_traditions(): void
    {
        $res = $this->actingAs($this->curator)
            ->withHeader('X-School-Id', $this->school->id)
            ->postJson("/api/v1/schools/{$this->school->id}/traditions", [
                'title' => 'Несанкционированная традиция куратора',
                'description' => 'Описание традиции куратора без членства в школе',
                'established_year' => 2026,
            ]);

        $res->assertStatus(403);
        $this->assertDatabaseMissing('school_traditions', [
            'title' => 'Несанкционированная традиция куратора',
        ]);
    }

    // MANDATORY TEST P: Regional curator without membership cannot create scenario requests (BE-008.3 Check B)
    public function test_regional_curator_without_membership_cannot_create_scenario_requests(): void
    {
        $res = $this->actingAs($this->curator)
            ->withHeader('X-School-Id', $this->school->id)
            ->postJson('/api/v1/scenario-requests', [
                'custom_event_title' => 'Несанкционированный сценарий куратора',
                'target_grades' => [5],
                'duration_minutes' => 45,
                'participants_count' => 25,
                'scale' => 'class_level',
                'format_type' => 'class_hour',
                'value_direction' => 'historical_memory',
                'family_involvement' => false,
            ]);

        $res->assertStatus(403);
        $this->assertDatabaseMissing('scenario_requests', [
            'custom_event_title' => 'Несанкционированный сценарий куратора',
        ]);
    }

    // MANDATORY TEST Q: Teacher retains scenario creation access (BE-008.3 Check C)
    public function test_teacher_with_membership_maintains_scenario_access(): void
    {
        $res = $this->actingAs($this->teacher)
            ->withHeader('X-School-Id', $this->school->id)
            ->postJson('/api/v1/scenario-requests', [
                'custom_event_title' => 'Сценарий учителя истории',
                'target_grades' => [8],
                'duration_minutes' => 45,
                'participants_count' => 25,
                'scale' => 'class_level',
                'format_type' => 'class_hour',
                'value_direction' => 'historical_memory',
                'family_involvement' => false,
            ]);

        $res->assertStatus(201);
        $this->assertDatabaseHas('scenario_requests', [
            'custom_event_title' => 'Сценарий учителя истории',
        ]);
    }

    // MANDATORY TEST R: School admin maintains standard school powers (BE-008.3 Check D)
    public function test_school_admin_maintains_standard_school_powers(): void
    {
        $res = $this->actingAs($this->schoolAdmin)
            ->withHeader('X-School-Id', $this->school->id)
            ->postJson("/api/v1/schools/{$this->school->id}/traditions", [
                'title' => 'Школьная традиция директора',
                'description' => 'Описание легитимной традиции школы',
                'established_year' => 2010,
            ]);

        $res->assertStatus(201);
        $this->assertDatabaseHas('school_traditions', [
            'title' => 'Школьная традиция директора',
        ]);
    }
}
