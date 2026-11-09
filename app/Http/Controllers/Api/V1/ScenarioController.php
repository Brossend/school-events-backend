<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Scenario;
use App\Services\Llm\PedagogicalQualityGate;
use App\Services\TenantContext;
use App\Support\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScenarioController extends Controller
{
    /**
     * List scenarios for the current active school.
     */
    public function index(
        TenantContext $tenantContext,
        Request $request
    ): JsonResponse {
        $schoolId = $tenantContext->getSchoolId();
        $user = $request->user();

        if (! $schoolId && ! ($user && $user->isSystemAdmin())) {
            return ApiResponse::error(
                code: 'NO_ACTIVE_SCHOOL_MEMBERSHIP',
                message: 'Для просмотра сценариев требуется активное членство в школе.',
                details: [],
                status: 403
            );
        }

        $query = Scenario::query();
        if ($schoolId) {
            $query->where('school_id', $schoolId);
        }

        // Regular teacher only sees their own scenarios; school_admin and system_admin see all
        $isSchoolAdmin = $schoolId && $user && $user->hasSchoolRole($schoolId, 'school_admin');
        if (! ($user && $user->isSystemAdmin()) && ! $isSchoolAdmin) {
            $query->where('user_id', $user?->id);
        }

        // Filter by archive status
        if ($request->has('is_archived')) {
            $isArchivedParam = $request->query('is_archived');
            if ($isArchivedParam !== 'all') {
                $query->where('is_archived', filter_var($isArchivedParam, FILTER_VALIDATE_BOOLEAN));
            }
        } elseif ($request->input('status') === 'archived') {
            $query->where('is_archived', true);
        } else {
            // Default to active non-archived scenarios
            $query->where('is_archived', false);
        }

        // Filter by title search
        if ($request->filled('q')) {
            $search = (string) $request->input('q');
            $query->where('title', 'ilike', "%{$search}%");
        }

        // Filter by scenario status
        if ($request->filled('status')) {
            $status = (string) $request->input('status');
            if ($status === 'archived') {
                $query->where('is_archived', true);
            } elseif ($status === 'draft') {
                $query->where('is_archived', false)
                    ->whereDoesntHave('currentRevision.teacherSignoffs', fn ($sq) => $sq->where('is_confirmed', true));
            } elseif ($status === 'completed') {
                $query->where('is_archived', false)
                    ->whereHas('currentRevision.teacherSignoffs', fn ($sq) => $sq->where('is_confirmed', true));
            }
        }

        // Filter by date range (inclusive of entire 'to' day)
        if ($request->filled('from')) {
            $from = (string) $request->input('from');
            $fromCarbon = preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)
                ? Carbon::parse($from)->startOfDay()
                : Carbon::parse($from);
            $query->where('created_at', '>=', $fromCarbon);
        }
        if ($request->filled('to')) {
            $to = (string) $request->input('to');
            $toCarbon = preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)
                ? Carbon::parse($to)->endOfDay()
                : Carbon::parse($to);
            $query->where('created_at', '<=', $toCarbon);
        }

        // Sorting (support created_at:desc, created_at_desc, title:asc, title_asc, etc.)
        $sortParam = (string) $request->query('sort', 'created_at:desc');
        if (str_contains($sortParam, ':')) {
            [$sortField, $sortDir] = explode(':', $sortParam, 2);
        } elseif (preg_match('/^(created_at|updated_at|title)_(asc|desc)$/i', $sortParam, $m)) {
            $sortField = $m[1];
            $sortDir = $m[2];
        } else {
            $sortField = 'created_at';
            $sortDir = 'desc';
        }

        $allowedFields = ['created_at', 'updated_at', 'title'];
        if (! in_array($sortField, $allowedFields, true)) {
            $sortField = 'created_at';
        }
        $sortDir = strtolower($sortDir) === 'asc' ? 'asc' : 'desc';

        $scenarios = $query->with(['currentRevision.moderationState'])
            ->withExists([
                'currentRevision as has_signoff' => fn ($rq) => $rq->whereHas('teacherSignoffs', fn ($sq) => $sq->where('is_confirmed', true)),
            ])
            ->orderBy($sortField, $sortDir)
            ->paginate((int) $request->query('per_page', 20));

        $items = collect($scenarios->items())->map(function (Scenario $s) {
            $effectiveStatus = $s->is_archived ? 'archived' : ($s->has_signoff ? 'completed' : 'draft');

            return [
                'id' => $s->id,
                'title' => $s->title,
                'status' => $effectiveStatus,
                'is_archived' => $s->is_archived,
                'current_revision_id' => $s->current_revision_id,
                'optimistic_lock_version' => $s->optimistic_lock_version,
                'created_at' => $s->created_at?->toIso8601String(),
                'updated_at' => $s->updated_at?->toIso8601String(),
                'moderation_status' => $s->currentRevision?->moderationState?->status ?? 'pending_verification',
                'current_revision' => $s->currentRevision ? [
                    'id' => $s->currentRevision->id,
                    'version_number' => $s->currentRevision->version_number,
                    'change_description' => $s->currentRevision->change_description,
                    'moderation_status' => $s->currentRevision->moderationState?->status ?? 'pending_verification',
                ] : null,
            ];
        });

        return ApiResponse::success(
            data: $items,
            meta: [
                'current_page' => $scenarios->currentPage(),
                'total' => $scenarios->total(),
                'per_page' => $scenarios->perPage(),
                'last_page' => $scenarios->lastPage(),
            ]
        );
    }

    /**
     * Show a detailed scenario with its current revision, stages, materials, and moderation state.
     */
    public function show(
        string $id,
        TenantContext $tenantContext,
        Request $request
    ): JsonResponse {
        $schoolId = $tenantContext->getSchoolId();
        $user = $request->user();

        $scenario = Scenario::with([
            'currentRevision.moderationState',
            'currentRevision.stages',
            'currentRevision.materials',
            'currentRevision.teacherSignoffs.user',
            'scenarioRequest',
        ])->find($id);

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
                message: 'Доступ к сценарию другого педагога запрещен.',
                details: [],
                status: 403
            );
        }

        $rev = $scenario->currentRevision;
        $generation = $rev?->sourceGenerationRun;
        if (! $generation) {
            $generation = $scenario->revisions()->whereNotNull('source_generation_run_id')
                ->orderBy('version_number')->first()?->sourceGenerationRun;
        }
        $latestSignoff = $rev?->teacherSignoffs()->where('is_confirmed', true)->latest('confirmed_at')->first();
        $hasSignoff = (bool) $latestSignoff;
        $effectiveStatus = $scenario->is_archived ? 'archived' : ($hasSignoff ? 'completed' : 'draft');

        return ApiResponse::success([
            'id' => $scenario->id,
            'quality_review' => $rev ? app(PedagogicalQualityGate::class)->forRevision($rev) : null,
            'generation' => $generation ? [
                'id' => $generation->id, 'provider_name' => $generation->provider_name,
                'model_identifier' => $generation->model_identifier, 'prompt_version' => $generation->prompt_version,
                'schema_version' => $generation->schema_version, 'latency_ms' => $generation->latency_ms,
                'prompt_tokens' => $generation->prompt_tokens, 'completion_tokens' => $generation->completion_tokens,
            ] : null,
            'school_id' => $scenario->school_id,
            'scenario_request_id' => $scenario->scenario_request_id,
            'title' => $scenario->title,
            'status' => $effectiveStatus,
            'is_archived' => $scenario->is_archived,
            'current_revision_id' => $scenario->current_revision_id,
            'optimistic_lock_version' => $scenario->optimistic_lock_version,
            'moderation_status' => $rev?->moderationState?->status ?? 'pending_verification',
            'created_at' => $scenario->created_at?->toIso8601String(),
            'updated_at' => $scenario->updated_at?->toIso8601String(),
            'current_revision' => $rev ? [
                'id' => $rev->id,
                'version_number' => $rev->version_number,
                'change_description' => $rev->change_description,
                'moderation_status' => $rev->moderationState?->status ?? 'pending_verification',
                'concept' => $rev->concept,
                'pedagogical_goal' => $rev->pedagogical_goal,
                'content_payload' => $rev->content_payload,
                'created_at' => $rev->created_at?->toIso8601String(),
                'moderation_state' => $rev->moderationState ? [
                    'status' => $rev->moderationState->status,
                    'p0_violations_count' => $rev->moderationState->p0_violations_count,
                    'p1_violations_count' => $rev->moderationState->p1_violations_count,
                    'moderator_notes' => $rev->moderationState->moderator_notes,
                    'checked_at' => $rev->moderationState->checked_at?->toIso8601String(),
                ] : null,
                'signoff' => $latestSignoff ? [
                    'id' => $latestSignoff->id,
                    'is_confirmed' => $latestSignoff->is_confirmed,
                    'checklist_confirmed' => $latestSignoff->checklist_confirmed,
                    'teacher_notes' => $latestSignoff->teacher_notes,
                    'confirmed_at' => $latestSignoff->confirmed_at?->toIso8601String(),
                    'user' => $latestSignoff->user ? [
                        'id' => $latestSignoff->user->id,
                        'name' => $latestSignoff->user->name,
                    ] : null,
                ] : null,
                'stages' => $rev->stages->map(fn ($st) => [
                    'id' => $st->id,
                    'stage_identifier' => $st->stage_identifier,
                    'stage_key' => $st->stage_identifier,
                    'order_index' => $st->order_index,
                    'stage_type' => $st->stage_type,
                    'title' => $st->title,
                    'duration_minutes' => $st->duration_minutes,
                    'teacher_actions' => $st->teacher_actions,
                    'student_actions' => $st->student_actions,
                    'transition_text' => $st->transition_text,
                ]),
                'materials' => $rev->materials->map(fn ($mat) => [
                    'id' => $mat->id,
                    'material_type' => $mat->material_type,
                    'title' => $mat->title,
                    'order_index' => $mat->order_index,
                    'content' => $mat->content,
                    'content_payload' => $mat->content,
                ]),
            ] : null,
        ]);
    }

    /**
     * Archive a scenario.
     */
    public function archive(
        string $id,
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
                message: 'Архивировать сценарий может только его автор, администратор школы или системный администратор.',
                details: [],
                status: 403
            );
        }

        if (! $scenario->is_archived) {
            $scenario->update([
                'is_archived' => true,
                'updated_at' => now(),
            ]);
        }

        return ApiResponse::success([
            'id' => $scenario->id,
            'title' => $scenario->title,
            'status' => 'archived',
            'is_archived' => true,
            'updated_at' => $scenario->updated_at?->toIso8601String(),
        ]);
    }

    /**
     * Restore an archived scenario.
     */
    public function restore(
        string $id,
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
                message: 'Восстанавливать сценарий может только его автор, администратор школы или системный администратор.',
                details: [],
                status: 403
            );
        }

        if ($scenario->is_archived) {
            $scenario->update([
                'is_archived' => false,
                'updated_at' => now(),
            ]);
        }

        $hasSignoff = (bool) $scenario->currentRevision?->teacherSignoffs()->where('is_confirmed', true)->exists();
        $status = $hasSignoff ? 'completed' : 'draft';

        return ApiResponse::success([
            'id' => $scenario->id,
            'title' => $scenario->title,
            'status' => $status,
            'is_archived' => false,
            'updated_at' => $scenario->updated_at?->toIso8601String(),
        ]);
    }

    /**
     * Soft-delete a scenario.
     * Guaranteed safe: if any revision has a teacher sign-off, returns 409 Conflict.
     */
    public function destroy(
        string $id,
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
                message: 'Удалять сценарий может только его автор, администратор школы или системный администратор.',
                details: [],
                status: 403
            );
        }

        // P0 Safety Gate: Check if ANY revision has a teacher signoff
        $hasSignedOffRevision = $scenario->revisions()->whereHas('teacherSignoffs')->exists();
        if ($hasSignedOffRevision) {
            return ApiResponse::error(
                code: 'CANNOT_DELETE_SIGNED_OFF_SCENARIO',
                message: 'Нельзя удалить сценарий, для ревизий которого подписан педагогический допуск (sign-off).',
                details: [],
                status: 409
            );
        }

        $scenario->delete();

        return ApiResponse::success([
            'message' => 'Сценарий успешно удален.',
        ]);
    }
}
