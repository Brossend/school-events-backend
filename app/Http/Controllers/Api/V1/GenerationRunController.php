<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\GenerationRun;
use App\Services\TenantContext;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GenerationRunController extends Controller
{
    /**
     * Show generation run details and status.
     */
    public function show(
        string $id,
        TenantContext $tenantContext,
        Request $request
    ): JsonResponse {
        $schoolId = $tenantContext->getSchoolId();
        $user = $request->user();

        $run = GenerationRun::with('scenarioRequest')->find($id);
        if (! $run) {
            return ApiResponse::error(
                code: 'GENERATION_RUN_NOT_FOUND',
                message: 'Запуск генерации не найден.',
                details: [],
                status: 404
            );
        }

        if ($run->scenarioRequest && $run->scenarioRequest->school_id !== $schoolId && ! ($user && $user->isSystemAdmin())) {
            return ApiResponse::error(
                code: 'FORBIDDEN_TENANT_ACCESS',
                message: 'Доступ к запуску генерации другой школы запрещен.',
                details: [],
                status: 403
            );
        }

        $authorId = $run->scenarioRequest?->user_id;
        $canAccess = ($user && $user->isSystemAdmin())
            || ($run->scenarioRequest && $user && $user->hasSchoolRole($run->scenarioRequest->school_id, 'school_admin'))
            || ($authorId === $user?->id);

        if (! $canAccess) {
            return ApiResponse::error(
                code: 'FORBIDDEN_AUTHOR_ACCESS',
                message: 'Доступ к запуску генерации другого педагога запрещен.',
                details: [],
                status: 403
            );
        }

        $scenario = $run->scenarioRequest?->scenario;
        $scenarioId = $scenario?->id;
        $revisionId = $scenario?->current_revision_id;

        return ApiResponse::success([
            'id' => $run->id,
            'status' => $run->status,
            'scenario_id' => $scenarioId,
            'revision_id' => $revisionId,
            'moderation_status' => $scenario?->currentRevision?->moderationState?->status ?? ($scenario ? 'pending_verification' : null),
            'scenario_request_id' => $run->scenario_request_id,
            'provider_name' => $run->provider_name,
            'model_identifier' => $run->model_identifier,
            'attempts' => $run->attempts,
            'latency_ms' => $run->latency_ms,
            'prompt_tokens' => $run->prompt_tokens,
            'completion_tokens' => $run->completion_tokens,
            'error_code' => $run->error_code,
            'error_message' => $run->error_message,
            'created_at' => $run->created_at?->toIso8601String(),
            'completed_at' => $run->completed_at?->toIso8601String(),
        ]);
    }
}
