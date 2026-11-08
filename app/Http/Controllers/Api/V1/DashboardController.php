<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CalendarEvent;
use App\Models\Scenario;
use App\Models\SchoolTradition;
use App\Services\TenantContext;
use App\Services\TenantResolver;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(
        protected TenantResolver $tenantResolver
    ) {}

    /**
     * Get aggregated dashboard summary for the active school tenant.
     */
    public function summary(
        Request $request,
        TenantContext $tenantContext
    ): JsonResponse {
        $user = $request->user();
        $school = $this->tenantResolver->resolve($request, $user, $tenantContext, allowSystemWithoutSchool: false);

        if (! $school) {
            return ApiResponse::error(
                code: 'TENANT_REQUIRED',
                message: 'Для просмотра сводной панели требуется активный контекст школы.',
                details: [],
                status: 403
            );
        }

        $isSchoolAdmin = $user && $user->hasSchoolRole($school->id, 'school_admin');
        $isSystemAdmin = $user && $user->isSystemAdmin();

        // 1. Upcoming calendar events visible to this school (taking into account current date and year-end wraparound)
        $now = now();
        $curMonth = (int) $now->format('n');
        $curDay = (int) $now->format('j');

        $upcomingEvents = CalendarEvent::query()
            ->visibleTo($user, $school)
            ->where('status', 'approved')
            ->whereNotNull('fixed_month')
            ->whereNotNull('fixed_day')
            ->orderByRaw('CASE WHEN (fixed_month > ? OR (fixed_month = ? AND fixed_day >= ?)) THEN 0 ELSE 1 END', [$curMonth, $curMonth, $curDay])
            ->orderBy('fixed_month', 'asc')
            ->orderBy('fixed_day', 'asc')
            ->limit(5)
            ->get(['id', 'title', 'event_type', 'region_code', 'fixed_month', 'fixed_day', 'description'])
            ->map(fn ($ev) => [
                'id' => $ev->id,
                'title' => $ev->title,
                'event_type' => $ev->event_type,
                'region_code' => $ev->region_code,
                'fixed_month' => $ev->fixed_month,
                'fixed_day' => $ev->fixed_day,
                'description' => $ev->description,
            ]);

        // 2. Recent scenarios query
        $scenariosQuery = Scenario::query()->where('school_id', $school->id);
        if (! $isSystemAdmin && ! $isSchoolAdmin) {
            $scenariosQuery->where('user_id', $user?->id);
        }

        $recentScenarios = (clone $scenariosQuery)
            ->with(['currentRevision.moderationState'])
            ->withExists([
                'currentRevision as has_signoff' => fn ($rq) => $rq->whereHas('teacherSignoffs', fn ($sq) => $sq->where('is_confirmed', true)),
            ])
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get()
            ->map(function (Scenario $s) {
                $status = $s->is_archived ? 'archived' : ($s->has_signoff ? 'completed' : 'draft');

                return [
                    'id' => $s->id,
                    'title' => $s->title,
                    'status' => $status,
                    'is_archived' => $s->is_archived,
                    'moderation_status' => $s->currentRevision?->moderationState?->status ?? 'pending_verification',
                    'current_revision_id' => $s->current_revision_id,
                    'created_at' => $s->created_at?->toIso8601String(),
                    'updated_at' => $s->updated_at?->toIso8601String(),
                ];
            });

        // 3. Counts
        $totalScenarios = (clone $scenariosQuery)->where('is_archived', false)->count();
        $draftScenarios = (clone $scenariosQuery)
            ->where('is_archived', false)
            ->whereDoesntHave('currentRevision.teacherSignoffs', fn ($sq) => $sq->where('is_confirmed', true))
            ->count();
        $completedScenarios = (clone $scenariosQuery)
            ->where('is_archived', false)
            ->whereHas('currentRevision.teacherSignoffs', fn ($sq) => $sq->where('is_confirmed', true))
            ->count();
        $archivedScenarios = (clone $scenariosQuery)->where('is_archived', true)->count();

        $traditionsCount = SchoolTradition::where('school_id', $school->id)
            ->where('is_archived', false)
            ->count();

        return ApiResponse::success([
            'school' => [
                'id' => $school->id,
                'name' => $school->name,
                'short_name' => $school->short_name,
                'region_code' => $school->region_code,
                'city' => $school->city,
            ],
            'upcoming_events' => $upcomingEvents,
            'recent_scenarios' => $recentScenarios,
            'counts' => [
                'total_scenarios' => $totalScenarios,
                'draft_scenarios' => $draftScenarios,
                'completed_scenarios' => $completedScenarios,
                'archived_scenarios' => $archivedScenarios,
                'traditions_count' => $traditionsCount,
            ],
        ]);
    }
}
