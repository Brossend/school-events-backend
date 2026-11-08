<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Scenario;
use App\Models\ScenarioRevision;
use App\Models\TeacherSignoff;
use App\Services\Llm\PedagogicalQualityGate;
use App\Services\ProvenanceVerificationService;
use App\Services\TenantContext;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class TeacherSignoffController extends Controller
{
    /**
     * Submit human teacher sign-off for a scenario revision.
     */
    public function signoff(
        string $id,
        int $version,
        Request $request,
        TenantContext $tenantContext
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
                message: 'Подтверждение сценария другого педагога запрещено.',
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

        // 1. Revision must be the current active revision
        if ($scenario->current_revision_id !== $revision->id) {
            return ApiResponse::error(
                code: 'CANNOT_SIGNOFF_HISTORICAL_REVISION',
                message: 'Подтверждение возможно только для текущей активной ревизии сценария.',
                details: [
                    'requested_version' => $version,
                    'current_revision_id' => $scenario->current_revision_id,
                ],
                status: 422
            );
        }

        // 2. Validate checklist items: all 3 must be true
        $validator = Validator::make($request->all(), [
            'checklist_confirmed' => 'required|array',
            'checklist_confirmed.safety_checked' => 'required|boolean',
            'checklist_confirmed.timing_fit' => 'required|boolean',
            'checklist_confirmed.cultural_context_approved' => 'required|boolean',
            'teacher_notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return ApiResponse::error(
                code: 'SIGNOFF_CHECKLIST_INCOMPLETE',
                message: 'Ошибка валидации чек-листа подтверждения.',
                details: $validator->errors()->toArray(),
                status: 422
            );
        }

        $checklist = $request->input('checklist_confirmed');
        if (
            filter_var($checklist['safety_checked'] ?? false, FILTER_VALIDATE_BOOLEAN) !== true ||
            filter_var($checklist['timing_fit'] ?? false, FILTER_VALIDATE_BOOLEAN) !== true ||
            filter_var($checklist['cultural_context_approved'] ?? false, FILTER_VALIDATE_BOOLEAN) !== true
        ) {
            return ApiResponse::error(
                code: 'SIGNOFF_CHECKLIST_FLAGS_MUST_BE_TRUE',
                message: 'Все пункты чек-листа (безопасность, хронометраж, культурный контекст) должны быть подтверждены педагогом (значение true).',
                details: $checklist,
                status: 422
            );
        }

        // 3. Moderation Gate: revision must have passed verification without open P0
        $moderationState = $revision->moderationState;
        if (! $moderationState || $moderationState->status === 'pending_verification' || $moderationState->status === 'running') {
            return ApiResponse::error(
                code: 'MODERATION_IN_PROGRESS',
                message: 'Подтверждение невозможно: сценарий находится в процессе проверки качества.',
                details: ['status' => $moderationState?->status ?? 'pending_verification'],
                status: 422
            );
        }

        if ($moderationState->status === 'failed_engine') {
            return ApiResponse::error(
                code: 'MODERATION_FAILED_ENGINE',
                message: 'Подтверждение невозможно: ошибка автоматического механизма проверки качества.',
                details: ['status' => 'failed_engine'],
                status: 422
            );
        }

        if ($moderationState->status === 'verification_blocked' || $moderationState->p0_violations_count > 0) {
            return ApiResponse::error(
                code: 'MODERATION_BLOCKED_P0',
                message: 'Подтверждение запрещено: ревизия содержит блокирующие нарушения качества (P0). Устраните замечания перед подтверждением.',
                details: ['p0_violations_count' => $moderationState->p0_violations_count],
                status: 422
            );
        }

        if (! in_array($moderationState->status, ['verification_passed', 'verification_passed_with_warnings'], true)) {
            return ApiResponse::error(
                code: 'MODERATION_NOT_PASSED',
                message: "Подтверждение невозможно: статус модерации '{$moderationState->status}'. Допустимы только 'verification_passed' или 'verification_passed_with_warnings'.",
                details: ['status' => $moderationState->status],
                status: 422
            );
        }

        if (app(PedagogicalQualityGate::class)->blocksUse($revision)) {
            return ApiResponse::error('QUALITY_GATE_BLOCKED', 'Сначала исправьте ошибки дополнительной проверки качества в новой ревизии.', ['quality_review' => app(PedagogicalQualityGate::class)->forRevision($revision)], 422);
        }

        $currentFingerprint = app(ProvenanceVerificationService::class)->computeRevisionProvenanceFingerprint($revision);

        // Moderation Reverification Gate: moderation must match the current evidence provenance fingerprint!
        if (empty($moderationState->provenance_fingerprint) || $moderationState->provenance_fingerprint !== $currentFingerprint) {
            return ApiResponse::error(
                code: 'MODERATION_REVERIFICATION_REQUIRED',
                message: 'Подтверждение невозможно: доказательная база первоисточников изменилась после последней проверки модерации либо отсутствует цифровой отпечаток проверки. Требуется повторный запуск модерации сценария.',
                details: [
                    'moderation_fingerprint' => $moderationState->provenance_fingerprint,
                    'current_fingerprint' => $currentFingerprint,
                ],
                status: 422
            );
        }

        // For P1 warnings, require explicit acknowledgment if status is verification_passed_with_warnings
        if ($moderationState->status === 'verification_passed_with_warnings' || $moderationState->p1_violations_count > 0) {
            $warningsAcknowledged = filter_var($request->input('warnings_acknowledged', false), FILTER_VALIDATE_BOOLEAN)
                || filter_var($checklist['warnings_acknowledged'] ?? false, FILTER_VALIDATE_BOOLEAN);

            if (! $warningsAcknowledged) {
                return ApiResponse::error(
                    code: 'WARNINGS_ACKNOWLEDGMENT_REQUIRED',
                    message: 'Ревизия содержит предупреждения (P1). Для подтверждения необходимо явное ознакомление педагога с замечаниями (warnings_acknowledged=true).',
                    details: ['p1_violations_count' => $moderationState->p1_violations_count],
                    status: 422
                );
            }
        }

        // 4. Idempotent check: return existing record if already confirmed for this exact evidence state
        $existingSignoff = TeacherSignoff::where('revision_id', $revision->id)
            ->where('user_id', $user->id)
            ->where(function ($q) use ($currentFingerprint) {
                $q->where('provenance_fingerprint', $currentFingerprint);
            })
            ->first();

        if ($existingSignoff) {
            return ApiResponse::success([
                'signoff_id' => $existingSignoff->id,
                'revision_id' => $revision->id,
                'user_id' => $user->id,
                'is_confirmed' => true,
                'checklist_confirmed' => $existingSignoff->checklist_confirmed,
                'teacher_notes' => $existingSignoff->teacher_notes,
                'confirmed_at' => $existingSignoff->confirmed_at?->toIso8601String(),
                'message' => 'Сценарий уже был ранее подтверждён данным педагогом.',
            ], status: 200);
        }

        // 5. Append-only creation of sign-off record capturing current evidence fingerprint
        $signoff = TeacherSignoff::create([
            'revision_id' => $revision->id,
            'user_id' => $user->id,
            'is_confirmed' => true,
            'provenance_fingerprint' => $currentFingerprint,
            'checklist_confirmed' => $checklist,
            'teacher_notes' => $request->input('teacher_notes'),
            'ip_address' => $request->ip(),
            'confirmed_at' => now(),
            'created_at' => now(),
        ]);

        return ApiResponse::success([
            'signoff_id' => $signoff->id,
            'revision_id' => $revision->id,
            'user_id' => $user->id,
            'is_confirmed' => true,
            'checklist_confirmed' => $signoff->checklist_confirmed,
            'teacher_notes' => $signoff->teacher_notes,
            'confirmed_at' => $signoff->confirmed_at->toIso8601String(),
            'message' => 'Сценарий успешно подтверждён педагогом.',
        ]);
    }
}
