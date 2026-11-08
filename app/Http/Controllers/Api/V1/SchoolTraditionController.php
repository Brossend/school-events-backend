<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Traditions\CreateTraditionRequest;
use App\Http\Requests\Traditions\CreateTraditionVersionRequest;
use App\Http\Requests\Traditions\UpdateTraditionRequest;
use App\Models\SchoolTradition;
use App\Models\TraditionVersion;
use App\Services\TenantContext;
use App\Support\ApiResponse;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SchoolTraditionController extends Controller
{
    /**
     * List traditions belonging to the specified school.
     * Enforces strict match between URL schoolId and active TenantContext.
     */
    public function listBySchool(string $schoolId, TenantContext $tenantContext, Request $request): JsonResponse
    {
        $tenantSchoolId = $tenantContext->getSchoolId();
        $user = $request->user();

        if ($schoolId !== $tenantSchoolId && ! ($user && $user->isSystemAdmin())) {
            return ApiResponse::error(
                code: 'FORBIDDEN_TENANT_ACCESS',
                message: 'Доступ к традициям другой школы запрещен.',
                details: [],
                status: 403
            );
        }

        $query = SchoolTradition::with(['versions' => fn ($q) => $q->orderBy('created_at', 'desc')])
            ->where('school_id', $schoolId);

        if ($request->has('is_archived')) {
            $archivedParam = $request->query('is_archived');
            if ($archivedParam !== 'all') {
                $query->where('is_archived', filter_var($archivedParam, FILTER_VALIDATE_BOOLEAN));
            }
        } else {
            $query->where('is_archived', false);
        }

        $traditions = $query->orderBy('created_at', 'desc')->get();

        $data = $traditions->map(function (SchoolTradition $t) {
            $latestVersion = $t->versions->first();

            return [
                'id' => $t->id,
                'title' => $t->title,
                'description' => $t->description,
                'established_year' => $t->established_year,
                'is_archived' => $t->is_archived,
                'active_version' => $latestVersion ? [
                    'id' => $latestVersion->id,
                    'academic_year' => $latestVersion->academic_year,
                    'notes' => $latestVersion->notes,
                    'created_at' => $latestVersion->created_at?->toIso8601String(),
                ] : null,
                'versions' => $t->versions->map(fn ($v) => [
                    'id' => $v->id,
                    'academic_year' => $v->academic_year,
                    'notes' => $v->notes,
                    'created_at' => $v->created_at?->toIso8601String(),
                ])->values()->all(),
            ];
        });

        return ApiResponse::success($data);
    }

    /**
     * Create a new school tradition.
     * Enforces strict match between URL schoolId and active TenantContext.
     */
    public function store(string $schoolId, CreateTraditionRequest $request, TenantContext $tenantContext): JsonResponse
    {
        $tenantSchoolId = $tenantContext->getSchoolId();
        $user = $request->user();

        if ($schoolId !== $tenantSchoolId && ! ($user && $user->isSystemAdmin())) {
            return ApiResponse::error(
                code: 'FORBIDDEN_TENANT_ACCESS',
                message: 'Создание традиций для другой школы запрещено.',
                details: [],
                status: 403
            );
        }

        $validated = $request->validated();

        $tradition = SchoolTradition::create([
            'school_id' => $schoolId,
            'title' => $validated['title'],
            'description' => $validated['description'],
            'established_year' => $validated['established_year'] ?? null,
            'created_by_user_id' => $user->id,
        ]);

        return response()->json([
            'data' => [
                'id' => $tradition->id,
                'title' => $tradition->title,
                'description' => $tradition->description,
                'established_year' => $tradition->established_year,
            ],
        ], 201);
    }

    /**
     * Create a new annual version for a school tradition.
     * Guarantees historical immutability (append-only) and uniqueness by academic_year.
     * Gracefully handles concurrent race conditions without yielding 500 errors.
     */
    public function createVersion(string $id, CreateTraditionVersionRequest $request, TenantContext $tenantContext): JsonResponse
    {
        $tradition = SchoolTradition::find($id);

        if (! $tradition) {
            return ApiResponse::error(
                code: 'TRADITION_NOT_FOUND',
                message: 'Школьная традиция не найдена.',
                details: [],
                status: 404
            );
        }

        $tenantSchoolId = $tenantContext->getSchoolId();
        $user = $request->user();

        if ($tradition->school_id !== $tenantSchoolId && ! ($user && $user->isSystemAdmin())) {
            return ApiResponse::error(
                code: 'FORBIDDEN_TENANT_ACCESS',
                message: 'Доступ к традиции другой школы запрещен.',
                details: [],
                status: 403
            );
        }

        $validated = $request->validated();
        $academicYear = $validated['academic_year'];

        // Prevent duplicate versions for the exact same academic year (pre-check)
        $exists = TraditionVersion::where('tradition_id', $tradition->id)
            ->where('academic_year', $academicYear)
            ->exists();

        if ($exists) {
            return ApiResponse::error(
                code: 'TRADITION_VERSION_ALREADY_EXISTS',
                message: "Версия традиции на учебный год {$academicYear} уже существует.",
                details: [
                    [
                        'field' => 'academic_year',
                        'message' => 'Версия традиции на данный учебный год уже создана.',
                    ],
                ],
                status: 422
            );
        }

        // Try creating with concurrency / race condition guard
        try {
            $version = TraditionVersion::create([
                'tradition_id' => $tradition->id,
                'academic_year' => $academicYear,
                'parameters_snapshot' => $validated['parameters_snapshot'],
                'notes' => $validated['notes'] ?? null,
                'created_by_user_id' => $user->id,
            ]);
        } catch (UniqueConstraintViolationException|QueryException $e) {
            $sqlState = (string) ($e->errorInfo[0] ?? $e->getCode());
            $isUnique = ($e instanceof UniqueConstraintViolationException) || ($sqlState === '23505');
            // Specifically verify constraint uq_tradition_academic_year before returning 422 duplicate error
            if ($isUnique && (str_contains($e->getMessage(), 'uq_tradition_academic_year') || str_contains($e->getMessage(), 'academic_year'))) {
                return $this->duplicateVersionResponse($academicYear);
            }

            // Re-throw all other exceptions (and other unique violations) so they are not masked as 422
            throw $e;
        }

        return response()->json([
            'data' => [
                'id' => $version->id,
                'tradition_id' => $version->tradition_id,
                'academic_year' => $version->academic_year,
                'parameters_snapshot' => $version->parameters_snapshot,
                'notes' => $version->notes,
                'created_at' => $version->created_at?->toIso8601String(),
            ],
        ], 201);
    }

    /**
     * Show school tradition details with all yearly versions.
     */
    public function show(
        string $id,
        TenantContext $tenantContext,
        Request $request
    ): JsonResponse {
        $tenantSchoolId = $tenantContext->getSchoolId();
        $user = $request->user();

        $tradition = SchoolTradition::with(['versions' => fn ($q) => $q->orderBy('created_at', 'desc')])
            ->find($id);

        if (! $tradition) {
            return ApiResponse::error(
                code: 'TRADITION_NOT_FOUND',
                message: 'Школьная традиция не найдена.',
                details: [],
                status: 404
            );
        }

        if ($tradition->school_id !== $tenantSchoolId && ! ($user && $user->isSystemAdmin())) {
            return ApiResponse::error(
                code: 'FORBIDDEN_TENANT_ACCESS',
                message: 'Доступ к традиции другой школы запрещен.',
                details: [],
                status: 403
            );
        }

        $latestVersion = $tradition->versions->first();

        return ApiResponse::success([
            'id' => $tradition->id,
            'school_id' => $tradition->school_id,
            'title' => $tradition->title,
            'description' => $tradition->description,
            'established_year' => $tradition->established_year,
            'is_archived' => $tradition->is_archived,
            'created_by_user_id' => $tradition->created_by_user_id,
            'created_at' => $tradition->created_at?->toIso8601String(),
            'updated_at' => $tradition->updated_at?->toIso8601String(),
            'active_version' => $latestVersion ? [
                'id' => $latestVersion->id,
                'academic_year' => $latestVersion->academic_year,
                'parameters_snapshot' => $latestVersion->parameters_snapshot,
                'notes' => $latestVersion->notes,
                'created_at' => $latestVersion->created_at?->toIso8601String(),
            ] : null,
            'versions' => $tradition->versions->map(fn ($v) => [
                'id' => $v->id,
                'academic_year' => $v->academic_year,
                'parameters_snapshot' => $v->parameters_snapshot,
                'notes' => $v->notes,
                'created_at' => $v->created_at?->toIso8601String(),
            ])->values()->all(),
        ]);
    }

    /**
     * Update school tradition metadata (immutable versions are never touched).
     */
    public function update(
        string $id,
        UpdateTraditionRequest $request,
        TenantContext $tenantContext
    ): JsonResponse {
        $tenantSchoolId = $tenantContext->getSchoolId();
        $user = $request->user();

        $tradition = SchoolTradition::find($id);
        if (! $tradition) {
            return ApiResponse::error(
                code: 'TRADITION_NOT_FOUND',
                message: 'Школьная традиция не найдена.',
                details: [],
                status: 404
            );
        }

        if ($tradition->school_id !== $tenantSchoolId && ! ($user && $user->isSystemAdmin())) {
            return ApiResponse::error(
                code: 'FORBIDDEN_TENANT_ACCESS',
                message: 'Доступ к традиции другой школы запрещен.',
                details: [],
                status: 403
            );
        }

        $canEdit = ($user && $user->isSystemAdmin())
            || ($user && $user->hasSchoolRole($tenantSchoolId, 'school_admin'))
            || ($tradition->created_by_user_id === $user?->id);

        if (! $canEdit) {
            return ApiResponse::error(
                code: 'FORBIDDEN_TRADITION_EDIT',
                message: 'Редактировать традицию может только автор, администратор школы или системный администратор.',
                details: [],
                status: 403
            );
        }

        $validated = $request->validated();
        $tradition->update($validated);

        return ApiResponse::success([
            'id' => $tradition->id,
            'title' => $tradition->title,
            'description' => $tradition->description,
            'established_year' => $tradition->established_year,
            'is_archived' => $tradition->is_archived,
            'updated_at' => $tradition->updated_at?->toIso8601String(),
        ]);
    }

    /**
     * Archive a school tradition.
     */
    public function archive(
        string $id,
        TenantContext $tenantContext,
        Request $request
    ): JsonResponse {
        $tenantSchoolId = $tenantContext->getSchoolId();
        $user = $request->user();

        $tradition = SchoolTradition::find($id);
        if (! $tradition) {
            return ApiResponse::error(
                code: 'TRADITION_NOT_FOUND',
                message: 'Школьная традиция не найдена.',
                details: [],
                status: 404
            );
        }

        if ($tradition->school_id !== $tenantSchoolId && ! ($user && $user->isSystemAdmin())) {
            return ApiResponse::error(
                code: 'FORBIDDEN_TENANT_ACCESS',
                message: 'Доступ к традиции другой школы запрещен.',
                details: [],
                status: 403
            );
        }

        $canArchive = ($user && $user->isSystemAdmin())
            || ($user && $user->hasSchoolRole($tenantSchoolId, 'school_admin'))
            || ($tradition->created_by_user_id === $user?->id);

        if (! $canArchive) {
            return ApiResponse::error(
                code: 'FORBIDDEN_TRADITION_EDIT',
                message: 'Архивировать традицию может только автор, администратор школы или системный администратор.',
                details: [],
                status: 403
            );
        }

        if (! $tradition->is_archived) {
            $tradition->update(['is_archived' => true]);
        }

        return ApiResponse::success([
            'id' => $tradition->id,
            'title' => $tradition->title,
            'is_archived' => true,
            'updated_at' => $tradition->updated_at?->toIso8601String(),
        ]);
    }

    /**
     * Helper to return standard 422 duplicate tradition version error.
     */
    protected function duplicateVersionResponse(string $academicYear): JsonResponse
    {
        return ApiResponse::error(
            code: 'TRADITION_VERSION_ALREADY_EXISTS',
            message: "Версия традиции на учебный год {$academicYear} уже существует.",
            details: [
                [
                    'field' => 'academic_year',
                    'message' => 'Версия традиции на данный учебный год уже создана.',
                ],
            ],
            status: 422
        );
    }
}
