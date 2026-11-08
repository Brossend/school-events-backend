<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Schools\UpdateSchoolProfileRequest;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\SchoolTradition;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SchoolProfileController extends Controller
{
    /**
     * Get detailed school profile.
     * Accessible to school members and system administrators.
     */
    public function show(string $id, Request $request): JsonResponse
    {
        $user = $request->user();

        $school = School::find($id);
        if (! $school) {
            return ApiResponse::error(
                code: 'SCHOOL_NOT_FOUND',
                message: 'Школа не найдена.',
                details: [],
                status: 404
            );
        }

        $isMember = $user && $school->memberships()->where('user_id', $user->id)->exists();
        if (! $isMember && ! ($user && $user->isSystemAdmin())) {
            return ApiResponse::error(
                code: 'FORBIDDEN_TENANT_ACCESS',
                message: 'Доступ к профилю данной образовательной организации запрещен.',
                details: [],
                status: 403
            );
        }

        $traditionsCount = SchoolTradition::where('school_id', $school->id)
            ->where('is_archived', false)
            ->count();

        $activeTeachersCount = SchoolMembership::where('school_id', $school->id)
            ->where('role', 'teacher')
            ->whereHas('user', fn ($q) => $q->where('is_active', true))
            ->count();

        return ApiResponse::success([
            'id' => $school->id,
            'name' => $school->name,
            'short_name' => $school->short_name,
            'region_code' => $school->region_code,
            'city' => $school->city,
            'inn' => $school->inn,
            'settings' => $school->settings ?? [],
            'traditions_count' => $traditionsCount,
            'active_teachers_count' => $activeTeachersCount,
            'created_at' => $school->created_at?->toIso8601String(),
            'updated_at' => $school->updated_at?->toIso8601String(),
        ]);
    }

    /**
     * Update school profile information.
     * Accessible strictly to school_admin of that school or system_admin.
     */
    public function update(
        string $id,
        UpdateSchoolProfileRequest $request
    ): JsonResponse {
        $user = $request->user();

        $school = School::find($id);
        if (! $school) {
            return ApiResponse::error(
                code: 'SCHOOL_NOT_FOUND',
                message: 'Школа не найдена.',
                details: [],
                status: 404
            );
        }

        $isSchoolAdmin = $user && $user->hasSchoolRole($school->id, 'school_admin');
        if (! $isSchoolAdmin && ! ($user && $user->isSystemAdmin())) {
            return ApiResponse::error(
                code: 'FORBIDDEN_SCHOOL_ADMIN_ACCESS',
                message: 'Редактировать профиль школы могут только администраторы этой школы или системные администраторы.',
                details: [],
                status: 403
            );
        }

        $validated = $request->validated();

        if (isset($validated['name'])) {
            $school->name = $validated['name'];
        }
        if (array_key_exists('short_name', $validated)) {
            $school->short_name = $validated['short_name'];
        }
        if (isset($validated['city'])) {
            $school->city = $validated['city'];
        }
        if (array_key_exists('inn', $validated)) {
            $school->inn = $validated['inn'];
        }
        if (isset($validated['settings'])) {
            $currentSettings = $school->settings ?? [];
            $school->settings = array_merge($currentSettings, $validated['settings']);
        }

        $school->save();

        $traditionsCount = SchoolTradition::where('school_id', $school->id)
            ->where('is_archived', false)
            ->count();

        $activeTeachersCount = SchoolMembership::where('school_id', $school->id)
            ->where('role', 'teacher')
            ->whereHas('user', fn ($q) => $q->where('is_active', true))
            ->count();

        return ApiResponse::success([
            'id' => $school->id,
            'name' => $school->name,
            'short_name' => $school->short_name,
            'region_code' => $school->region_code,
            'city' => $school->city,
            'inn' => $school->inn,
            'settings' => $school->settings ?? [],
            'traditions_count' => $traditionsCount,
            'active_teachers_count' => $activeTeachersCount,
            'created_at' => $school->created_at?->toIso8601String(),
            'updated_at' => $school->updated_at?->toIso8601String(),
        ]);
    }
}
