<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\RunVerificationJob;
use App\Models\Scenario;
use App\Models\ScenarioRevision;
use App\Models\VerificationRun;
use App\Services\Moderation\ModerationEngineService;
use App\Services\TenantContext;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RevisionModerationController extends Controller
{
    /**
     * Get verification run results and findings for a revision.
     */
    public function getVerifications(
        string $id,
        int $version,
        TenantContext $tenantContext,
        Request $request
    ): JsonResponse {
        $schoolId = $tenantContext->getSchoolId();
        $user = $request->user();

        $scenario = Scenario::find($id);
        if (! $scenario) {
            return ApiResponse::error(
                code: 'SCENARIO_NOT_FOUND',
                message: 'Сценарий не найден.',
                details: [],
                status: 404
            );
        }

        if ($scenario->school_id !== $schoolId && ! ($user && $user->isSystemAdmin())) {
            return ApiResponse::error(
                code: 'FORBIDDEN_TENANT_ACCESS',
                message: 'Доступ к сценарию другой школы запрещен.',
                details: [],
                status: 403
            );
        }

        $canAccess = ($user && $user->isSystemAdmin())
            || ($user && $user->hasSchoolRole($schoolId, 'school_admin'))
            || ($scenario->user_id === $user?->id);

        if (! $canAccess) {
            return ApiResponse::error(
                code: 'FORBIDDEN_AUTHOR_ACCESS',
                message: 'Доступ к замечаниям модерации другого педагога запрещен.',
                details: [],
                status: 403
            );
        }

        $revision = ScenarioRevision::where('scenario_id', $scenario->id)
            ->where('version_number', $version)
            ->first();

        if (! $revision) {
            return ApiResponse::error(
                code: 'REVISION_NOT_FOUND',
                message: "Ревизия версии {$version} не найдена.",
                details: [],
                status: 404
            );
        }

        // Fetch latest verification run for this revision
        $latestRun = VerificationRun::with('findings')
            ->where('revision_id', $revision->id)
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->first();

        if (! $latestRun) {
            return ApiResponse::success([
                'verification_run_id' => null,
                'status' => 'pending_verification',
                'p0_violations_count' => 0,
                'p1_violations_count' => 0,
                'findings' => [],
            ]);
        }

        return ApiResponse::success([
            'verification_run_id' => $latestRun->id,
            'status' => $latestRun->status,
            'p0_violations_count' => $latestRun->p0_violations_count,
            'p1_violations_count' => $latestRun->p1_violations_count,
            'execution_time_ms' => $latestRun->execution_time_ms,
            'created_at' => $latestRun->created_at?->toIso8601String(),
            'findings' => $latestRun->findings->map(fn ($f) => [
                'id' => $f->id,
                'severity' => $f->severity,
                'rule_code' => $f->rule_code,
                'target_path' => $f->target_path,
                'message' => $f->message,
                'suggested_fix' => $f->suggested_fix,
                'status' => $f->status,
                'created_at' => $f->created_at?->toIso8601String(),
            ]),
        ]);
    }

    /**
     * Retrigger automated verification for a revision.
     */
    public function verify(
        string $id,
        int $version,
        TenantContext $tenantContext,
        Request $request,
        ModerationEngineService $engine
    ): JsonResponse {
        $schoolId = $tenantContext->getSchoolId();
        $user = $request->user();

        $scenario = Scenario::find($id);
        if (! $scenario) {
            return ApiResponse::error(
                code: 'SCENARIO_NOT_FOUND',
                message: 'Сценарий не найден.',
                details: [],
                status: 404
            );
        }

        if ($scenario->school_id !== $schoolId && ! ($user && $user->isSystemAdmin())) {
            return ApiResponse::error(
                code: 'FORBIDDEN_TENANT_ACCESS',
                message: 'Доступ к сценарию другой школы запрещен.',
                details: [],
                status: 403
            );
        }

        $canAccess = ($user && $user->isSystemAdmin())
            || ($user && $user->hasSchoolRole($schoolId, 'school_admin'))
            || ($scenario->user_id === $user?->id);

        if (! $canAccess) {
            return ApiResponse::error(
                code: 'FORBIDDEN_AUTHOR_ACCESS',
                message: 'Запуск проверки сценария другого педагога запрещен.',
                details: [],
                status: 403
            );
        }

        $revision = ScenarioRevision::where('scenario_id', $scenario->id)
            ->where('version_number', $version)
            ->first();

        if (! $revision) {
            return ApiResponse::error(
                code: 'REVISION_NOT_FOUND',
                message: "Ревизия версии {$version} не найдена.",
                details: [],
                status: 404
            );
        }

        // Enforce single active verification run per revision using DB transaction + lockForUpdate
        $run = \Illuminate\Support\Facades\DB::transaction(function () use ($revision) {
            $state = \Illuminate\Support\Facades\DB::table('revision_moderation_states')
                ->where('revision_id', $revision->id)
                ->lockForUpdate()
                ->first();

            $hasRunning = \Illuminate\Support\Facades\DB::table('verification_runs')
                ->where('revision_id', $revision->id)
                ->where('status', 'running')
                ->exists();

            if ($hasRunning || ($state && $state->status === 'running')) {
                return null;
            }

            $newRun = VerificationRun::create([
                'revision_id' => $revision->id,
                'rule_engine_version' => ModerationEngineService::RULE_ENGINE_VERSION,
                'status' => 'running',
                'p0_violations_count' => 0,
                'p1_violations_count' => 0,
                'created_at' => now(),
            ]);

            \Illuminate\Support\Facades\DB::table('revision_moderation_states')->updateOrInsert(
                ['revision_id' => $revision->id],
                [
                    'status' => 'running',
                    'latest_verification_run_id' => $newRun->id,
                    'updated_at' => now(),
                ]
            );

            return $newRun;
        });

        if (! $run) {
            return ApiResponse::error(
                code: 'VERIFICATION_ALREADY_RUNNING',
                message: 'Проверка для данной ревизии уже выполняется.',
                details: [],
                status: 409
            );
        }

        // If sync execution in testing/CLI or dispatch to Redis queue
        if (app()->environment('testing')) {
            $engine->verifyRevision($revision, $run->id);
            $run->refresh();

            return ApiResponse::success([
                'verification_run_id' => $run->id,
                'status' => $run->status,
                'message' => 'Проверка ревизии завершена.',
            ], status: 202);
        }

        RunVerificationJob::dispatch($revision->id, $run->id);

        return ApiResponse::success([
            'verification_run_id' => $run->id,
            'status' => 'running',
            'message' => 'Проверка ревизии поставлена в очередь.',
        ], status: 202);
    }
}
