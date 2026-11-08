<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ExecuteGenerationRunJob;
use App\Models\CalendarEvent;
use App\Models\GenerationRun;
use App\Models\Scenario;
use App\Models\ScenarioMaterial;
use App\Models\ScenarioRequest;
use App\Models\ScenarioRevision;
use App\Models\ScenarioStage;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\TeacherSignoff;
use App\Models\User;
use App\Services\ContextSnapshotBuilder;
use App\Services\Llm\MockLlmProvider;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ScenarioRevisionEditorTest extends TestCase
{
    use DatabaseTransactions;

    protected School $school;
    protected User $teacher;
    protected User $otherTeacher;
    protected Scenario $scenario;
    protected ScenarioRevision $revision1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::factory()->create(['name' => 'Школа № 55']);
        $otherSchool = School::factory()->create(['name' => 'Школа № 77']);

        $this->teacher = User::factory()->create(['email' => 'editor_teacher@test.ru']);
        SchoolMembership::factory()->create([
            'school_id' => $this->school->id,
            'user_id' => $this->teacher->id,
            'role' => 'teacher',
            'is_default' => true,
        ]);

        $this->otherTeacher = User::factory()->create(['email' => 'other_editor@test.ru']);
        SchoolMembership::factory()->create([
            'school_id' => $otherSchool->id,
            'user_id' => $this->otherTeacher->id,
            'role' => 'teacher',
            'is_default' => true,
        ]);

        $event = CalendarEvent::create([
            'title' => 'День космонавтики',
            'event_type' => 'federal',
            'date_type' => 'fixed_annual',
            'fixed_month' => 4,
            'fixed_day' => 12,
            'description' => 'Первый полет человека в космос',
            'target_age_groups' => ['basic_5_9'],
            'recommended_value_directions' => ['labor_and_creation', 'historical_memory'],
            'status' => 'approved',
        ]);

        $input = [
            'calendar_event_id' => $event->id,
            'scale' => 'class_level',
            'format_type' => 'class_hour',
            'target_grades' => [6],
            'participants_count' => 28,
            'duration_minutes' => 45,
            'value_direction' => 'labor_and_creation',
            'family_involvement' => false,
            'available_resources' => ['Проектор'],
        ];

        $snapshot = app(ContextSnapshotBuilder::class)->buildSnapshot($this->teacher, $this->school, $input);

        $req = ScenarioRequest::create([
            'school_id' => $this->school->id,
            'user_id' => $this->teacher->id,
            'calendar_event_id' => $event->id,
            'scale' => 'class_level',
            'format_type' => 'class_hour',
            'target_grades' => [6],
            'participants_count' => 28,
            'duration_minutes' => 45,
            'value_direction' => 'labor_and_creation',
            'family_involvement' => false,
            'available_resources' => ['Проектор'],
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
        $this->revision1 = $this->scenario->currentRevision;

        // Simulate sign-off on revision 1
        TeacherSignoff::create([
            'revision_id' => $this->revision1->id,
            'user_id' => $this->teacher->id,
            'is_confirmed' => true,
            'checklist_confirmed' => [
                'safety_checked' => true,
                'timing_fit' => true,
                'cultural_context_approved' => true,
            ],
            'teacher_notes' => 'Подтверждение первой версии',
            'confirmed_at' => now(),
            'created_at' => now(),
        ]);
    }

    public function test_patch_creates_revision_n_plus_one_and_advances_pointer(): void
    {
        $initialLockVersion = $this->scenario->optimistic_lock_version;
        $initialRevisionId = $this->scenario->current_revision_id;

        $newConcept = 'Обновленная концепция сценария ко Дню космонавтики с интерактивом';
        $newGoal = 'Сформировать интерес к освоению космического пространства через командную работу';

        $patchPayload = [
            'concept' => $newConcept,
            'pedagogical_goal' => $newGoal,
        ];

        $response = $this->actingAs($this->teacher)
            ->withHeader('If-Match', (string) $initialLockVersion)
            ->patchJson(
                "/api/v1/scenarios/{$this->scenario->id}/revisions/{$this->revision1->version_number}",
                $patchPayload
            );

        $response->assertStatus(201)
            ->assertJsonPath('data.current_revision.version_number', 2)
            ->assertJsonPath('data.optimistic_lock_version', $initialLockVersion + 1);

        $this->scenario->refresh();

        // 1. New revision ID is set
        $this->assertNotEquals($initialRevisionId, $this->scenario->current_revision_id);
        $this->assertEquals($initialLockVersion + 1, $this->scenario->optimistic_lock_version);

        // 2. Old revision v1 remains completely unchanged (immutable)
        $this->revision1->refresh();
        $this->assertEquals(1, $this->revision1->version_number);
        $this->assertNotEquals($newConcept, $this->revision1->concept);

        // 3. New revision v2 has updated concept & goal
        $revision2 = ScenarioRevision::find($this->scenario->current_revision_id);
        $this->assertNotNull($revision2);
        $this->assertEquals(2, $revision2->version_number);
        $this->assertEquals($newConcept, $revision2->concept);
        $this->assertEquals($newGoal, $revision2->pedagogical_goal);

        // 4. Projections recreated for revision 2
        $this->assertGreaterThan(0, ScenarioStage::where('revision_id', $revision2->id)->count());
        $this->assertGreaterThan(0, ScenarioMaterial::where('revision_id', $revision2->id)->count());

        // 5. Sign-off is NOT inherited on Revision 2
        $this->assertFalse(TeacherSignoff::where('revision_id', $revision2->id)->exists());
        $this->assertTrue(TeacherSignoff::where('revision_id', $this->revision1->id)->exists());
    }

    public function test_patch_with_optimistic_lock_mismatch_returns_409(): void
    {
        $wrongLockVersion = $this->scenario->optimistic_lock_version + 99;

        $response = $this->actingAs($this->teacher)
            ->withHeader('If-Match', (string) $wrongLockVersion)
            ->patchJson(
                "/api/v1/scenarios/{$this->scenario->id}/revisions/{$this->revision1->version_number}",
                ['concept' => 'Попытка обновления с неверным If-Match']
            );

        $response->assertStatus(409)
            ->assertJsonPath('error.code', 'OPTIMISTIC_LOCK_CONFLICT');

        // Pointer must not have changed
        $this->scenario->refresh();
        $this->assertEquals($this->revision1->id, $this->scenario->current_revision_id);
    }

    public function test_patch_without_if_match_header_fails_409(): void
    {
        $response = $this->actingAs($this->teacher)
            ->patchJson(
                "/api/v1/scenarios/{$this->scenario->id}/revisions/{$this->revision1->version_number}",
                ['concept' => 'Попытка обновления без заголовка']
            );

        $response->assertStatus(409)
            ->assertJsonPath('error.code', 'IF_MATCH_HEADER_REQUIRED');
    }

    public function test_patch_historical_revision_fails_409(): void
    {
        // Advance to v2
        $this->actingAs($this->teacher)
            ->withHeader('If-Match', (string) $this->scenario->optimistic_lock_version)
            ->patchJson(
                "/api/v1/scenarios/{$this->scenario->id}/revisions/1",
                ['concept' => 'Обновленная концепция сценария для второй версии мероприятия']
            )->assertStatus(201);

        $this->scenario->refresh();
        $this->assertEquals(2, $this->scenario->currentRevision->version_number);

        // Attempt to edit historical v1 now
        $response = $this->actingAs($this->teacher)
            ->withHeader('If-Match', (string) $this->scenario->optimistic_lock_version)
            ->patchJson(
                "/api/v1/scenarios/{$this->scenario->id}/revisions/1",
                ['concept' => 'Попытка изменить историческую первую ревизию когда активна вторая']
            );

        $response->assertStatus(409)
            ->assertJsonPath('error.code', 'CANNOT_EDIT_HISTORICAL_REVISION');
    }

    public function test_cross_tenant_patch_forbidden(): void
    {
        $response = $this->actingAs($this->otherTeacher)
            ->withHeader('If-Match', (string) $this->scenario->optimistic_lock_version)
            ->patchJson(
                "/api/v1/scenarios/{$this->scenario->id}/revisions/{$this->revision1->version_number}",
                ['concept' => 'Взлом сценария другой школы']
            );

        $response->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN_TENANT_ACCESS');
    }
}
