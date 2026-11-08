<?php

declare(strict_types=1);

namespace App\Services\Llm;

use App\Models\GenerationRun;
use App\Models\ScenarioComparison;
use App\Services\Moderation\ModerationEngineService;
use App\Services\ProvenanceVerificationService;
use App\Services\ScenarioPersistenceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ComparisonGenerationService
{
    public function execute(string $id): void
    {
        $comparison = DB::transaction(function () use ($id) {
            $c = ScenarioComparison::lockForUpdate()->find($id);
            if (! $c || $c->status !== 'pending' || isset($c->differences_summary['execution_started_at'])) {
                return null;
            }
            $c->update(['differences_summary' => [...$c->differences_summary, 'execution_started_at' => now()->toIso8601String()]]);

            return $c;
        });
        if (! $comparison) {
            return;
        }
        $run = null;
        try {
            if (($comparison->differences_summary['provider_name'] ?? null) === 'litellm' && LlmRuntime::metadata()['provider_name'] !== 'litellm') {
                throw new LlmProviderException('LLM_CONFIGURATION_CHANGED', 'Провайдер сравнения изменён. Mock не заменяет реальную генерацию.');
            }
            $request = clone $comparison->scenarioRequest;
            $context = app(TrustedGenerationContext::class)->forRequest($request);
            $request->context_snapshot = $context;
            $cultural = $comparison->comparison_type === 'cultural_context';
            $available = ! $cultural || ! empty($context['cultural_provenance']['claims']);
            $modes = $cultural ? ['A' => 'without_cultural_context', 'B' => 'with_cultural_context'] : ['A' => 'universal', 'B' => 'personalized'];
            $pairs = [];
            $provider = app(VariantLlmProviderInterface::class);
            foreach ($modes as $key => $mode) {
                if ($key === 'B' && ! $available) {
                    continue;
                }
                // Prepare audit identity in a short transaction; external HTTP is outside it.
                [$variantRequest, $run] = DB::transaction(function () use ($request, $comparison, $key) {
                    $copy = $request->replicate();
                    $copy->save();
                    $r = GenerationRun::create(['scenario_request_id' => $copy->id, ...LlmRuntime::metadata(),
                        'status' => 'running', 'attempts' => 1, 'created_at' => now()]);
                    $c = ScenarioComparison::lockForUpdate()->findOrFail($comparison->id);
                    $summary = $c->differences_summary;
                    $summary['generation_run_ids'][$key] = $r->id;
                    $c->update(['differences_summary' => $summary]);

                    return [$copy, $r];
                });
                $result = $provider->generateVariant($variantRequest, $mode);
                $run->update([...array_intersect_key($result, array_flip(['provider_name', 'model_identifier', 'prompt_version', 'schema_version', 'latency_ms', 'prompt_tokens', 'completion_tokens', 'sanitized_prompt'])),
                    'sanitized_response' => $result['output_payload']]);
                app(GenerationOutputValidator::class)->validate($result['output_payload']);
                app(ScenarioSemanticValidator::class)->validate($result['output_payload'], $context);
                $normalized = app(ServerNormalizationService::class)->normalize($result['output_payload'], app(TrustedGenerationContext::class)->forRequest($variantRequest));
                if ($cultural && $key === 'B' && (empty($normalized['cultural_claims'])
                    || collect($normalized['cultural_claims'])->contains(fn ($claim) => $claim['server_verification_status'] !== 'verified_authentic'))) {
                    throw new LlmProviderException('LLM_PROVENANCE_MISSING', 'Культурный вариант не сохранил допустимые доказательства.');
                }
                $pairs[$key] = [$variantRequest, $run, $normalized];
            }
            DB::transaction(function () use ($comparison, $pairs, $available, $cultural) {
                $locked = ScenarioComparison::lockForUpdate()->findOrFail($comparison->id);
                if ($locked->status !== 'pending') {
                    throw new LlmProviderException('LLM_EXECUTION_INTERRUPTED', 'Сравнение уже завершено или прервано.');
                }
                $fields = [];
                foreach ($pairs as $key => [$request, $generation, $payload]) {
                    $scenario = app(ScenarioPersistenceService::class)->persist($request, $generation, $payload);
                    $revision = $scenario->currentRevision;
                    app(ModerationEngineService::class)->verifyRevision($revision);
                    if ($cultural && $key === 'B' && ! app(ProvenanceVerificationService::class)->verifyRevisionProvenanceAuthenticity($revision)['valid']) {
                        throw new LlmProviderException('LLM_PROVENANCE_CHANGED', 'Доказательства изменились во время генерации.');
                    }
                    $generation->update(['status' => 'completed', 'completed_at' => now()]);
                    $letter = strtolower($key);
                    $fields["variant_{$letter}_scenario_id"] = $scenario->id;
                    $fields["variant_{$letter}_revision_id"] = $revision->id;
                    $fields["variant_{$letter}_payload"] = $payload;
                }
                if (! $available) {
                    $fields['variant_b_payload'] = ['status' => 'unavailable_no_provenance', 'message' => 'Нет подтверждённых источников.', 'cultural_claims' => []];
                }
                $summary = [...$locked->differences_summary, 'comparison_dimension' => $locked->comparison_type,
                    'pedagogical_approach' => ['variant_a' => $pairs['A'][2]['pedagogical_goal'],
                        'variant_b' => $pairs['B'][2]['pedagogical_goal'] ?? 'Недоступен без подтверждённых источников.']];
                if ($cultural) {
                    $summary['regional_component'] = ['variant_a' => 'Без культурных утверждений', 'variant_b' => $available ? 'Утверждения привязаны к серверным доказательствам' : 'Нет подтверждённых источников'];
                    $summary['is_cultural_available'] = $available;
                    $summary['evidence_gate_status'] = $available ? 'VERIFIED_AUTHENTIC' : 'UNAVAILABLE_NO_PROVENANCE';
                }
                $locked->update([...$fields, 'differences_summary' => $summary, 'is_cultural_available' => $available, 'status' => 'completed']);
            });
        } catch (\Throwable $e) {
            if ($e instanceof LlmProviderException && $run) {
                $run->update(array_intersect_key($e->metadata, array_flip(['latency_ms', 'prompt_tokens', 'completion_tokens', 'sanitized_prompt'])));
                if (is_array($e->metadata['output_payload'] ?? null)) {
                    $run->update(['sanitized_response' => $e->metadata['output_payload']]);
                }
            }
            $code = $e instanceof LlmProviderException ? $e->errorCode : ($e instanceof ValidationException ? 'VALIDATION_FAILED' : 'COMPARISON_GENERATION_FAILED');
            $message = $e instanceof LlmProviderException ? $e->getMessage() : ($e instanceof ValidationException ? json_encode($e->errors(), JSON_UNESCAPED_UNICODE) : 'Не удалось завершить сравнение.');
            $this->fail($id, $code, $message);
        }
    }

    public function fail(string $id, string $code = 'LLM_EXECUTION_INTERRUPTED', string $message = 'Генерация сравнения прервана. Автоматического повтора платных запросов нет.'): void
    {
        DB::transaction(function () use ($id, $code, $message) {
            $c = ScenarioComparison::lockForUpdate()->find($id);
            if (! $c || $c->status !== 'pending') {
                return;
            }
            foreach ($c->differences_summary['generation_run_ids'] ?? [] as $runId) {
                GenerationRun::whereKey($runId)->whereIn('status', ['running', 'queued'])->update(['status' => 'failed', 'error_code' => $code, 'error_message' => $message, 'completed_at' => now()]);
            }
            $c->update(['status' => 'failed', 'is_cultural_available' => false,
                'differences_summary' => [...$c->differences_summary, 'error_code' => $code, 'error_message' => $message]]);
        });
    }
}
