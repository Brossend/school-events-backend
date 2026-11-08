<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\GenerationRun;
use App\Models\IdempotencyKey;
use App\Models\Scenario;
use App\Models\VerificationRun;
use App\Services\IdempotencyService;
use App\Services\Llm\GenerationOutputValidator;
use App\Services\Llm\LlmProviderException;
use App\Services\Llm\LlmProviderInterface;
use App\Services\Llm\LlmRuntime;
use App\Services\Llm\ScenarioSemanticValidator;
use App\Services\Llm\ServerNormalizationService;
use App\Services\Llm\TrustedGenerationContext;
use App\Services\Moderation\ModerationEngineService;
use App\Services\ScenarioPersistenceService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class ExecuteGenerationRunJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 420;

    public function __construct(
        public readonly string $runId,
        public readonly ?string $idempotencyKeyId = null
    ) {}

    public function handle(
        LlmProviderInterface $llmProvider,
        GenerationOutputValidator $outputValidator,
        ScenarioSemanticValidator $semanticValidator,
        ServerNormalizationService $normalizer,
        ScenarioPersistenceService $persister,
        IdempotencyService $idempotencyService
    ): void {
        $run = DB::transaction(function () {
            $r = GenerationRun::lockForUpdate()->find($this->runId);
            if (! $r || $r->status !== 'queued') {
                return null;
            }
            $r->update([
                'status' => 'running',
                'attempts' => $r->attempts + 1,
                'sanitized_prompt' => json_encode(['execution_started_at' => now()->toIso8601String()]),
            ]);

            return $r;
        });

        if (! $run) {
            return;
        }

        $startTime = microtime(true);

        $request = $run->scenarioRequest;
        if (! $request) {
            $run->update([
                'status' => 'failed',
                'error_code' => 'REQUEST_NOT_FOUND',
                'error_message' => 'Associated ScenarioRequest not found.',
                'completed_at' => now(),
            ]);

            return;
        }

        // Safety guard: if scenario v1 already exists, do not recreate or create duplicate revision
        $existingScenario = Scenario::where('scenario_request_id', $request->id)->first();
        if ($existingScenario) {
            $run->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);
            if ($this->idempotencyKeyId) {
                $idempotencyRecord = IdempotencyKey::find($this->idempotencyKeyId);
                if ($idempotencyRecord) {
                    $idempotencyService->markCompleted($idempotencyRecord, 202, [
                        'data' => [
                            'generation_run_id' => $run->id,
                            'run_id' => $run->id,
                            'scenario_request_id' => $request->id,
                            'scenario_id' => $existingScenario->id,
                            'status' => 'completed',
                            'estimated_wait_seconds' => 0,
                            'poll_url' => "/api/v1/generation-runs/{$run->id}",
                        ],
                    ]);
                }
            }

            return;
        }

        try {
            if ($run->provider_name === 'litellm' && LlmRuntime::metadata()['provider_name'] !== 'litellm') {
                throw new LlmProviderException('LLM_CONFIGURATION_CHANGED', 'Провайдер изменён после постановки задания. Mock не заменяет реальную генерацию.');
            }
            // Step 1: Call LLM Provider (Mock or configured engine)
            $generationResult = $llmProvider->generate($request);
            $rawOutput = $generationResult['output_payload'];
            $run->update(array_intersect_key($generationResult, array_flip(['provider_name', 'model_identifier', 'prompt_version', 'schema_version'])));

            $run->update([
                'prompt_tokens' => $generationResult['prompt_tokens'] ?? null,
                'completion_tokens' => $generationResult['completion_tokens'] ?? null,
                'sanitized_prompt' => $generationResult['sanitized_prompt'] ?? null,
                'sanitized_response' => $rawOutput,
            ]);

            // Step 2: Contour 1 - JSON Schema Draft 2020-12 Validation
            $outputValidator->validate($rawOutput);

            // Step 3: Contour 2 - Semantic Pedagogical Validation
            $semanticValidator->validate($rawOutput, $request->context_snapshot);

            // Step 4: Contour 3 - Server Normalization and Provenance Binding
            $normalizationContext = $generationResult['provider_name'] === 'litellm'
                ? app(TrustedGenerationContext::class)->forRequest($request) : $request->context_snapshot;
            $normalizedPayload = $normalizer->normalize($rawOutput, $normalizationContext);

            // Step 5: Atomic DB Persistence
            $scenario = DB::transaction(function () use ($request, $run, $persister, $normalizedPayload, $generationResult) {
                $locked = GenerationRun::lockForUpdate()->findOrFail($run->id);
                if ($locked->status !== 'running') {
                    throw new LlmProviderException('LLM_EXECUTION_INTERRUPTED', 'Выполнение этой попытки уже прервано.');
                }
                $scenario = $persister->persist($request, $run, $normalizedPayload);
                $locked->update(['status' => 'completed', 'latency_ms' => $generationResult['latency_ms'] ?? null, 'completed_at' => now()]);

                return $scenario;
            });

            // Automatically trigger moderation for newly generated revision v1 (idempotent: no duplicate on job retry)
            $v1Revision = $scenario->currentRevision ?? $scenario->revisions()->where('version_number', 1)->first();
            if ($v1Revision) {
                $existingRun = VerificationRun::where('revision_id', $v1Revision->id)->first();
                if (! $existingRun) {
                    $vRun = VerificationRun::create([
                        'revision_id' => $v1Revision->id,
                        'rule_engine_version' => ModerationEngineService::RULE_ENGINE_VERSION,
                        'status' => 'running',
                        'p0_violations_count' => 0,
                        'p1_violations_count' => 0,
                        'execution_time_ms' => null,
                        'created_at' => now(),
                    ]);

                    $v1Revision->moderationState()->updateOrCreate(
                        ['revision_id' => $v1Revision->id],
                        [
                            'status' => 'running',
                            'latest_verification_run_id' => $vRun->id,
                            'updated_at' => now(),
                        ]
                    );

                    RunVerificationJob::dispatch($v1Revision->id, $vRun->id)->afterCommit();
                }
            }

            $latencyMs = (int) round((microtime(true) - $startTime) * 1000);
            $run->update([
                'status' => 'completed',
                'latency_ms' => $generationResult['latency_ms'] ?? $latencyMs,
                'completed_at' => now(),
            ]);

            // Update idempotency record if present
            if ($this->idempotencyKeyId) {
                $idempotencyRecord = IdempotencyKey::find($this->idempotencyKeyId);
                if ($idempotencyRecord) {
                    $idempotencyService->markCompleted($idempotencyRecord, 202, [
                        'data' => [
                            'generation_run_id' => $run->id,
                            'run_id' => $run->id,
                            'scenario_request_id' => $request->id,
                            'scenario_id' => $scenario->id,
                            'status' => 'completed',
                            'estimated_wait_seconds' => 0,
                            'poll_url' => "/api/v1/generation-runs/{$run->id}",
                        ],
                    ]);
                }
            }

            Log::info("Generation run {$run->id} completed successfully for scenario {$scenario->id}.");
        } catch (ValidationException $ve) {
            $latencyMs = (int) round((microtime(true) - $startTime) * 1000);
            $errorMessage = json_encode($ve->errors(), JSON_UNESCAPED_UNICODE);

            $run->update([
                'status' => 'failed',
                'error_code' => 'VALIDATION_FAILED',
                'error_message' => $errorMessage,
                'latency_ms' => $latencyMs,
                'completed_at' => now(),
            ]);

            if ($this->idempotencyKeyId) {
                $idempotencyRecord = IdempotencyKey::find($this->idempotencyKeyId);
                if ($idempotencyRecord) {
                    $idempotencyService->markFailed($idempotencyRecord, 422, [
                        'error' => 'GENERATION_VALIDATION_FAILED',
                        'message' => $errorMessage,
                        'run_id' => $run->id,
                    ]);
                }
            }

            Log::warning("Generation run {$run->id} failed validation: {$errorMessage}");
        } catch (LlmProviderException $e) {
            $this->failSafely($e->errorCode, $e->getMessage(), $e->metadata);
        } catch (Throwable $e) {
            $latencyMs = (int) round((microtime(true) - $startTime) * 1000);

            $run->update([
                'status' => 'failed',
                'error_code' => 'UNHANDLED_EXCEPTION',
                'error_message' => 'Не удалось завершить генерацию.',
                'latency_ms' => $latencyMs,
                'completed_at' => now(),
            ]);

            if ($this->idempotencyKeyId) {
                $idempotencyRecord = IdempotencyKey::find($this->idempotencyKeyId);
                if ($idempotencyRecord) {
                    $idempotencyService->markFailed($idempotencyRecord, 500, [
                        'error' => 'GENERATION_EXECUTION_ERROR',
                        'message' => 'Не удалось завершить генерацию.',
                        'run_id' => $run->id,
                    ]);
                }
            }

            Log::error('Generation execution failed', ['run_id' => $run->id, 'exception_class' => get_class($e)]);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $code = $exception instanceof LlmProviderException ? $exception->errorCode : 'LLM_EXECUTION_INTERRUPTED';
        $message = $exception instanceof LlmProviderException ? $exception->getMessage() : 'Задача прервана. Автоматический повтор платного запроса не выполняется.';
        $this->failSafely($code, $message);
    }

    private function failSafely(string $code, string $message, array $metadata = []): void
    {
        $run = GenerationRun::find($this->runId);
        if (! $run || in_array($run->status, ['completed', 'failed'], true)) {
            return;
        }
        $allowed = array_intersect_key($metadata, array_flip(['provider_name', 'model_identifier', 'prompt_version', 'schema_version', 'latency_ms', 'prompt_tokens', 'completion_tokens', 'sanitized_prompt']));
        if (is_array($metadata['output_payload'] ?? null)) {
            $allowed['sanitized_response'] = $metadata['output_payload'];
        }
        $run->update([...$allowed, 'status' => 'failed', 'error_code' => $code, 'error_message' => $message, 'completed_at' => now()]);
        if ($this->idempotencyKeyId && ($record = IdempotencyKey::find($this->idempotencyKeyId))) {
            app(IdempotencyService::class)->markFailed($record, 503, ['error' => $code, 'message' => $message, 'run_id' => $run->id]);
        }
    }
}
