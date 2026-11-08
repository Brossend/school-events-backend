<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Scenarios\CreateScenarioRequestRequest;
use App\Jobs\ExecuteGenerationRunJob;
use App\Models\CalendarEvent;
use App\Models\GenerationRun;
use App\Models\IdempotencyKey;
use App\Models\ScenarioRequest;
use App\Models\School;
use App\Models\TraditionVersion;
use App\Services\ContextSnapshotBuilder;
use App\Services\IdempotencyService;
use App\Services\Llm\LlmRuntime;
use App\Services\TenantContext;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ScenarioRequestController extends Controller
{
    /**
     * Store a new scenario request with an immutable context snapshot.
     */
    public function store(
        CreateScenarioRequestRequest $request,
        TenantContext $tenantContext,
        ContextSnapshotBuilder $snapshotBuilder
    ): JsonResponse {
        $schoolId = $tenantContext->getSchoolId();
        $user = $request->user();

        if (! $schoolId) {
            return ApiResponse::error(
                code: 'NO_ACTIVE_SCHOOL_MEMBERSHIP',
                message: 'Для создания сценария требуется активное членство в школе.',
                details: [],
                status: 403
            );
        }

        $validated = $request->validated();

        $school = School::findOrFail($schoolId);

        // Verify calendar_event_id if provided
        if (! empty($validated['calendar_event_id'])) {
            $rawEvent = CalendarEvent::find($validated['calendar_event_id']);
            if (! $rawEvent) {
                return ApiResponse::error(
                    code: 'EVENT_NOT_FOUND',
                    message: 'Событие календаря не найдено.',
                    details: [],
                    status: 404
                );
            }

            $visibleEvent = CalendarEvent::visibleTo($user, $school)->find($validated['calendar_event_id']);
            if (! $visibleEvent) {
                if ($rawEvent->school_id && $rawEvent->school_id !== $schoolId) {
                    return ApiResponse::error(
                        code: 'FORBIDDEN_TENANT_ACCESS',
                        message: 'Событие календаря принадлежит другой образовательной организации.',
                        details: [],
                        status: 403
                    );
                }

                if ($rawEvent->region_code && $rawEvent->region_code !== $school->region_code) {
                    return ApiResponse::error(
                        code: 'FORBIDDEN_EVENT_ACCESS',
                        message: 'Региональное событие не относится к региону текущей школы.',
                        details: [],
                        status: 403
                    );
                }

                return ApiResponse::error(
                    code: 'FORBIDDEN_EVENT_ACCESS',
                    message: 'Событие календаря недоступно для вашей школы.',
                    details: [],
                    status: 403
                );
            }

            if ($visibleEvent->status !== 'approved') {
                return ApiResponse::error(
                    code: 'EVENT_STATUS_INVALID',
                    message: 'Для создания сценария могут использоваться только утверждённые события календаря.',
                    details: ['current_status' => $visibleEvent->status],
                    status: 422
                );
            }
        }

        // If tradition_version_id provided, verify existence and school ownership
        if (! empty($validated['tradition_version_id'])) {
            $traditionVersion = TraditionVersion::with('tradition')->find($validated['tradition_version_id']);
            if (! $traditionVersion) {
                return ApiResponse::error(
                    code: 'TRADITION_NOT_FOUND',
                    message: 'Версия школьной традиции не найдена.',
                    details: [],
                    status: 404
                );
            }
            if ($traditionVersion->tradition && $traditionVersion->tradition->school_id !== $schoolId && ! $user->isSystemAdmin()) {
                return ApiResponse::error(
                    code: 'FORBIDDEN_TENANT_ACCESS',
                    message: 'Выбранная версия традиции принадлежит другой школе.',
                    details: [],
                    status: 403
                );
            }
        }

        // Build immutable context snapshot
        $snapshot = $snapshotBuilder->buildSnapshot($user, $school, $validated);

        $scenarioRequest = ScenarioRequest::create([
            'school_id' => $schoolId,
            'user_id' => $user->id,
            'calendar_event_id' => $validated['calendar_event_id'] ?? null,
            'tradition_version_id' => $validated['tradition_version_id'] ?? null,
            'custom_event_title' => $validated['custom_event_title'] ?? null,
            'scale' => $validated['scale'],
            'format_type' => $validated['format_type'],
            'target_grades' => array_values($validated['target_grades']),
            'participants_count' => (int) $validated['participants_count'],
            'duration_minutes' => (int) $validated['duration_minutes'],
            'value_direction' => $validated['value_direction'],
            'family_involvement' => (bool) $validated['family_involvement'],
            'available_resources' => array_values($validated['available_resources'] ?? []),
            'pedagogical_constraints' => $validated['pedagogical_constraints'] ?? null,
            'context_snapshot' => $snapshot,
        ]);

        return ApiResponse::success($scenarioRequest, status: 201);
    }

    /**
     * Show a scenario request.
     */
    public function show(
        string $id,
        TenantContext $tenantContext,
        Request $request
    ): JsonResponse {
        $schoolId = $tenantContext->getSchoolId();
        $user = $request->user();

        $scenarioRequest = ScenarioRequest::find($id);
        if (! $scenarioRequest) {
            return ApiResponse::error(
                code: 'SCENARIO_REQUEST_NOT_FOUND',
                message: 'Запрос на сценарий не найден.',
                details: [],
                status: 404
            );
        }

        if ($scenarioRequest->school_id !== $schoolId && ! ($user && $user->isSystemAdmin())) {
            return ApiResponse::error(
                code: 'FORBIDDEN_TENANT_ACCESS',
                message: 'Доступ к запросу сценария другой школы запрещен.',
                details: [],
                status: 403
            );
        }

        $canAccess = ($user && $user->isSystemAdmin())
            || ($user && $user->hasSchoolRole($schoolId, 'school_admin'))
            || ($scenarioRequest->user_id === $user?->id);

        if (! $canAccess) {
            return ApiResponse::error(
                code: 'FORBIDDEN_AUTHOR_ACCESS',
                message: 'Доступ к запросу сценария другого педагога запрещен.',
                details: [],
                status: 403
            );
        }

        return ApiResponse::success($scenarioRequest);
    }

    /**
     * Enqueue asynchronous generation for the scenario request.
     */
    public function generate(
        string $id,
        TenantContext $tenantContext,
        Request $request,
        IdempotencyService $idempotencyService
    ): JsonResponse {
        $schoolId = $tenantContext->getSchoolId();
        $user = $request->user();

        $scenarioRequest = ScenarioRequest::find($id);
        if (! $scenarioRequest) {
            return ApiResponse::error(
                code: 'SCENARIO_REQUEST_NOT_FOUND',
                message: 'Запрос на сценарий не найден.',
                details: [],
                status: 404
            );
        }

        if ($scenarioRequest->school_id !== $schoolId && ! ($user && $user->isSystemAdmin())) {
            return ApiResponse::error(
                code: 'FORBIDDEN_TENANT_ACCESS',
                message: 'Запуск генерации для сценария другой школы запрещен.',
                details: [],
                status: 403
            );
        }

        // Author access: only author or system admin can trigger generation
        if ($scenarioRequest->user_id !== $user?->id && ! ($user && $user->isSystemAdmin())) {
            return ApiResponse::error(
                code: 'FORBIDDEN_AUTHOR_ACCESS',
                message: 'Запуск генерации сценария разрешен только автору запроса.',
                details: [],
                status: 403
            );
        }

        // Require Idempotency-Key header with valid UUID
        $idempotencyKey = $request->header('Idempotency-Key');
        if (empty($idempotencyKey)) {
            return ApiResponse::error(
                code: 'IDEMPOTENCY_KEY_REQUIRED',
                message: 'Заголовок Idempotency-Key обязателен для запуска генерации.',
                details: [],
                status: 422
            );
        }

        if (! preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $idempotencyKey)) {
            return ApiResponse::error(
                code: 'IDEMPOTENCY_KEY_INVALID',
                message: 'Заголовок Idempotency-Key должен содержать валидный UUID.',
                details: [],
                status: 422
            );
        }

        $dispatchInfo = null;

        $response = DB::transaction(function () use ($id, $user, $request, $idempotencyKey, $idempotencyService, &$dispatchInfo) {
            // Row lock on scenario_requests to strictly serialize concurrent generation attempts
            $scenarioRequest = ScenarioRequest::where('id', $id)->lockForUpdate()->first();
            if (! $scenarioRequest) {
                return ApiResponse::error(
                    code: 'SCENARIO_REQUEST_NOT_FOUND',
                    message: 'Запрос на сценарий не найден.',
                    details: [],
                    status: 404
                );
            }

            // Check or acquire idempotency key
            try {
                $check = $idempotencyService->checkOrAcquire(
                    $user,
                    $request->path(),
                    $idempotencyKey,
                    $request->all()
                );

                if ($check['replayed']) {
                    return response()->json($check['body'], $check['code']);
                }

                $idempotencyRecord = $check['record'];
            } catch (ConflictHttpException $ce) {
                $decoded = json_decode($ce->getMessage(), true) ?: ['message' => $ce->getMessage()];

                return ApiResponse::error(
                    code: $decoded['error'] ?? 'IDEMPOTENCY_CONFLICT',
                    message: $decoded['message'] ?? 'Конфликт ключа идемпотентности.',
                    details: ['run_id' => $decoded['run_id'] ?? null],
                    status: 409
                );
            }

            // 1. If scenario v1 is already generated for this request, reject new key
            if ($scenarioRequest->scenario()->exists()) {
                $idempotencyRecord?->delete();

                return ApiResponse::error(
                    code: 'SCENARIO_ALREADY_GENERATED',
                    message: 'Сценарий для данного запроса уже сгенерирован.',
                    details: ['scenario_id' => $scenarioRequest->scenario->id],
                    status: 409
                );
            }

            // 2. Check if an active generation run (queued or running) already exists
            $activeRun = GenerationRun::where('scenario_request_id', $scenarioRequest->id)
                ->whereIn('status', ['queued', 'running'])
                ->lockForUpdate()
                ->first();

            if ($activeRun !== null) {
                // Running recovery must exceed the full model/repair budget and job timeout.
                $execution = json_decode($activeRun->sanitized_prompt ?? '', true);
                $started = $activeRun->status === 'running' && is_array($execution) && isset($execution['execution_started_at'])
                    ? Carbon::parse($execution['execution_started_at']) : $activeRun->created_at;
                if ($started < now()->subSeconds($activeRun->status === 'running' ? 480 : 180)) {
                    $activeRun->update([
                        'status' => 'failed',
                        'error_code' => 'GENERATION_TIMED_OUT',
                        'error_message' => 'Предыдущая попытка генерации зависла или превысила таймаут очереди.',
                        'completed_at' => now(),
                    ]);
                    $activeRun = null;
                } else {
                    $idempotencyRecord?->delete();

                    return ApiResponse::error(
                        code: 'GENERATION_ALREADY_IN_PROGRESS',
                        message: 'Генерация для данного запроса уже выполняется.',
                        details: [
                            'generation_run_id' => $activeRun->id,
                            'run_id' => $activeRun->id,
                            'status' => $activeRun->status,
                        ],
                        status: 409
                    );
                }
            }

            // 3. Check failed attempts limit for explicit retry policy
            $failedCount = GenerationRun::where('scenario_request_id', $scenarioRequest->id)
                ->where('status', 'failed')
                ->count();

            if ($failedCount >= 3) {
                $idempotencyRecord?->delete();

                return ApiResponse::error(
                    code: 'GENERATION_MAX_ATTEMPTS_EXCEEDED',
                    message: 'Превышено максимальное число попыток генерации для данного запроса (3). Создайте новый запрос.',
                    details: ['failed_attempts' => $failedCount],
                    status: 409
                );
            }

            // 4. Create GenerationRun record protected by PostgreSQL partial unique index
            $inTx = DB::transactionLevel() > 0;
            try {
                if ($inTx) {
                    DB::statement('SAVEPOINT gen_run_insert');
                }
                $run = GenerationRun::create([
                    'scenario_request_id' => $scenarioRequest->id,
                    ...LlmRuntime::metadata(),
                    'status' => 'queued',
                    'attempts' => $failedCount + 1,
                    'created_at' => now(),
                ]);
                if ($inTx) {
                    DB::statement('RELEASE SAVEPOINT gen_run_insert');
                }
            } catch (QueryException $qe) {
                if ($inTx) {
                    DB::statement('ROLLBACK TO SAVEPOINT gen_run_insert');
                }
                $sqlState = (string) ($qe->errorInfo[0] ?? $qe->getCode());
                if ($sqlState === '23505') {
                    $idempotencyRecord?->delete();

                    return ApiResponse::error(
                        code: 'GENERATION_ALREADY_IN_PROGRESS',
                        message: 'Генерация для данного запроса уже выполняется.',
                        details: [],
                        status: 409
                    );
                }
                throw $qe;
            }

            $responsePayload = [
                'generation_run_id' => $run->id,
                'run_id' => $run->id,
                'scenario_request_id' => $scenarioRequest->id,
                'status' => 'queued',
                'estimated_wait_seconds' => 15,
                'poll_url' => "/api/v1/generation-runs/{$run->id}",
                'created_at' => $run->created_at->toIso8601String(),
            ];

            if ($idempotencyRecord) {
                $idempotencyService->attachResponsePayload($idempotencyRecord, [
                    'data' => $responsePayload,
                ], 202);
            }

            $dispatchInfo = [
                'run_id' => $run->id,
                'idempotency_key_id' => $idempotencyRecord?->id,
            ];

            return ApiResponse::success($responsePayload, status: 202);
        });

        // 5. Dispatch async job strictly AFTER transaction commit
        if ($dispatchInfo !== null) {
            try {
                ExecuteGenerationRunJob::dispatch(
                    $dispatchInfo['run_id'],
                    $dispatchInfo['idempotency_key_id']
                )->afterCommit();
            } catch (\Throwable $e) {
                GenerationRun::where('id', $dispatchInfo['run_id'])->update([
                    'status' => 'failed',
                    'error_code' => 'DISPATCH_FAILED',
                    'error_message' => 'Не удалось поставить задачу в очередь.',
                ]);
                if (! empty($dispatchInfo['idempotency_key_id'])) {
                    IdempotencyKey::where('id', $dispatchInfo['idempotency_key_id'])->delete();
                }
                throw $e;
            }
        }

        return $response;
    }
}
