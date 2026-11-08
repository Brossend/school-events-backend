<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ExecuteGenerationRunJob;
use App\Models\CalendarEvent;
use App\Models\GenerationRun;
use App\Models\Scenario;
use App\Models\ScenarioRequest;
use App\Models\ScenarioRevision;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\TeacherSignoff;
use App\Models\User;
use App\Services\ContextSnapshotBuilder;
use App\Services\Llm\MockLlmProvider;
use App\Services\Moderation\ModerationEngineService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class TeacherSignoffTest extends TestCase
{
    use DatabaseTransactions;

    protected School $school;
    protected User $teacher;
    protected User $otherTeacher;
    protected Scenario $scenario;
    protected ScenarioRevision $revision;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::factory()->create(['name' => 'Лицей № 12']);
        $otherSchool = School::factory()->create(['name' => 'Лицей № 34']);

        $this->teacher = User::factory()->create(['email' => 'signoff_teacher@test.ru']);
        SchoolMembership::factory()->create([
            'school_id' => $this->school->id,
            'user_id' => $this->teacher->id,
            'role' => 'teacher',
            'is_default' => true,
        ]);

        $this->otherTeacher = User::factory()->create(['email' => 'other_signoff@test.ru']);
        SchoolMembership::factory()->create([
            'school_id' => $otherSchool->id,
            'user_id' => $this->otherTeacher->id,
            'role' => 'teacher',
            'is_default' => true,
        ]);

        $event = CalendarEvent::create([
            'title' => 'День учителя',
            'event_type' => 'federal',
            'date_type' => 'fixed_annual',
            'fixed_month' => 10,
            'fixed_day' => 5,
            'description' => 'Профессиональный праздник педагогов',
            'target_age_groups' => ['basic_5_9'],
            'recommended_value_directions' => ['social_solidarity', 'labor_and_creation'],
            'status' => 'approved',
        ]);

        $input = [
            'calendar_event_id' => $event->id,
            'scale' => 'class_level',
            'format_type' => 'class_hour',
            'target_grades' => [7],
            'participants_count' => 25,
            'duration_minutes' => 45,
            'value_direction' => 'mutual_aid',
            'family_involvement' => false,
            'available_resources' => ['Микрофон', 'Проектор'],
        ];

        $snapshot = app(ContextSnapshotBuilder::class)->buildSnapshot($this->teacher, $this->school, $input);

        $req = ScenarioRequest::create([
            'school_id' => $this->school->id,
            'user_id' => $this->teacher->id,
            'calendar_event_id' => $event->id,
            'scale' => 'class_level',
            'format_type' => 'class_hour',
            'target_grades' => [7],
            'participants_count' => 25,
            'duration_minutes' => 45,
            'value_direction' => 'mutual_aid',
            'family_involvement' => false,
            'available_resources' => ['Микрофон', 'Проектор'],
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

    public function test_signoff_forbidden_without_moderation(): void
    {
        // Reset moderation to pending_verification
        $this->revision->moderationState->update([
            'status' => 'pending_verification',
        ]);

        $response = $this->actingAs($this->teacher)
            ->postJson("/api/v1/scenarios/{$this->scenario->id}/revisions/{$this->revision->version_number}/signoff", [
                'checklist_confirmed' => [
                    'safety_checked' => true,
                    'timing_fit' => true,
                    'cultural_context_approved' => true,
                ],
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'MODERATION_IN_PROGRESS');
    }

    public function test_signoff_forbidden_with_open_p0(): void
    {
        $this->revision->moderationState->update([
            'status' => 'verification_blocked',
            'p0_violations_count' => 1,
        ]);

        $response = $this->actingAs($this->teacher)
            ->postJson("/api/v1/scenarios/{$this->scenario->id}/revisions/{$this->revision->version_number}/signoff", [
                'checklist_confirmed' => [
                    'safety_checked' => true,
                    'timing_fit' => true,
                    'cultural_context_approved' => true,
                ],
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'MODERATION_BLOCKED_P0');
    }

    public function test_signoff_forbidden_if_checklist_flag_is_false(): void
    {
        // Moderate revision cleanly
        app(ModerationEngineService::class)->verifyRevision($this->revision);

        $response = $this->actingAs($this->teacher)
            ->postJson("/api/v1/scenarios/{$this->scenario->id}/revisions/{$this->revision->version_number}/signoff", [
                'checklist_confirmed' => [
                    'safety_checked' => true,
                    'timing_fit' => false, // FALSE!
                    'cultural_context_approved' => true,
                ],
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'SIGNOFF_CHECKLIST_FLAGS_MUST_BE_TRUE');
    }

    public function test_successful_signoff_creates_historical_record_and_keeps_moderation_status(): void
    {
        // Moderate revision cleanly
        app(ModerationEngineService::class)->verifyRevision($this->revision);

        $notes = 'Сценарий детально проверен и согласован.';

        $response = $this->actingAs($this->teacher)
            ->postJson("/api/v1/scenarios/{$this->scenario->id}/revisions/{$this->revision->version_number}/signoff", [
                'checklist_confirmed' => [
                    'safety_checked' => true,
                    'timing_fit' => true,
                    'cultural_context_approved' => true,
                ],
                'teacher_notes' => $notes,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.is_confirmed', true)
            ->assertJsonPath('data.teacher_notes', $notes);

        // Check teacher_signoffs table
        $signoff = TeacherSignoff::where('revision_id', $this->revision->id)->first();
        $this->assertNotNull($signoff);
        $this->assertEquals($this->teacher->id, $signoff->user_id);
        $this->assertTrue($signoff->is_confirmed);
        $this->assertNotNull($signoff->confirmed_at);

        // Moderation state must NOT be mutated to signed_off (signoff is an independent attestation)
        $this->revision->refresh();
        $this->assertEquals('verification_passed', $this->revision->moderationState->status);
    }

    public function test_cross_tenant_signoff_forbidden(): void
    {
        app(ModerationEngineService::class)->verifyRevision($this->revision);

        $response = $this->actingAs($this->otherTeacher)
            ->postJson("/api/v1/scenarios/{$this->scenario->id}/revisions/{$this->revision->version_number}/signoff", [
                'checklist_confirmed' => [
                    'safety_checked' => true,
                    'timing_fit' => true,
                    'cultural_context_approved' => true,
                ],
            ]);

        $response->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN_TENANT_ACCESS');
    }

    public function test_new_revision_requires_fresh_signoff(): void
    {
        // 1. Moderate and sign off revision 1
        app(ModerationEngineService::class)->verifyRevision($this->revision);

        $this->actingAs($this->teacher)
            ->postJson("/api/v1/scenarios/{$this->scenario->id}/revisions/1/signoff", [
                'checklist_confirmed' => [
                    'safety_checked' => true,
                    'timing_fit' => true,
                    'cultural_context_approved' => true,
                ],
            ])->assertStatus(200);

        $this->assertTrue(TeacherSignoff::where('revision_id', $this->revision->id)->exists());

        // 2. Patch to revision 2
        $patchRes = $this->actingAs($this->teacher)
            ->withHeader('If-Match', (string) $this->scenario->optimistic_lock_version)
            ->patchJson("/api/v1/scenarios/{$this->scenario->id}/revisions/1", [
                'concept' => 'Обновление для проверки сброса подтверждения',
            ]);
        $patchRes->assertStatus(201);

        $this->scenario->refresh();
        $revision2 = $this->scenario->currentRevision;
        $this->assertEquals(2, $revision2->version_number);

        // Revision 2 must NOT have sign-off
        $this->assertFalse(TeacherSignoff::where('revision_id', $revision2->id)->exists());
    }

    public function test_scenario_and_revision_endpoints_expose_signoff_and_moderation_state(): void
    {
        app(ModerationEngineService::class)->verifyRevision($this->revision);

        $this->actingAs($this->teacher)
            ->withHeader('X-School-Id', $this->school->id)
            ->postJson("/api/v1/scenarios/{$this->scenario->id}/revisions/1/signoff", [
                'checklist_confirmed' => [
                    'safety_checked' => true,
                    'timing_fit' => true,
                    'cultural_context_approved' => true,
                ],
                'teacher_notes' => 'Проверено лично',
            ])->assertStatus(200);

        // Check GET /scenarios/{id}
        $scRes = $this->actingAs($this->teacher)
            ->withHeader('X-School-Id', $this->school->id)
            ->getJson("/api/v1/scenarios/{$this->scenario->id}");

        $scRes->assertStatus(200);
        $scRes->assertJsonPath('data.current_revision_id', $this->revision->id);
        $scRes->assertJsonPath('data.current_revision.signoff.is_confirmed', true);
        $scRes->assertJsonPath('data.current_revision.signoff.teacher_notes', 'Проверено лично');
        $scRes->assertJsonPath('data.current_revision.signoff.user.id', $this->teacher->id);
        $scRes->assertJsonPath('data.current_revision.moderation_state.status', 'verification_passed');
        $scRes->assertJsonPath('data.current_revision.moderation_state.p0_violations_count', 0);

        // Check GET /scenarios/{id}/revisions/1
        $revRes = $this->actingAs($this->teacher)
            ->withHeader('X-School-Id', $this->school->id)
            ->getJson("/api/v1/scenarios/{$this->scenario->id}/revisions/1");

        $revRes->assertStatus(200);
        $revRes->assertJsonPath('data.is_current', true);
        $revRes->assertJsonPath('data.signoff.is_confirmed', true);
        $revRes->assertJsonPath('data.signoff.teacher_notes', 'Проверено лично');
        $revRes->assertJsonPath('data.signoff.user.id', $this->teacher->id);
        $revRes->assertJsonPath('data.moderation_state.status', 'verification_passed');
        $revRes->assertJsonPath('data.moderation_state.p0_violations_count', 0);
    }
}
