<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Services\PilotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class PilotMetricsController extends Controller
{
    public function __construct(
        protected PilotService $pilotService
    ) {}

    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();
        $requestedScope = $request->query('scope', 'school');

        if ($requestedScope === 'global') {
            $isGlobalAllowed = $user && (
                $user->isSystemAdmin() ||
                ($user->isCurator() && $user->curator_scope !== 'regional')
            );
            if (! $isGlobalAllowed) {
                throw new AccessDeniedHttpException('Доступ к общесистемным глобальным показателям разрешен только системным администраторам и глобальным кураторам.');
            }
            $scope = 'global';
            $schoolId = null;
        } else {
            $scope = 'school';
            $tenantResolver = app(\App\Services\TenantResolver::class);
            $school = $tenantResolver->resolveAnalyticalSchool($request, $user);
            $schoolId = $school->id;
        }

        $includeSynthetic = $request->boolean('include_synthetic', false);

        $metrics = $this->pilotService->calculateMetrics($schoolId, $scope, $includeSynthetic);

        return response()->json([
            'data' => $metrics,
        ]);
    }

    public function export(Request $request): JsonResponse
    {
        $user = $request->user();
        $requestedScope = $request->query('scope', 'school');

        if ($requestedScope === 'global') {
            $isGlobalAllowed = $user && (
                $user->isSystemAdmin() ||
                ($user->isCurator() && $user->curator_scope !== 'regional')
            );
            if (! $isGlobalAllowed) {
                throw new AccessDeniedHttpException('Экспорт общесистемных глобальных показателей разрешен только системным администраторам и глобальным кураторам.');
            }
            $scope = 'global';
            $schoolId = null;
        } else {
            $scope = 'school';
            $tenantResolver = app(\App\Services\TenantResolver::class);
            $school = $tenantResolver->resolveAnalyticalSchool($request, $user);

            $this->authorizePilotAdmin($request, $school);
            $schoolId = $school->id;
        }

        $includeSynthetic = $request->boolean('include_synthetic', false);

        $metrics = $this->pilotService->calculateMetrics($schoolId, $scope, $includeSynthetic);

        return response()->json([
            'data' => [
                'exported_at' => now()->toIso8601String(),
                'scope' => $scope,
                'school_id' => ($scope === 'school') ? $schoolId : null,
                'metrics' => $metrics,
            ],
        ]);
    }

    protected function authorizePilotAdmin(Request $request, School $school): void
    {
        $user = $request->user();
        if (! $user || ! $user->is_active) {
            throw new AccessDeniedHttpException('Требуется авторизация активного пользователя.');
        }

        if ($user->isSystemAdmin()) {
            return;
        }

        if ($user->isCurator()) {
            if ($user->curator_scope === 'regional') {
                $allowedRegions = $user->curated_region_codes ?? [];
                if (! in_array($school->region_code, $allowedRegions, true)) {
                    throw new AccessDeniedHttpException("Куратор региона не имеет доступа к экспорту пилотных данных школы региона '{$school->region_code}'.");
                }
            }
            return;
        }

        if ($user->hasSchoolRole($school->id, 'school_admin')) {
            return;
        }

        throw new AccessDeniedHttpException('Экспорт данных пилота разрешен только школьным администраторам, кураторам и системным администраторам.');
    }
}
