<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ExecuteGenerationRunJob;
use App\Models\GenerationRun;
use App\Models\IdempotencyKey;
use App\Models\ScenarioRequest;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class IdempotencyTest extends TestCase
{
    use DatabaseTransactions;

    protected School $school;
    protected User $teacher;
    protected ScenarioRequest $scenarioRequest;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::factory()->create(['name' => 'Школа № 50']);

        $this->teacher = User::factory()->create(['email' => 'teacher_idem@test.ru']);
        SchoolMembership::factory()->create([
            'school_id' => $this->school->id,
            'user_id' => $this->teacher->id,
            'role' => 'teacher',
            'is_default' => true,
        ]);

        $this->scenarioRequest = ScenarioRequest::create([
            'school_id' => $this->school->id,
            'user_id' => $this->teacher->id,
            'custom_event_title' => 'Идемпотентный тест генерации',
            'scale' => 'class_level',
            'format_type' => 'class_hour',
            'target_grades' => [5],
            'participants_count' => 25,
            'duration_minutes' => 45,
            'value_direction' => 'family',
            'family_involvement' => false,
            'context_snapshot' => [
                'occasion' => ['title' => 'Идемпотентный тест генерации'],
                'pedagogical_parameters' => [
                    'duration_minutes' => 45,
                    'target_grades' => [5],
                    'participants_count' => 25,
                    'scale' => 'class_level',
                    'format_type' => 'class_hour',
                    'value_direction' => 'family',
                    'family_involvement' => false,
                ],
                'cultural_provenance' => ['claims' => [], 'sources' => []],
            ],
        ]);
    }

    public function test_post_generate_without_idempotency_key_fails_422(): void
    {
        $response = $this->actingAs($this->teacher)
            ->postJson("/api/v1/scenario-requests/{$this->scenarioRequest->id}/generate");

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REQUIRED');
    }

    public function test_post_generate_with_non_uuid_idempotency_key_fails_422(): void
    {
        $response = $this->actingAs($this->teacher)
            ->withHeader('Idempotency-Key', 'non-uuid-key')
            ->postJson("/api/v1/scenario-requests/{$this->scenarioRequest->id}/generate");

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_INVALID');
    }

    public function test_post_generate_returns_202_and_creates_run(): void
    {
        \Illuminate\Support\Facades\Queue::fake();

        $key = (string) \Illuminate\Support\Str::uuid();

        $response = $this->actingAs($this->teacher)
            ->withHeader('Idempotency-Key', $key)
            ->postJson("/api/v1/scenario-requests/{$this->scenarioRequest->id}/generate");

        $response->assertStatus(202)
            ->assertJsonPath('data.scenario_request_id', $this->scenarioRequest->id)
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonStructure(['data' => ['generation_run_id', 'run_id', 'poll_url', 'estimated_wait_seconds', 'created_at']]);

        $runId = $response->json('data.run_id');
        $this->assertDatabaseHas('generation_runs', [
            'id' => $runId,
            'status' => 'queued',
        ]);

        \Illuminate\Support\Facades\Queue::assertPushed(ExecuteGenerationRunJob::class);
    }

    public function test_idempotent_duplicate_request_while_processing_returns_same_run(): void
    {
        \Illuminate\Support\Facades\Queue::fake();

        $key = (string) \Illuminate\Support\Str::uuid();

        // 1. Initial request with Idempotency-Key
        $response1 = $this->actingAs($this->teacher)
            ->withHeader('Idempotency-Key', $key)
            ->postJson("/api/v1/scenario-requests/{$this->scenarioRequest->id}/generate");

        $response1->assertStatus(202);
        $runId = $response1->json('data.run_id');

        // 2. Immediate duplicate request with same key while key is in 'processing' status
        $response2 = $this->actingAs($this->teacher)
            ->withHeader('Idempotency-Key', $key)
            ->postJson("/api/v1/scenario-requests/{$this->scenarioRequest->id}/generate");

        $response2->assertStatus(202)
            ->assertJsonPath('data.run_id', $runId);

        // Verify only 1 run created
        $this->assertEquals(1, GenerationRun::where('scenario_request_id', $this->scenarioRequest->id)->count());
    }

    public function test_idempotent_duplicate_request_after_completion_replays_response(): void
    {
        $key = (string) \Illuminate\Support\Str::uuid();

        // 1. Initial request
        $response1 = $this->actingAs($this->teacher)
            ->withHeader('Idempotency-Key', $key)
            ->postJson("/api/v1/scenario-requests/{$this->scenarioRequest->id}/generate");

        $response1->assertStatus(202);
        $runId = $response1->json('data.run_id');

        // Execute the job to completion
        $job = new ExecuteGenerationRunJob($runId, IdempotencyKey::where('key', $key)->first()->id);
        app()->call([$job, 'handle']);

        // Check key status is completed
        $this->assertDatabaseHas('idempotency_keys', [
            'key' => $key,
            'status' => 'completed',
        ]);

        // 2. Repeat request with same key
        $response2 = $this->actingAs($this->teacher)
            ->withHeader('Idempotency-Key', $key)
            ->postJson("/api/v1/scenario-requests/{$this->scenarioRequest->id}/generate");

        // Must replay response without creating a second GenerationRun
        $response2->assertStatus(202)
            ->assertJsonPath('data.run_id', $runId);

        $runsCount = GenerationRun::where('scenario_request_id', $this->scenarioRequest->id)->count();
        $this->assertEquals(1, $runsCount);
    }

    public function test_idempotent_request_with_payload_mismatch_returns_409(): void
    {
        $key = (string) \Illuminate\Support\Str::uuid();

        // 1. First request with payload A
        $this->actingAs($this->teacher)
            ->withHeader('Idempotency-Key', $key)
            ->postJson("/api/v1/scenario-requests/{$this->scenarioRequest->id}/generate", [
                'param' => 'value_a',
            ])->assertStatus(202);

        // 2. Second request with different payload B
        $response = $this->actingAs($this->teacher)
            ->withHeader('Idempotency-Key', $key)
            ->postJson("/api/v1/scenario-requests/{$this->scenarioRequest->id}/generate", [
                'param' => 'value_b_different',
            ]);

        $response->assertStatus(409)
            ->assertJsonPath('error.code', 'IDEMPOTENCY_PAYLOAD_MISMATCH');
    }

    public function test_cannot_generate_with_new_key_if_scenario_already_created(): void
    {
        $key1 = (string) \Illuminate\Support\Str::uuid();

        // 1. Initial generation
        $res1 = $this->actingAs($this->teacher)
            ->withHeader('Idempotency-Key', $key1)
            ->postJson("/api/v1/scenario-requests/{$this->scenarioRequest->id}/generate");
        $res1->assertStatus(202);
        $runId = $res1->json('data.run_id');

        // Complete execution
        $job = new ExecuteGenerationRunJob($runId, IdempotencyKey::where('key', $key1)->first()->id);
        app()->call([$job, 'handle']);

        // 2. Try to generate with a brand NEW key
        $key2 = (string) \Illuminate\Support\Str::uuid();
        $res2 = $this->actingAs($this->teacher)
            ->withHeader('Idempotency-Key', $key2)
            ->postJson("/api/v1/scenario-requests/{$this->scenarioRequest->id}/generate");

        $res2->assertStatus(409)
            ->assertJsonPath('error.code', 'SCENARIO_ALREADY_GENERATED');
    }

    public function test_new_key_rejected_with_409_if_generation_already_in_progress(): void
    {
        \Illuminate\Support\Facades\Queue::fake();

        $keyA = (string) \Illuminate\Support\Str::uuid();
        $keyB = (string) \Illuminate\Support\Str::uuid();

        // 1. Initial request with Key A creates queued run
        $resA = $this->actingAs($this->teacher)
            ->withHeader('Idempotency-Key', $keyA)
            ->postJson("/api/v1/scenario-requests/{$this->scenarioRequest->id}/generate");

        $resA->assertStatus(202);
        $runIdA = $resA->json('data.run_id');

        // Verify run is queued
        $this->assertDatabaseHas('generation_runs', [
            'id' => $runIdA,
            'status' => 'queued',
        ]);

        // 2. Second request with Key B while Key A is still queued/running
        $resB = $this->actingAs($this->teacher)
            ->withHeader('Idempotency-Key', $keyB)
            ->postJson("/api/v1/scenario-requests/{$this->scenarioRequest->id}/generate");

        $resB->assertStatus(409)
            ->assertJsonPath('error.code', 'GENERATION_ALREADY_IN_PROGRESS')
            ->assertJsonPath('error.details.generation_run_id', $runIdA);

        // Verify only 1 run was created
        $this->assertEquals(1, GenerationRun::where('scenario_request_id', $this->scenarioRequest->id)->count());
        $this->assertEquals(1, IdempotencyKey::where('key', $keyA)->count());
        // Key B processing record was cleaned up, avoiding orphaned records
        $this->assertEquals(0, IdempotencyKey::where('key', $keyB)->count());
    }

    public function test_retry_of_same_execute_generation_run_job_does_not_create_second_revision(): void
    {
        $key = (string) \Illuminate\Support\Str::uuid();

        $res = $this->actingAs($this->teacher)
            ->withHeader('Idempotency-Key', $key)
            ->postJson("/api/v1/scenario-requests/{$this->scenarioRequest->id}/generate");
        $res->assertStatus(202);
        $runId = $res->json('data.run_id');

        // First execution of job creates scenario and revision v1
        $job = new ExecuteGenerationRunJob($runId, IdempotencyKey::where('key', $key)->first()->id);
        app()->call([$job, 'handle']);

        $scenario = \App\Models\Scenario::where('scenario_request_id', $this->scenarioRequest->id)->first();
        $this->assertNotNull($scenario);
        $this->assertEquals(1, $scenario->revisions()->count());

        // Second retry of same job MUST NOT create a second revision or duplicate scenario
        app()->call([$job, 'handle']);

        $scenario->refresh();
        $this->assertEquals(1, $scenario->revisions()->count());
        $this->assertEquals(1, \App\Models\Scenario::where('scenario_request_id', $this->scenarioRequest->id)->count());
    }

    public function test_repeat_with_same_key_after_failed_run_replays_failed_response(): void
    {
        $key = (string) \Illuminate\Support\Str::uuid();

        $res = $this->actingAs($this->teacher)
            ->withHeader('Idempotency-Key', $key)
            ->postJson("/api/v1/scenario-requests/{$this->scenarioRequest->id}/generate");
        $res->assertStatus(202);
        $runId = $res->json('data.run_id');

        // Mark run and idempotency record as failed (e.g. from validation error)
        $run = GenerationRun::find($runId);
        $run->update([
            'status' => 'failed',
            'error_code' => 'VALIDATION_FAILED',
            'error_message' => 'Schema error during generation',
            'completed_at' => now(),
        ]);

        $idempotencyRecord = IdempotencyKey::where('key', $key)->first();
        app(\App\Services\IdempotencyService::class)->markFailed($idempotencyRecord, 422, [
            'error' => 'GENERATION_VALIDATION_FAILED',
            'message' => 'Schema error during generation',
            'run_id' => $runId,
            'status' => 'failed',
        ]);

        // Repeat request with the SAME key
        $resRepeat = $this->actingAs($this->teacher)
            ->withHeader('Idempotency-Key', $key)
            ->postJson("/api/v1/scenario-requests/{$this->scenarioRequest->id}/generate");

        // Must predictably replay the failed response without 500 error
        $resRepeat->assertStatus(422)
            ->assertJsonPath('error', 'GENERATION_VALIDATION_FAILED')
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('run_id', $runId);
    }

    public function test_repeat_with_new_key_after_failed_allows_explicit_retry_up_to_limit(): void
    {
        \Illuminate\Support\Facades\Queue::fake();

        // Failed attempt 1
        $key1 = (string) \Illuminate\Support\Str::uuid();
        $res1 = $this->actingAs($this->teacher)
            ->withHeader('Idempotency-Key', $key1)
            ->postJson("/api/v1/scenario-requests/{$this->scenarioRequest->id}/generate");
        $res1->assertStatus(202);
        $runId1 = $res1->json('data.run_id');
        GenerationRun::where('id', $runId1)->update([
            'status' => 'failed',
            'error_code' => 'ERR_1',
            'completed_at' => now(),
        ]);

        // Failed attempt 2 with new key
        $key2 = (string) \Illuminate\Support\Str::uuid();
        $res2 = $this->actingAs($this->teacher)
            ->withHeader('Idempotency-Key', $key2)
            ->postJson("/api/v1/scenario-requests/{$this->scenarioRequest->id}/generate");
        $res2->assertStatus(202);
        $runId2 = $res2->json('data.run_id');
        GenerationRun::where('id', $runId2)->update([
            'status' => 'failed',
            'error_code' => 'ERR_2',
            'completed_at' => now(),
        ]);

        // Failed attempt 3 with new key
        $key3 = (string) \Illuminate\Support\Str::uuid();
        $res3 = $this->actingAs($this->teacher)
            ->withHeader('Idempotency-Key', $key3)
            ->postJson("/api/v1/scenario-requests/{$this->scenarioRequest->id}/generate");
        $res3->assertStatus(202);
        $runId3 = $res3->json('data.run_id');
        GenerationRun::where('id', $runId3)->update([
            'status' => 'failed',
            'error_code' => 'ERR_3',
            'completed_at' => now(),
        ]);

        // Attempt 4 with new key must be rejected: limit of 3 failed retries reached
        $key4 = (string) \Illuminate\Support\Str::uuid();
        $res4 = $this->actingAs($this->teacher)
            ->withHeader('Idempotency-Key', $key4)
            ->postJson("/api/v1/scenario-requests/{$this->scenarioRequest->id}/generate");

        $res4->assertStatus(409)
            ->assertJsonPath('error.code', 'GENERATION_MAX_ATTEMPTS_EXCEEDED');
    }

    public function test_two_concurrent_generation_requests_with_different_keys_single_active_run(): void
    {
        \Illuminate\Support\Facades\Queue::fake();

        $keyA = (string) \Illuminate\Support\Str::uuid();
        $keyB = (string) \Illuminate\Support\Str::uuid();

        // Simulate concurrent execution: Transaction 1 locks the row and inserts Run A
        \Illuminate\Support\Facades\DB::transaction(function () use ($keyA) {
            $req = ScenarioRequest::where('id', $this->scenarioRequest->id)->lockForUpdate()->first();
            
            GenerationRun::create([
                'scenario_request_id' => $req->id,
                'provider_name' => 'mock',
                'model_identifier' => 'mock-v1',
                'prompt_version' => '1.0',
                'schema_version' => '1.0',
                'status' => 'queued',
                'attempts' => 1,
            ]);

            IdempotencyKey::create([
                'user_id' => $this->teacher->id,
                'endpoint' => "api/v1/scenario-requests/{$req->id}/generate",
                'key' => $keyA,
                'payload_sha256' => hash('sha256', '[]'),
                'status' => 'processing',
                'expires_at' => now()->addHours(24),
            ]);
        });

        // Request B arrives concurrently
        $resB = $this->actingAs($this->teacher)
            ->withHeader('Idempotency-Key', $keyB)
            ->postJson("/api/v1/scenario-requests/{$this->scenarioRequest->id}/generate");

        $resB->assertStatus(409)
            ->assertJsonPath('error.code', 'GENERATION_ALREADY_IN_PROGRESS');

        // Only 1 run exists
        $this->assertEquals(1, GenerationRun::where('scenario_request_id', $this->scenarioRequest->id)->count());
    }

    public function test_two_concurrent_generation_requests_with_same_key_single_run_and_replay(): void
    {
        \Illuminate\Support\Facades\Queue::fake();

        $key = (string) \Illuminate\Support\Str::uuid();

        // 1. First request
        $res1 = $this->actingAs($this->teacher)
            ->withHeader('Idempotency-Key', $key)
            ->postJson("/api/v1/scenario-requests/{$this->scenarioRequest->id}/generate");
        $res1->assertStatus(202);
        $runId = $res1->json('data.run_id');

        // 2. Second request with same key
        $res2 = $this->actingAs($this->teacher)
            ->withHeader('Idempotency-Key', $key)
            ->postJson("/api/v1/scenario-requests/{$this->scenarioRequest->id}/generate");
        $res2->assertStatus(202)
            ->assertJsonPath('data.run_id', $runId);

        $this->assertEquals(1, GenerationRun::where('scenario_request_id', $this->scenarioRequest->id)->count());
    }
}
