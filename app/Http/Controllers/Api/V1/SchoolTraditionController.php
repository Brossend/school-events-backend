<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Traditions\CreateTraditionRequest;
use App\Http\Requests\Traditions\CreateTraditionVersionRequest;
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

        $traditions = SchoolTradition::where('school_id', $schoolId)
            ->orderBy('created_at', 'desc')
            ->get();

        $data = $traditions->map(function (SchoolTradition $t) {
            return [
                'id' => $t->id,
                'title' => $t->title,
                'description' => $t->description,
                'established_year' => $t->established_year,
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
