<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ExecuteGenerationRunJob;
use App\Jobs\GenerateComparisonJob;
use App\Models\GenerationRun;
use App\Models\Scenario;
use App\Models\ScenarioRequest;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Services\Llm\ComparisonGenerationService;
use App\Services\Llm\LlmRuntime;
use App\Services\ScenarioComparisonService;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

// Intentionally no enclosing DatabaseTransactions: enforce HTTP at transactionLevel=0.
// All retained records are isolated synthetic LLM001 test data.
class LiteLlmPipelineTest extends TestCase
{
    private School $school;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['llm.provider' => 'litellm', 'llm.api_key' => 'unit-only-not-a-live-key', 'llm.model' => 'unit-model',
            'llm.base_url' => 'https://gateway.example.test/v1', 'llm.max_retry_wait' => 0, 'llm.repair_passes' => 0]);
        $this->school = School::create(['name' => 'LLM001 synthetic PHPUnit '.Str::uuid(), 'region_code' => '66', 'city' => 'Тестовый город', 'inn' => (string) random_int(1000000000, 9999999999), 'settings' => []]);
        $this->teacher = User::factory()->create(['name' => 'LLM001 synthetic PHPUnit teacher']);
        SchoolMembership::create(['school_id' => $this->school->id, 'user_id' => $this->teacher->id, 'role' => 'teacher', 'is_default' => true]);
    }

    private function requestFixture(string $scale = 'class_level'): ScenarioRequest
    {
        $request = LiteLlmProviderTest::requestFixture($scale);
        $request->school_id = $this->school->id;
        $request->user_id = $this->teacher->id;
        $request->save();

        return $request;
    }

    public function test_three_scales_pass_full_pipeline_and_reexecuting_job_does_not_call_again(): void
    {
        foreach (['class_level', 'all_school', 'family_community'] as $scale) {
            Http::swap(new Factory);
            Http::preventStrayRequests();
            $request = $this->requestFixture($scale);
            $payload = LiteLlmProviderTest::readyPayload($request);
            Http::fake(['*' => function () use ($payload) {
                $this->assertSame(0, DB::transactionLevel());

                return Http::response(LiteLlmProviderTest::completion($payload));
            }]);
            $run = GenerationRun::create(['scenario_request_id' => $request->id, ...LlmRuntime::metadata(), 'status' => 'queued', 'attempts' => 0, 'created_at' => now()]);
            $job = new ExecuteGenerationRunJob($run->id);
            app()->call([$job, 'handle']);
            app()->call([$job, 'handle']);
            $run->refresh();
            $this->assertSame('completed', $run->status);
            $this->assertSame('litellm', $run->provider_name);
            $this->assertSame('actual-resolved-model', $run->model_identifier);
            $this->assertSame(17, $run->prompt_tokens);
            $this->assertSame(1, Scenario::where('scenario_request_id', $request->id)->count());
            $scenario = Scenario::where('scenario_request_id', $request->id)->firstOrFail();
            $this->assertSame(1, $scenario->currentRevision->version_number);
            $this->assertSame($scale, $scenario->currentRevision->format['scale']);
            $this->assertNotNull($scenario->currentRevision->moderationState);
            Http::assertSentCount(1);
        }
    }

    public function test_missing_fields_and_semantic_errors_are_not_persisted_as_scenarios(): void
    {
        foreach (['schema', 'semantic'] as $kind) {
            Http::swap(new Factory);
            Http::preventStrayRequests();
            $request = $this->requestFixture();
            $payload = LiteLlmProviderTest::readyPayload($request);
            if ($kind === 'schema') {
                unset($payload['concept']);
            } else {
                $payload['stages'][0]['duration_minutes']++;
            }
            Http::fake(['*' => Http::response(LiteLlmProviderTest::completion($payload))]);
            $run = GenerationRun::create(['scenario_request_id' => $request->id, ...LlmRuntime::metadata(), 'status' => 'queued', 'attempts' => 0, 'created_at' => now()]);
            app()->call([new ExecuteGenerationRunJob($run->id), 'handle']);
            $this->assertSame('failed', $run->refresh()->status);
            $this->assertSame('VALIDATION_FAILED', $run->error_code);
            $this->assertStringContainsString($kind === 'schema' ? 'generation_schema' : 'RULE_TIMING_SUM_MISMATCH', $run->error_message);
            $this->assertSame(31, $run->completion_tokens);
            $this->assertSame(0, Scenario::where('scenario_request_id', $request->id)->count());
        }
    }

    public function test_same_http_idempotency_key_reuses_paid_result_and_metadata(): void
    {
        $request = $this->requestFixture();
        Http::fake(['*' => Http::response(LiteLlmProviderTest::completion(LiteLlmProviderTest::readyPayload($request)))]);
        $key = (string) Str::uuid();
        $this->actingAs($this->teacher, 'web');
        $headers = ['X-School-Id' => $this->school->id, 'Idempotency-Key' => $key];
        $first = $this->postJson('/api/v1/scenario-requests/'.$request->id.'/generate', [], $headers)->assertStatus(202);
        $runId = $first->json('data.run_id');
        $this->postJson('/api/v1/scenario-requests/'.$request->id.'/generate', [], $headers)->assertStatus(202)->assertJsonPath('data.run_id', $runId);
        $this->assertSame('litellm', GenerationRun::findOrFail($runId)->provider_name);
        $scenario = Scenario::where('scenario_request_id', $request->id)->firstOrFail();
        $this->getJson('/api/v1/scenarios/'.$scenario->id, ['X-School-Id' => $this->school->id])
            ->assertOk()->assertJsonPath('data.generation.provider_name', 'litellm')
            ->assertJsonPath('data.generation.model_identifier', 'actual-resolved-model');
        Http::assertSentCount(1);
    }

    public function test_ab_is_durable_idempotent_and_generates_two_distinct_prompts_outside_transactions(): void
    {
        config(['queue.default' => 'redis']);
        Queue::fake();
        $request = $this->requestFixture();
        $prompts = [];
        Http::fake(['*' => function ($httpRequest) use ($request, &$prompts) {
            $this->assertSame(0, DB::transactionLevel());
            $prompts[] = $httpRequest['messages'][0]['content'];

            return Http::response(LiteLlmProviderTest::completion(LiteLlmProviderTest::readyPayload($request)));
        }]);
        $service = app(ScenarioComparisonService::class);
        $key = (string) Str::uuid();
        $comparison = $service->createComparison($this->school, $this->teacher, $request, 'universal_vs_personalized', true, $key);
        $this->assertSame('pending', $comparison->status);
        Http::assertNothingSent();
        Queue::assertPushed(GenerateComparisonJob::class, 1);
        $this->assertSame($comparison->id, $service->createComparison($this->school, $this->teacher, $request, 'universal_vs_personalized', true, $key)->id);
        app(ComparisonGenerationService::class)->execute($comparison->id);
        app(ComparisonGenerationService::class)->execute($comparison->id);
        $comparison->refresh();
        $this->assertSame('completed', $comparison->status);
        $this->assertNotNull($comparison->variant_a_revision_id);
        $this->assertNotNull($comparison->variant_b_revision_id);
        $this->assertNotSame($prompts[0], $prompts[1]);
        Http::assertSentCount(2);
        foreach ($comparison->differences_summary['generation_run_ids'] as $runId) {
            $this->assertSame('actual-resolved-model', GenerationRun::findOrFail($runId)->model_identifier);
        }
        $this->assertSame($comparison->id, $service->createComparison($this->school, $this->teacher, $request, 'universal_vs_personalized', true, $key)->id);
        Http::assertSentCount(2);
    }

    public function test_ab_partial_failure_preserves_usage_and_never_claims_completed(): void
    {
        config(['queue.default' => 'redis']);
        Queue::fake();
        $request = $this->requestFixture();
        Http::fake(['*' => Http::sequence()->push(LiteLlmProviderTest::completion(LiteLlmProviderTest::readyPayload($request)))->push([], 401)]);
        $comparison = app(ScenarioComparisonService::class)->createComparison($this->school, $this->teacher, $request, 'universal_vs_personalized', true);
        app(ComparisonGenerationService::class)->execute($comparison->id);
        $comparison->refresh();
        $this->assertSame('failed', $comparison->status);
        $this->assertNull($comparison->variant_a_scenario_id);
        $runA = GenerationRun::findOrFail($comparison->differences_summary['generation_run_ids']['A']);
        $this->assertSame(17, $runA->prompt_tokens);
        $this->assertSame('failed', $runA->status);
        $this->assertSame('LLM_AUTHENTICATION_FAILED', $comparison->differences_summary['error_code']);
    }

    public function test_worker_interrupt_is_terminal_and_safe(): void
    {
        $request = $this->requestFixture();
        $run = GenerationRun::create(['scenario_request_id' => $request->id, ...LlmRuntime::metadata(), 'status' => 'running', 'attempts' => 1, 'created_at' => now()]);
        (new ExecuteGenerationRunJob($run->id))->failed(new \RuntimeException('sensitive internal detail'));
        $this->assertSame('LLM_EXECUTION_INTERRUPTED', $run->refresh()->error_code);
        $this->assertStringNotContainsString('sensitive internal detail', $run->error_message);
        app()->call([new ExecuteGenerationRunJob($run->id), 'handle']);
        Http::assertNothingSent();
    }

    public function test_provider_change_after_enqueue_cannot_substitute_mock(): void
    {
        $request = $this->requestFixture();
        $run = GenerationRun::create(['scenario_request_id' => $request->id, ...LlmRuntime::metadata(), 'status' => 'queued', 'attempts' => 0, 'created_at' => now()]);
        config(['llm.provider' => 'mock']);
        app()->call([new ExecuteGenerationRunJob($run->id), 'handle']);
        $this->assertSame('LLM_CONFIGURATION_CHANGED', $run->refresh()->error_code);
        $this->assertSame('litellm', $run->provider_name);
        $this->assertSame(0, Scenario::where('scenario_request_id', $request->id)->count());
        Http::assertNothingSent();
    }
}
