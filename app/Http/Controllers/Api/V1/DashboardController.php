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
        $upcomingEvents = CalendarEvent::query()
            ->visibleTo($user, $school)
            ->where('status', 'approved')
            ->with(['occurrences' => fn ($query) => $query->whereDate('start_date', '>=', $now->toDateString())->orderBy('start_date')])
            ->get()
            ->map(function (CalendarEvent $event) use ($now) {
                $next = $event->occurrences->first()?->start_date;
                if (! $next && $event->date_type === 'fixed_annual' && $event->fixed_month && $event->fixed_day) {
                    for ($year = $now->year; $year <= $now->year + 8; $year++) {
                        if (checkdate($event->fixed_month, $event->fixed_day, $year)) {
                            $candidate = $now->copy()->setDate($year, $event->fixed_month, $event->fixed_day)->startOfDay();
                            if ($candidate->greaterThanOrEqualTo($now->copy()->startOfDay())) {
                                $next = $candidate;
                                break;
                            }
                        }
                    }
                }
                if (! $next) {
                    return null;
                }

                return [
                    'id' => $event->id, 'title' => $event->title, 'event_type' => $event->event_type,
                    'region_code' => $event->region_code, 'fixed_month' => $next->month, 'fixed_day' => $next->day,
                    'next_date' => $next->toDateString(), 'description' => $event->description,
                ];
            })->filter()->sortBy('next_date')->take(5)->values();

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
