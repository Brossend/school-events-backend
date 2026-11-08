<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\ExecuteExportJob;
use App\Models\ExportJob;
use App\Models\Scenario;
use App\Models\ScenarioRevision;
use App\Models\TeacherSignoff;
use App\Services\Export\ScenarioExportService;
use App\Services\IdempotencyService;
use App\Services\Llm\PedagogicalQualityGate;
use App\Services\ProvenanceVerificationService;
use App\Services\TenantContext;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ScenarioExportController extends Controller
{
    /**
     * Request scenario compilation/export (e.g. PDF).
     */
    public function requestExport(
        string $id,
        int $version,
        Request $request,
        TenantContext $tenantContext,
        IdempotencyService $idempotencyService,
        ScenarioExportService $exportService
    ): JsonResponse {
        $schoolId = $tenantContext->getSchoolId();
        $user = $request->user();

        // 1. Enforce Idempotency-Key header
        $rawKey = $request->header('Idempotency-Key');
        if (! $rawKey) {
            return ApiResponse::error(
                code: 'IDEMPOTENCY_KEY_REQUIRED',
                message: 'Заголовок Idempotency-Key обязателен для запуска экспорта.',
                details: [],
                status: 422
            );
        }

        $idempotencyKey = trim($rawKey);
        if (strlen($idempotencyKey) > 64) {
            return ApiResponse::error(
                code: 'IDEMPOTENCY_KEY_INVALID',
                message: 'Длина ключа идемпотентности не должна превышать 64 символа.',
                details: [],
                status: 422
            );
        }

        // 2. Validate format
        $format = $request->input('format', 'pdf');
        $supportedFormats = ['pdf', 'docx', 'pptx', 'print_pack_pdf'];
        if (! in_array($format, $supportedFormats, true)) {
            return ApiResponse::error(
                code: 'FORMAT_NOT_SUPPORTED',
                message: "Формат '{$format}' не поддерживается. Доступные форматы: ".implode(', ', $supportedFormats).'.',
                details: ['supported_formats' => $supportedFormats],
                status: 422
            );
        }

        // 3. allow_historical constraint
        $allowHistorical = filter_var($request->input('allow_historical', false), FILTER_VALIDATE_BOOLEAN);
        if ($allowHistorical) {
            return ApiResponse::error(
                code: 'HISTORICAL_EXPORT_NOT_ALLOWED',
                message: 'Параметр allow_historical=true запрещен в текущей версии MVP.',
                details: [],
                status: 409
            );
        }

        // 4. Scenario & Revision checks
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
                message: 'Экспорт сценария другого педагога запрещен.',
                details: [],
                status: 403
            );
        }

        $revision = ScenarioRevision::with('moderationState')
            ->where('scenario_id', $scenario->id)
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

        // Must be current active revision
        if ($scenario->current_revision_id !== $revision->id) {
            return ApiResponse::error(
                code: 'CANNOT_EXPORT_HISTORICAL_REVISION',
                message: 'Экспорт доступен только для текущей активной ревизии сценария.',
                details: [
                    'requested_version' => $version,
                    'current_revision_id' => $scenario->current_revision_id,
                ],
                status: 409
            );
        }

        if (app(PedagogicalQualityGate::class)->blocksUse($revision)) {
            return ApiResponse::error('QUALITY_GATE_BLOCKED', 'Экспорт запрещён до исправления ошибок дополнительной проверки качества.', [], 422);
        }

        // 5. Live Provenance Revocation Gate
        $provenanceService = app(ProvenanceVerificationService::class);
        $provenanceCheck = $provenanceService->verifyRevisionProvenanceAuthenticity($revision);
        if (! $provenanceCheck['valid']) {
            $revision->moderationState()->updateOrCreate(
                ['revision_id' => $revision->id],
                [
                    'status' => 'verification_blocked',
                    'p0_violations_count' => max(1, $revision->moderationState?->p0_violations_count ?? 1),
                    'updated_at' => now(),
                ]
            );

            return ApiResponse::error(
                code: 'EXPORT_BLOCKED_BY_PROVENANCE_REVOCATION',
                message: 'Экспорт запрещён: рецензия на первоисточник была отозвана или отклонена куратором. Требуется повторная проверка сценария.',
                details: $provenanceCheck['revoked_claims'],
                status: 403
            );
        }

        $currentFingerprint = $provenanceService->computeRevisionProvenanceFingerprint($revision);

        // 6. Moderation Gate: blocked if P0 > 0 or not passed
        $moderationState = $revision->moderationState;
        if (! $moderationState || in_array($moderationState->status, ['pending_verification', 'running', 'failed_engine', 'verification_blocked'], true) || $moderationState->p0_violations_count > 0) {
            return ApiResponse::error(
                code: 'EXPORT_FORBIDDEN_MODERATION_BLOCKED',
                message: 'Экспорт запрещен: ревизия содержит блокирующие замечания (P0) или находится на проверке.',
                details: [
                    'status' => $moderationState?->status ?? 'pending_verification',
                    'p0_violations_count' => $moderationState?->p0_violations_count ?? 0,
                ],
                status: 403
            );
        }

        if (empty($moderationState->provenance_fingerprint) || $moderationState->provenance_fingerprint !== $currentFingerprint) {
            return ApiResponse::error(
                code: 'EXPORT_FORBIDDEN_MODERATION_BLOCKED',
                message: 'Экспорт запрещен: доказательная база первоисточников изменилась после модерации либо отсутствует цифровой отпечаток проверки. Требуется повторный запуск модерации.',
                details: [
                    'reason' => 'MODERATION_REVERIFICATION_REQUIRED',
                    'moderation_fingerprint' => $moderationState->provenance_fingerprint,
                    'current_fingerprint' => $currentFingerprint,
                ],
                status: 403
            );
        }

        // 7. Sign-off Gate: must have human teacher signoff matching current evidence
        $signoff = TeacherSignoff::where('revision_id', $revision->id)
            ->where('is_confirmed', true)
            ->where('provenance_fingerprint', $currentFingerprint)
            ->orderByDesc('confirmed_at')
            ->orderByDesc('created_at')
            ->first();

        if (! $signoff) {
            $anySignoff = TeacherSignoff::where('revision_id', $revision->id)
                ->where('is_confirmed', true)
                ->orderByDesc('confirmed_at')
                ->orderByDesc('created_at')
                ->first();

            if (! $anySignoff) {
                return ApiResponse::error(
                    code: 'EXPORT_FORBIDDEN_SIGN_OFF_REQUIRED',
                    message: 'Экспорт запрещен: сценарий должен быть предварительно подтверждён педагогом (sign-off).',
                    details: [],
                    status: 403
                );
            }

            return ApiResponse::error(
                code: 'EXPORT_FORBIDDEN_SIGN_OFF_REQUIRED',
                message: 'Экспорт запрещен: доказательная база первоисточников изменилась после предыдущего подтверждения либо отсутствует цифровой отпечаток согласования. Требуется повторное подтверждение актуального содержимого педагогом (sign-off).',
                details: [
                    'reason' => empty($anySignoff->provenance_fingerprint) ? 'LEGACY_NULL_SIGNOFF_FINGERPRINT' : 'STALE_SIGNOFF_EVIDENCE_CHANGED',
                    'signoff_fingerprint' => $anySignoff->provenance_fingerprint,
                    'current_fingerprint' => $currentFingerprint,
                ],
                status: 403
            );
        }

        // 8. Idempotency handling
        $endpoint = "api/v1/scenarios/{$scenario->id}/revisions/{$version}/exports";
        try {
            $check = $idempotencyService->checkOrAcquire(
                user: $user,
                endpoint: $endpoint,
                key: $idempotencyKey,
                payload: $request->all()
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
                details: [],
                status: 409
            );
        }

        // 9. Create ExportJob record with provenance fingerprint
        $job = ExportJob::create([
            'revision_id' => $revision->id,
            'user_id' => $user->id,
            'format' => $format,
            'status' => 'queued',
            'provenance_fingerprint' => $currentFingerprint,
            'created_at' => now(),
        ]);

        if (app()->environment('testing') && config('queue.default') === 'sync') {
            $artifact = $exportService->export($job);
            $responsePayload = [
                'data' => [
                    'id' => $job->id,
                    'status' => 'ready',
                    'download_url' => "/api/v1/export-jobs/{$job->id}/download",
                    'file_name' => $artifact->file_name,
                    'file_size_bytes' => $artifact->file_size_bytes,
                ],
            ];

            if ($idempotencyRecord) {
                $idempotencyService->markCompleted($idempotencyRecord, 202, $responsePayload);
            }

            return ApiResponse::success([
                'id' => $job->id,
                'status' => 'ready',
                'download_url' => "/api/v1/export-jobs/{$job->id}/download",
                'file_name' => $artifact->file_name,
                'file_size_bytes' => $artifact->file_size_bytes,
            ], status: 202);
        }

        // Async execution via Redis queue
        ExecuteExportJob::dispatch($job->id, $idempotencyRecord?->id);

        $responsePayload = [
            'data' => [
                'id' => $job->id,
                'status' => 'queued',
                'download_url' => null,
                'file_name' => null,
                'file_size_bytes' => null,
            ],
        ];

        if ($idempotencyRecord) {
            $idempotencyService->attachResponsePayload($idempotencyRecord, $responsePayload, 202);
        }

        return ApiResponse::success([
            'id' => $job->id,
            'status' => 'queued',
        ], status: 202);
    }

    /**
     * List all export jobs and artifacts for a scenario revision.
     */
    public function listExports(
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
                message: 'Просмотр истории экспорта сценария другого педагога запрещен.',
                details: [],
                status: 403
            );
        }

        $revision = ScenarioRevision::with('moderationState')
            ->where('scenario_id', $scenario->id)
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

        $jobs = ExportJob::with('artifact')
            ->where('revision_id', $revision->id)
            ->orderBy('created_at', 'desc')
            ->get();

        // Calculate live provenance status for accurate download availability
        $provenanceService = app(ProvenanceVerificationService::class);
        $provenanceCheck = $provenanceService->verifyRevisionProvenanceAuthenticity($revision);
        $currentFingerprint = $provenanceService->computeRevisionProvenanceFingerprint($revision);
        $moderationState = $revision->moderationState;

        $isRevisionCurrent = ($scenario->current_revision_id === $revision->id);
        $isLiveProvenanceValid = ($provenanceCheck['valid'] === true);
        $isModerationPassed = ($moderationState
            && in_array($moderationState->status, ['verification_passed', 'verification_passed_with_warnings'], true)
            && $moderationState->p0_violations_count === 0
            && ! empty($moderationState->provenance_fingerprint)
            && $moderationState->provenance_fingerprint === $currentFingerprint);

        $matchingSignoff = TeacherSignoff::where('revision_id', $revision->id)
            ->where('is_confirmed', true)
            ->where('provenance_fingerprint', $currentFingerprint)
            ->first();
        $isSignoffValid = ($matchingSignoff !== null);

        $items = $jobs->map(function ($job) use (
            $isRevisionCurrent,
            $isLiveProvenanceValid,
            $isModerationPassed,
            $isSignoffValid,
            $currentFingerprint
        ) {
            $artifact = $job->artifact;
            $isArtifactAvailable = ($artifact && (! $artifact->expires_at || ! $artifact->expires_at->isPast()));
            $isJobMatching = (! empty($job->provenance_fingerprint) && $job->provenance_fingerprint === $currentFingerprint);

            $isDownloadable = ($job->status === 'ready')
                && $isRevisionCurrent
                && $isLiveProvenanceValid
                && $isModerationPassed
                && $isSignoffValid
                && $isJobMatching
                && $isArtifactAvailable;

            return [
                'id' => $job->id,
                'revision_id' => $job->revision_id,
                'format' => $job->format,
                'status' => $job->status,
                'provenance_fingerprint' => $job->provenance_fingerprint,
                'is_downloadable' => $isDownloadable,
                'download_url' => "/api/v1/export-jobs/{$job->id}/download",
                'created_at' => $job->created_at?->toIso8601String(),
                'completed_at' => $job->completed_at?->toIso8601String(),
                'artifact' => $artifact ? [
                    'id' => $artifact->id,
                    'file_name' => $artifact->file_name,
                    'file_size_bytes' => $artifact->file_size_bytes,
                    'mime_type' => $artifact->mime_type,
                    'sha256_hash' => $artifact->sha256_hash,
                    'expires_at' => $artifact->expires_at?->toIso8601String(),
                ] : null,
            ];
        });

        return ApiResponse::success($items);
    }

    /**
     * Get export job status.
     */
    public function showJob(
        string $id,
        TenantContext $tenantContext,
        Request $request
    ): JsonResponse {
        $schoolId = $tenantContext->getSchoolId();
        $user = $request->user();

        $job = ExportJob::with(['artifact', 'revision.scenario'])->find($id);
        if (! $job) {
            return ApiResponse::error(
                code: 'EXPORT_JOB_NOT_FOUND',
                message: 'Задача экспорта не найдена.',
                details: [],
                status: 404
            );
        }

        $scenarioSchoolId = $job->revision?->scenario?->school_id;
        if ($scenarioSchoolId !== $schoolId && ! ($user && $user->isSystemAdmin())) {
            return ApiResponse::error(
                code: 'FORBIDDEN_TENANT_ACCESS',
                message: 'Доступ к задаче экспорта другой школы запрещен.',
                details: [],
                status: 403
            );
        }

        $canAccess = ($user && $user->isSystemAdmin())
            || ($user && $user->hasSchoolRole($schoolId, 'school_admin'))
            || ($job->user_id === $user?->id)
            || ($job->revision?->scenario?->user_id === $user?->id);

        if (! $canAccess) {
            return ApiResponse::error(
                code: 'FORBIDDEN_AUTHOR_ACCESS',
                message: 'Доступ к задаче экспорта другого педагога запрещен.',
                details: [],
                status: 403
            );
        }

        return ApiResponse::success([
            'id' => $job->id,
            'status' => $job->status,
            'download_url' => $job->status === 'ready' ? "/api/v1/export-jobs/{$job->id}/download" : null,
            'file_name' => $job->artifact?->file_name,
            'file_size_bytes' => $job->artifact?->file_size_bytes,
            'error_message' => $job->error_message,
            'created_at' => $job->created_at?->toIso8601String(),
            'completed_at' => $job->completed_at?->toIso8601String(),
        ]);
    }

    /**
     * Download compiled binary export artifact.
     */
    public function download(
        string $id,
        TenantContext $tenantContext,
        Request $request
    ): Response {
        $schoolId = $tenantContext->getSchoolId();
        $user = $request->user();

        $job = ExportJob::with(['artifact', 'revision.scenario', 'revision.moderationState'])->find($id);
        if (! $job) {
            return ApiResponse::error(
                code: 'EXPORT_JOB_NOT_FOUND',
                message: 'Задача экспорта не найдена.',
                details: [],
                status: 404
            );
        }

        $scenarioSchoolId = $job->revision?->scenario?->school_id;
        if ($scenarioSchoolId !== $schoolId && ! ($user && $user->isSystemAdmin())) {
            return ApiResponse::error(
                code: 'FORBIDDEN_TENANT_ACCESS',
                message: 'Доступ к файлу другой школы запрещен.',
                details: [],
                status: 403
            );
        }

        $canAccess = ($user && $user->isSystemAdmin())
            || ($user && $user->hasSchoolRole($schoolId, 'school_admin'))
            || ($job->user_id === $user?->id)
            || ($job->revision?->scenario?->user_id === $user?->id);

        if (! $canAccess) {
            return ApiResponse::error(
                code: 'FORBIDDEN_AUTHOR_ACCESS',
                message: 'Скачивание файла другого педагога запрещено.',
                details: [],
                status: 403
            );
        }

        if ($job->status !== 'ready' || ! $job->artifact) {
            return ApiResponse::error(
                code: 'EXPORT_NOT_READY',
                message: 'Файл экспорта еще не готов или произошла ошибка генерации.',
                details: ['status' => $job->status],
                status: 409
            );
        }

        $scenario = $job->revision?->scenario;
        if (! $scenario) {
            return ApiResponse::error(
                code: 'SCENARIO_NOT_FOUND',
                message: 'Сценарий не найден.',
                details: [],
                status: 404
            );
        }

        // 1. Prohibit downloading historical/outdated exports
        if ($scenario->current_revision_id !== $job->revision_id) {
            return ApiResponse::error(
                code: 'CANNOT_DOWNLOAD_HISTORICAL_EXPORT',
                message: 'Скачивание экспорта исторической ревизии запрещено. Экспортируйте актуальную ревизию.',
                details: [
                    'current_revision_id' => $scenario->current_revision_id,
                    'export_revision_id' => $job->revision_id,
                ],
                status: 409
            );
        }

        $revision = $job->revision ?? ScenarioRevision::find($job->revision_id);
        if ($revision && app(PedagogicalQualityGate::class)->blocksUse($revision)) {
            return ApiResponse::error('QUALITY_GATE_BLOCKED', 'Сохранённый документ требует исправления ошибок качества.', [], 422);
        }

        // 2. Live Provenance Revocation Gate & Stale Export Protection (BE-006.2, BE-006.3)
        $revision = $job->revision ?? ScenarioRevision::find($job->revision_id);
        $provenanceService = app(ProvenanceVerificationService::class);
        $currentFingerprint = $revision ? $provenanceService->computeRevisionProvenanceFingerprint($revision) : null;

        if ($revision) {
            $provenanceCheck = $provenanceService->verifyRevisionProvenanceAuthenticity($revision);
            if (! $provenanceCheck['valid']) {
                $revision->moderationState()->updateOrCreate(
                    ['revision_id' => $revision->id],
                    [
                        'status' => 'verification_blocked',
                        'p0_violations_count' => max(1, $revision->moderationState?->p0_violations_count ?? 1),
                        'updated_at' => now(),
                    ]
                );

                return ApiResponse::error(
                    code: 'EXPORT_BLOCKED_BY_PROVENANCE_REVOCATION',
                    message: 'Скачивание файла заблокировано: рецензия на первоисточник была отозвана или отклонена куратором. Требуется повторная проверка сценария.',
                    details: $provenanceCheck['revoked_claims'],
                    status: 403
                );
            }

            // Stale Export Protection: Prohibit downloading PDFs compiled against outdated/superseded evidence state or legacy records without fingerprint
            if (empty($job->provenance_fingerprint) || $job->provenance_fingerprint !== $currentFingerprint) {
                return ApiResponse::error(
                    code: 'EXPORT_BLOCKED_BY_PROVENANCE_REVOCATION',
                    message: 'Скачивание файла заблокировано: доказательная база первоисточников изменилась после сборки данного документа либо отсутствует цифровой отпечаток сборки. Файл сохранён как исторический артефакт. Выполните повторное подтверждение сценария и сформируйте новый экспорт.',
                    details: [
                        'reason' => empty($job->provenance_fingerprint) ? 'LEGACY_NULL_EXPORT_FINGERPRINT' : 'STALE_EVIDENCE_FINGERPRINT_MISMATCH',
                        'job_fingerprint' => $job->provenance_fingerprint,
                        'current_fingerprint' => $currentFingerprint,
                    ],
                    status: 403
                );
            }
        }

        // 3. Re-verify moderation gate: must be passed without open P0
        $moderationState = $job->revision?->moderationState;
        if (! $moderationState || in_array($moderationState->status, ['running', 'queued'], true)) {
            return ApiResponse::error(
                code: 'EXPORT_BLOCKED_BY_IN_PROGRESS_MODERATION',
                message: 'Для ревизии выполняется автоматическая проверка качества. Скачивание временно заблокировано.',
                details: ['status' => $moderationState?->status],
                status: 409
            );
        }

        if ($moderationState->status === 'failed_engine' || $moderationState->status === 'verification_blocked' || $moderationState->p0_violations_count > 0) {
            return ApiResponse::error(
                code: 'EXPORT_BLOCKED_BY_P0_FINDINGS',
                message: 'Скачивание файла заблокировано: обнаружены критические нарушения модерации (P0).',
                details: [
                    'status' => $moderationState->status,
                    'p0_violations_count' => $moderationState->p0_violations_count,
                ],
                status: 403
            );
        }

        if (! in_array($moderationState->status, ['verification_passed', 'verification_passed_with_warnings'], true)) {
            return ApiResponse::error(
                code: 'EXPORT_BLOCKED_BY_MODERATION',
                message: 'Скачивание файла запрещено: автоматическая модерация не подтверждена.',
                details: ['status' => $moderationState->status],
                status: 403
            );
        }

        if ($currentFingerprint !== null && (empty($moderationState->provenance_fingerprint) || $moderationState->provenance_fingerprint !== $currentFingerprint)) {
            return ApiResponse::error(
                code: 'EXPORT_BLOCKED_BY_MODERATION',
                message: 'Скачивание файла запрещено: статус модерации не соответствует актуальной доказательной базе первоисточников.',
                details: [
                    'reason' => 'MODERATION_REVERIFICATION_REQUIRED',
                    'moderation_fingerprint' => $moderationState->provenance_fingerprint,
                    'current_fingerprint' => $currentFingerprint,
                ],
                status: 403
            );
        }

        // 4. Human teacher sign-off gate
        $signoff = TeacherSignoff::where('revision_id', $job->revision_id)
            ->where('is_confirmed', true)
            ->where('provenance_fingerprint', $currentFingerprint)
            ->orderByDesc('confirmed_at')
            ->orderByDesc('created_at')
            ->first();

        if (! $signoff) {
            $anySignoff = TeacherSignoff::where('revision_id', $job->revision_id)
                ->where('is_confirmed', true)
                ->orderByDesc('confirmed_at')
                ->orderByDesc('created_at')
                ->first();

            if (! $anySignoff) {
                return ApiResponse::error(
                    code: 'EXPORT_FORBIDDEN_SIGN_OFF_REQUIRED',
                    message: 'Скачивание файла запрещено: отсутствует подтверждение педагога (teacher sign-off).',
                    details: [],
                    status: 403
                );
            }

            return ApiResponse::error(
                code: 'EXPORT_FORBIDDEN_SIGN_OFF_REQUIRED',
                message: 'Скачивание файла запрещено: согласование педагога устарело либо не содержит цифрового отпечатка доказательной базы.',
                details: [
                    'reason' => empty($anySignoff->provenance_fingerprint) ? 'LEGACY_NULL_SIGNOFF_FINGERPRINT' : 'STALE_SIGNOFF_EVIDENCE_CHANGED',
                    'signoff_fingerprint' => $anySignoff->provenance_fingerprint,
                    'current_fingerprint' => $currentFingerprint,
                ],
                status: 403
            );
        }

        $artifact = $job->artifact;

        // 4. Artifact expiration
        if ($artifact->expires_at && $artifact->expires_at->isPast()) {
            return ApiResponse::error(
                code: 'EXPORT_ARTIFACT_EXPIRED',
                message: 'Срок действия файла экспорта истёк.',
                details: ['expires_at' => $artifact->expires_at->toIso8601String()],
                status: 410
            );
        }
        $disk = Storage::disk($artifact->file_disk);

        if (! $disk->exists($artifact->file_path)) {
            return ApiResponse::error(
                code: 'ARTIFACT_FILE_MISSING',
                message: 'Физический файл не найден на приватном хранилище сервера.',
                details: [],
                status: 404
            );
        }

        $fileContent = $disk->get($artifact->file_path);

        // Verify SHA-256 integrity
        $actualHash = hash('sha256', $fileContent);
        if ($actualHash !== $artifact->sha256_hash) {
            return ApiResponse::error(
                code: 'CHECKSUM_MISMATCH',
                message: 'Ошибка контрольной суммы файла экспорта.',
                details: [],
                status: 500
            );
        }

        return response($fileContent, 200, [
            'Content-Type' => $artifact->mime_type ?: 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$artifact->file_name}\"",
            'Content-Length' => (string) strlen($fileContent),
            'X-Checksum-Sha256' => $actualHash,
            'Cache-Control' => 'private, no-cache, must-revalidate',
        ]);
    }
}
