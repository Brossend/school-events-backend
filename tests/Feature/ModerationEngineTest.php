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
use App\Models\User;
use App\Models\VerificationRun;
use App\Services\ContextSnapshotBuilder;
use App\Services\Llm\MockLlmProvider;
use App\Services\Moderation\ModerationEngineService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ModerationEngineTest extends TestCase
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

        $this->school = School::factory()->create(['name' => 'Гимназия № 1']);
        $otherSchool = School::factory()->create(['name' => 'Школа № 2']);

        $this->teacher = User::factory()->create(['email' => 'teacher_mod@test.ru']);
        SchoolMembership::factory()->create([
            'school_id' => $this->school->id,
            'user_id' => $this->teacher->id,
            'role' => 'teacher',
            'is_default' => true,
        ]);

        $this->otherTeacher = User::factory()->create(['email' => 'other_mod@test.ru']);
        SchoolMembership::factory()->create([
            'school_id' => $otherSchool->id,
            'user_id' => $this->otherTeacher->id,
            'role' => 'teacher',
            'is_default' => true,
        ]);

        $event = CalendarEvent::create([
            'title' => 'День славянской письменности',
            'event_type' => 'federal',
            'date_type' => 'fixed_annual',
            'fixed_month' => 5,
            'fixed_day' => 24,
            'description' => 'Культурно-историческое событие',
            'target_age_groups' => ['basic_5_9'],
            'recommended_value_directions' => ['historical_memory', 'culture_and_traditions'],
            'status' => 'approved',
        ]);

        $input = [
            'calendar_event_id' => $event->id,
            'scale' => 'class_level',
            'format_type' => 'class_hour',
            'target_grades' => [5],
            'participants_count' => 25,
            'duration_minutes' => 45,
            'value_direction' => 'cultural_diversity',
            'family_involvement' => false,
            'available_resources' => ['Интерактивная доска'],
        ];

        $snapshot = app(ContextSnapshotBuilder::class)->buildSnapshot($this->teacher, $this->school, $input);

        $req = ScenarioRequest::create([
            'school_id' => $this->school->id,
            'user_id' => $this->teacher->id,
            'calendar_event_id' => $event->id,
            'scale' => 'class_level',
            'format_type' => 'class_hour',
            'target_grades' => [5],
            'participants_count' => 25,
            'duration_minutes' => 45,
            'value_direction' => 'cultural_diversity',
            'family_involvement' => false,
            'available_resources' => ['Интерактивная доска'],
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

    public function test_clean_revision_passes_moderation(): void
    {
        $engine = app(ModerationEngineService::class);
        $run = $engine->verifyRevision($this->revision);

        $this->assertEquals('passed', $run->status);
        $this->assertEquals(0, $run->p0_violations_count);
        $this->assertEquals(0, $run->p1_violations_count);

        $this->revision->refresh();
        $this->assertEquals('verification_passed', $this->revision->moderationState->status);
    }

    public function test_p0_stereotype_blocks_verification(): void
    {
        // Mutate payload with prohibited stereotype
        $payload = $this->revision->content_payload;
        $payload['concept'] = 'Сценарий где утверждается что все представители этой национальности ленивы.';
        
        // Update in DB table directly since model is immutable
        \Illuminate\Support\Facades\DB::table('scenario_revisions')
            ->where('id', $this->revision->id)
            ->update(['content_payload' => json_encode($payload)]);

        $this->revision->refresh();

        $engine = app(ModerationEngineService::class);
        $run = $engine->verifyRevision($this->revision);

        $this->assertEquals('blocked', $run->status);
        $this->assertGreaterThan(0, $run->p0_violations_count);

        $this->revision->refresh();
        $this->assertEquals('verification_blocked', $this->revision->moderationState->status);

        $finding = $run->findings()->where('rule_code', 'P0_STEREOTYPE')->first();
        $this->assertNotNull($finding);
        $this->assertEquals('P0', $finding->severity);
    }

    public function test_p0_fabricated_culture_blocks_verification(): void
    {
        // Mutate payload to include a claim with verified_authentic but without valid DB claim_id
        $payload = $this->revision->content_payload;
        $payload['cultural_context']['verified_claims'] = [
            [
                'claim_id' => '00000000-0000-0000-0000-000000000000',
                'claim_text' => 'Выдуманный исторический факт как подлинный',
                'status' => 'verified_authentic',
            ]
        ];

        \Illuminate\Support\Facades\DB::table('scenario_revisions')
            ->where('id', $this->revision->id)
            ->update(['content_payload' => json_encode($payload)]);

        $this->revision->refresh();

        $engine = app(ModerationEngineService::class);
        $run = $engine->verifyRevision($this->revision);

        $this->assertEquals('blocked', $run->status);
        $this->assertGreaterThan(0, $run->p0_violations_count);

        $finding = $run->findings()->where('rule_code', 'P0_FABRICATED_CULTURE')->first();
        $this->assertNotNull($finding);
        $this->assertEquals('P0', $finding->severity);
    }

    public function test_p1_timing_mismatch_yields_passed_with_warnings(): void
    {
        // Mutate payload: stages sum 40 min, but total duration 45 min
        $payload = $this->revision->content_payload;
        $payload['stages'][0]['duration_minutes'] = 5;
        $payload['stages'][1]['duration_minutes'] = 10;
        $payload['stages'][2]['duration_minutes'] = 15; // sum = 30, total = 45

        \Illuminate\Support\Facades\DB::table('scenario_revisions')
            ->where('id', $this->revision->id)
            ->update(['content_payload' => json_encode($payload)]);

        $this->revision->refresh();

        $engine = app(ModerationEngineService::class);
        $run = $engine->verifyRevision($this->revision);

        $this->assertEquals('passed_with_warnings', $run->status);
        $this->assertEquals(0, $run->p0_violations_count);
        $this->assertGreaterThan(0, $run->p1_violations_count);

        $this->revision->refresh();
        $this->assertEquals('verification_passed_with_warnings', $this->revision->moderationState->status);

        $finding = $run->findings()->where('rule_code', 'P1_TIMING_MISMATCH')->first();
        $this->assertNotNull($finding);
        $this->assertEquals('P1', $finding->severity);
    }

    public function test_repeated_verification_preserves_history(): void
    {
        $engine = app(ModerationEngineService::class);
        $initialRuns = VerificationRun::where('revision_id', $this->revision->id)->count();

        $run1 = $engine->verifyRevision($this->revision);
        $this->assertNotNull($run1);

        $run2 = $engine->verifyRevision($this->revision);
        $this->assertNotNull($run2);

        $this->assertNotEquals($run1->id, $run2->id);

        // VerificationRuns are strictly append-only: both exist in DB
        $this->assertEquals($initialRuns + 2, VerificationRun::where('revision_id', $this->revision->id)->count());

        $this->revision->refresh();
        $this->assertEquals($run2->id, $this->revision->moderationState->latest_verification_run_id);
    }

    public function test_get_verifications_endpoint_returns_latest_run_and_findings(): void
    {
        $engine = app(ModerationEngineService::class);
        $run = $engine->verifyRevision($this->revision);

        $response = $this->actingAs($this->teacher)
            ->getJson("/api/v1/scenarios/{$this->scenario->id}/revisions/{$this->revision->version_number}/verifications");

        $response->assertStatus(200)
            ->assertJsonPath('data.verification_run_id', $run->id)
            ->assertJsonPath('data.status', 'passed');
    }

    public function test_cross_tenant_access_to_verifications_forbidden(): void
    {
        $response = $this->actingAs($this->otherTeacher)
            ->getJson("/api/v1/scenarios/{$this->scenario->id}/revisions/{$this->revision->version_number}/verifications");

        $response->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN_TENANT_ACCESS');
    }
}
