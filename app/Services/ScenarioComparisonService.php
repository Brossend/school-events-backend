<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\GenerateComparisonJob;
use App\Models\ScenarioComparison;
use App\Models\ScenarioRequest;
use App\Models\ScenarioRevision;
use App\Models\School;
use App\Models\User;
use App\Services\Llm\ComparisonGenerationService;
use App\Services\Llm\LlmProviderException;
use App\Services\Llm\LlmRuntime;
use App\Services\Llm\PedagogicalQualityGate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class ScenarioComparisonService
{
    public function createComparison(School $school, User $user, ScenarioRequest $request, string $comparisonType, bool $isSynthetic = false, ?string $idempotencyKey = null): ScenarioComparison
    {
        if ($request->school_id !== $school->id) {
            throw new AccessDeniedHttpException('Запрос сценария принадлежит другой образовательной организации.');
        }
        if (! in_array($comparisonType, ['universal_vs_personalized', 'cultural_context'], true)) {
            throw new UnprocessableEntityHttpException('Неизвестный тип сравнения.');
        }
        if ($idempotencyKey !== null && ! Str::isUuid($idempotencyKey)) {
            throw new UnprocessableEntityHttpException('Idempotency-Key должен быть UUID.');
        }
        $metadata = LlmRuntime::metadata();
        if ($metadata['provider_name'] === 'litellm' && config('queue.default') !== 'redis') {
            throw new LlmProviderException('LLM_CONFIGURATION_ERROR', 'Для генерации LiteLLM требуется Redis worker.');
        }
        [$comparison, $created] = DB::transaction(function () use ($school, $user, $request, $comparisonType, $isSynthetic, $idempotencyKey, $metadata) {
            School::whereKey($school->id)->lockForUpdate()->firstOrFail();
            $hash = $idempotencyKey ? hash('sha256', $user->id.':'.$idempotencyKey) : null;
            $query = ScenarioComparison::where('school_id', $school->id)->where('user_id', $user->id);
            if ($hash) {
                $existing = $query->where('differences_summary->client_key_hash', $hash)->first();
                if ($existing && ($existing->scenario_request_id !== $request->id || $existing->comparison_type !== $comparisonType)) {
                    throw new ConflictHttpException('Idempotency-Key уже использован для другого сравнения.');
                }
            } else {
                $existing = $query->where('scenario_request_id', $request->id)->where('comparison_type', $comparisonType)->where('status', 'pending')->first();
            }
            if ($existing) {
                return [$existing, false];
            }
            $c = ScenarioComparison::create(['school_id' => $school->id, 'user_id' => $user->id, 'scenario_request_id' => $request->id,
                'comparison_type' => $comparisonType, 'status' => 'pending', 'is_synthetic' => $isSynthetic,
                'variant_a_payload' => ['status' => 'pending'], 'variant_b_payload' => ['status' => 'pending'],
                'differences_summary' => ['client_key_hash' => $hash, 'provider_name' => $metadata['provider_name']], 'is_cultural_available' => false]);

            return [$c, true];
        });
        if ($created) {
            if ($metadata['provider_name'] === 'mock') {
                app(ComparisonGenerationService::class)->execute($comparison->id);
            } else {
                try {
                    GenerateComparisonJob::dispatch($comparison->id)->afterCommit();
                } catch (\Throwable) {
                    app(ComparisonGenerationService::class)->fail($comparison->id, 'DISPATCH_FAILED', 'Не удалось поставить сравнение в очередь.');
                }
            }
        }

        return $comparison->refresh();
    }

    public function recoverInterrupted(ScenarioComparison $comparison): ScenarioComparison
    {
        $started = $comparison->differences_summary['execution_started_at'] ?? null;
        if ($comparison->status === 'pending' && $started && Carbon::parse($started)->lt(now()->subSeconds(900))) {
            app(ComparisonGenerationService::class)->fail($comparison->id);

            return $comparison->refresh();
        }

        return $comparison;
    }

    public function isCulturalAvailable(ScenarioComparison $comparison): bool
    {
        if ($comparison->comparison_type !== 'cultural_context') {
            return true;
        }
        if (! $comparison->is_cultural_available || ! $comparison->variant_b_revision_id) {
            return false;
        }
        $claims = $comparison->variant_b_payload['cultural_claims'] ?? [];
        if (! $claims || collect($claims)->contains(fn ($claim) => ($claim['server_verification_status'] ?? '') !== 'verified_authentic')) {
            return false;
        }
        $revision = ScenarioRevision::find($comparison->variant_b_revision_id);

        return $revision && ! app(PedagogicalQualityGate::class)->blocksUse($revision)
            && app(ProvenanceVerificationService::class)->verifyRevisionProvenanceAuthenticity($revision)['valid'];
    }

    public function recordSelection(
        ScenarioComparison $comparison,
        User $user,
        string $selectedVariant,
        string $reason,
        ?int $culturalRating = null
    ): ScenarioComparison {
        return DB::transaction(function () use ($comparison, $user, $selectedVariant, $reason, $culturalRating) {
            /** @var ScenarioComparison|null $locked */
            $locked = ScenarioComparison::where('id', $comparison->id)
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                throw new NotFoundHttpException('Сессия сравнения сценариев не найдена.');
            }

            if ($locked->selected_variant !== null) {
                throw new ConflictHttpException('Выбор для данной сессии сравнения уже зафиксирован и не подлежит повторному изменению.');
            }

            if ($locked->comparison_type === 'cultural_context' && $selectedVariant === 'B' && ! $this->isCulturalAvailable($locked)) {
                throw new ConflictHttpException('Вариант B недоступен для выбора, так как в системе отсутствуют верифицированные первоисточники.');
            }

            // Authorization: user must belong to the school or be an admin/curator
            $isMember = $user->memberships()->where('school_id', $locked->school_id)->exists();
            $isAdminOrCurator = in_array($user->system_role, ['catalog_curator', 'system_admin'], true);
            if (! $isMember && ! $isAdminOrCurator) {
                throw new AccessDeniedHttpException('Выбор варианта может быть зафиксирован только сотрудником образовательной организации.');
            }

            $locked->selected_variant = $selectedVariant;
            $locked->selection_reason = $reason;
            $locked->cultural_usefulness_rating = $culturalRating;
            $locked->selection_recorded_at = now();
            $locked->selected_by_user_id = $user->id;
            $locked->save();

            return $locked;
        });
    }
}
