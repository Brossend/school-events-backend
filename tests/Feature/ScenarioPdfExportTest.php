<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ExecuteGenerationRunJob;
use App\Models\CalendarEvent;
use App\Models\ExportJob;
use App\Models\ExportedArtifact;
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
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ScenarioPdfExportTest extends TestCase
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

        Storage::fake('local');

        $this->school = School::factory()->create(['name' => 'Школа № 100']);
        $otherSchool = School::factory()->create(['name' => 'Школа № 200']);

        $this->teacher = User::factory()->create(['email' => 'export_teacher@test.ru']);
        SchoolMembership::factory()->create([
            'school_id' => $this->school->id,
            'user_id' => $this->teacher->id,
            'role' => 'teacher',
            'is_default' => true,
        ]);

        $this->otherTeacher = User::factory()->create(['email' => 'other_export@test.ru']);
        SchoolMembership::factory()->create([
            'school_id' => $otherSchool->id,
            'user_id' => $this->otherTeacher->id,
            'role' => 'teacher',
            'is_default' => true,
        ]);

        $event = CalendarEvent::create([
            'title' => 'День славянской письменности и культуры',
            'event_type' => 'federal',
            'date_type' => 'fixed_annual',
            'fixed_month' => 5,
            'fixed_day' => 24,
            'description' => 'Культурно-просветительское мероприятие',
            'target_age_groups' => ['basic_5_9'],
            'recommended_value_directions' => ['culture_and_traditions', 'historical_memory'],
            'status' => 'approved',
        ]);

        $input = [
            'calendar_event_id' => $event->id,
            'scale' => 'class_level',
            'format_type' => 'class_hour',
            'target_grades' => [5],
            'participants_count' => 26,
            'duration_minutes' => 45,
            'value_direction' => 'cultural_diversity',
            'family_involvement' => false,
            'available_resources' => ['Проектор', 'Плакаты'],
        ];

        $snapshot = app(ContextSnapshotBuilder::class)->buildSnapshot($this->teacher, $this->school, $input);

        $req = ScenarioRequest::create([
            'school_id' => $this->school->id,
            'user_id' => $this->teacher->id,
            'calendar_event_id' => $event->id,
            'scale' => 'class_level',
            'format_type' => 'class_hour',
            'target_grades' => [5],
            'participants_count' => 26,
            'duration_minutes' => 45,
            'value_direction' => 'cultural_diversity',
            'family_involvement' => false,
            'available_resources' => ['Проектор', 'Плакаты'],
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

    public function test_export_forbidden_without_teacher_signoff(): void
    {
        // Moderate cleanly but do NOT sign off
        app(ModerationEngineService::class)->verifyRevision($this->revision);

        $key = (string) Str::uuid();

        $response = $this->actingAs($this->teacher)
            ->withHeader('Idempotency-Key', $key)
            ->postJson("/api/v1/scenarios/{$this->scenario->id}/revisions/{$this->revision->version_number}/exports", [
                'format' => 'pdf',
            ]);

        $response->assertStatus(403)
            ->assertJsonPath('error.code', 'EXPORT_FORBIDDEN_SIGN_OFF_REQUIRED');
    }

    public function test_export_forbidden_with_open_p0(): void
    {
        // Set moderation to blocked
        $this->revision->moderationState->update([
            'status' => 'verification_blocked',
            'p0_violations_count' => 1,
        ]);

        // Add sign-off
        TeacherSignoff::create([
            'revision_id' => $this->revision->id,
            'user_id' => $this->teacher->id,
            'is_confirmed' => true,
            'provenance_fingerprint' => $this->revision->moderationState->provenance_fingerprint,
            'checklist_confirmed' => ['safety_checked' => true, 'timing_fit' => true, 'cultural_context_approved' => true],
            'confirmed_at' => now(),
            'created_at' => now(),
        ]);

        $key = (string) Str::uuid();

        $response = $this->actingAs($this->teacher)
            ->withHeader('Idempotency-Key', $key)
            ->postJson("/api/v1/scenarios/{$this->scenario->id}/revisions/{$this->revision->version_number}/exports", [
                'format' => 'pdf',
            ]);

        $response->assertStatus(403)
            ->assertJsonPath('error.code', 'EXPORT_FORBIDDEN_MODERATION_BLOCKED');
    }

    public function test_export_with_unsupported_format_returns_422(): void
    {
        app(ModerationEngineService::class)->verifyRevision($this->revision);

        $key = (string) Str::uuid();

        $response = $this->actingAs($this->teacher)
            ->withHeader('Idempotency-Key', $key)
            ->postJson("/api/v1/scenarios/{$this->scenario->id}/revisions/{$this->revision->version_number}/exports", [
                'format' => 'unsupported_format',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'FORMAT_NOT_SUPPORTED');
    }

    public function test_successful_pdf_export_and_download_flow(): void
    {
        // 1. Moderate cleanly
        app(ModerationEngineService::class)->verifyRevision($this->revision);

        // 2. Add teacher sign-off
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
            'teacher_notes' => 'Готово к публикации',
            'confirmed_at' => now(),
            'created_at' => now(),
        ]);

        // 3. Request PDF export with Idempotency-Key
        $key = (string) Str::uuid();

        $response = $this->actingAs($this->teacher)
            ->withHeader('Idempotency-Key', $key)
            ->postJson("/api/v1/scenarios/{$this->scenario->id}/revisions/{$this->revision->version_number}/exports", [
                'format' => 'pdf',
            ]);

        $response->assertStatus(202)
            ->assertJsonPath('data.status', 'ready');

        $jobId = $response->json('data.id');
        $this->assertNotNull($jobId);

        // 4. Inspect DB ExportJob & ExportedArtifact
        $job = ExportJob::find($jobId);
        $this->assertNotNull($job);
        $this->assertEquals('ready', $job->status);

        $artifact = ExportedArtifact::where('export_job_id', $jobId)->first();
        $this->assertNotNull($artifact);
        $this->assertEquals('application/pdf', $artifact->mime_type);
        $this->assertNotEmpty($artifact->sha256_hash);
        $this->assertGreaterThan(1000, $artifact->file_size_bytes);

        // 5. Verify physical storage file
        $disk = Storage::disk($artifact->file_disk);
        $this->assertTrue($disk->exists($artifact->file_path));

        $fileContent = $disk->get($artifact->file_path);
        $this->assertStringStartsWith('%PDF-', $fileContent);
        $this->assertEquals($artifact->sha256_hash, hash('sha256', $fileContent));
        $this->assertEquals($artifact->file_size_bytes, strlen($fileContent));

        // 6. Check status endpoint
        $statusRes = $this->actingAs($this->teacher)
            ->getJson("/api/v1/export-jobs/{$jobId}");

        $statusRes->assertStatus(200)
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.download_url', "/api/v1/export-jobs/{$jobId}/download");

        // 7. Download PDF
        $downloadRes = $this->actingAs($this->teacher)
            ->get("/api/v1/export-jobs/{$jobId}/download");

        $downloadRes->assertStatus(200);
        $downloadRes->assertHeader('Content-Type', 'application/pdf');
        $downloadRes->assertHeader('X-Checksum-Sha256', $artifact->sha256_hash);
        $this->assertStringStartsWith('%PDF-', $downloadRes->getContent());

        // 8. Test Idempotency Replay
        $replayRes = $this->actingAs($this->teacher)
            ->withHeader('Idempotency-Key', $key)
            ->postJson("/api/v1/scenarios/{$this->scenario->id}/revisions/{$this->revision->version_number}/exports", [
                'format' => 'pdf',
            ]);

        $replayRes->assertStatus(202)
            ->assertJsonPath('data.id', $jobId);

        // Only 1 ExportJob was created
        $this->assertEquals(1, ExportJob::where('revision_id', $this->revision->id)->count());
    }

    public function test_cross_tenant_download_forbidden(): void
    {
        // Prepare completed export job
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

        $res = $this->actingAs($this->teacher)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/scenarios/{$this->scenario->id}/revisions/{$this->revision->version_number}/exports", [
                'format' => 'pdf',
            ]);

        $jobId = $res->json('data.id');

        // Teacher from school B attempts to download
        $downloadRes = $this->actingAs($this->otherTeacher)
            ->getJson("/api/v1/export-jobs/{$jobId}/download");

        $downloadRes->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN_TENANT_ACCESS');
    }
}
