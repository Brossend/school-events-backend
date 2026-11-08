<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Calendar\CreateCalendarEventRequest;
use App\Http\Requests\Calendar\ListCalendarEventsRequest;
use App\Models\CalendarEvent;
use App\Services\ProvenanceVerificationService;
use App\Services\TenantContext;
use App\Services\TenantResolver;
use App\Support\ApiResponse;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CalendarEventController extends Controller
{
    public function __construct(
        protected TenantResolver $tenantResolver,
        protected ProvenanceVerificationService $provenanceService
    ) {}

    /**
     * List calendar events accessible to the current tenant school or system user.
     */
    public function index(ListCalendarEventsRequest $request, TenantContext $tenantContext): JsonResponse
    {
        $user = $request->user();
        $school = $this->tenantResolver->resolve($request, $user, $tenantContext, allowSystemWithoutSchool: true);

        $query = CalendarEvent::query()->with('occurrences')->visibleTo($user, $school);

        // Filter by region_code within allowed visibility
        if ($request->filled('region_code')) {
            $region = (string) $request->input('region_code');
            $query->where('region_code', $region);
        }

        // Filter by event_type
        if ($request->filled('type')) {
            $query->where('event_type', $request->input('type'));
        }

        // Filter by search query (case-insensitive in title and description)
        if ($request->filled('q')) {
            $searchTerm = (string) $request->input('q');
            $query->where(function (Builder $q) use ($searchTerm) {
                $q->where('title', 'ilike', "%{$searchTerm}%")
                    ->orWhere('description', 'ilike', "%{$searchTerm}%");
            });
        }

        // Filter by academic year
        if ($request->filled('academic_year')) {
            $academicYear = (string) $request->input('academic_year');
            $query->whereHas('occurrences', function (Builder $q) use ($academicYear) {
                $q->where('academic_year', $academicYear);
            });
        }

        // Filter by date range (handling multi-day occurrences and fixed annual dates)
        $from = $request->input('from');
        $to = $request->input('to');
        if ($from || $to) {
            $query->where(function (Builder $dateQ) use ($from, $to) {
                // 1. Concrete event occurrences with interval overlap
                $dateQ->whereHas('occurrences', function (Builder $occQ) use ($from, $to) {
                    if ($from && $to) {
                        $occQ->where('start_date', '<=', $to)
                            ->where(function (Builder $sub) use ($from) {
                                $sub->where('end_date', '>=', $from)
                                    ->orWhere(function (Builder $s) use ($from) {
                                        $s->whereNull('end_date')
                                            ->where('start_date', '>=', $from);
                                    });
                            });
                    } elseif ($from) {
                        $occQ->where(function (Builder $sub) use ($from) {
                            $sub->where('end_date', '>=', $from)
                                ->orWhere(function (Builder $s) use ($from) {
                                    $s->whereNull('end_date')
                                        ->where('start_date', '>=', $from);
                                });
                        });
                    } elseif ($to) {
                        $occQ->where('start_date', '<=', $to);
                    }
                });

                // 2. Fixed annual recurring events without occurrence rows
                $dateQ->orWhere(function (Builder $fixedQ) use ($from, $to) {
                    $fixedQ->where('date_type', 'fixed_annual')
                        ->whereNotNull('fixed_month')
                        ->whereNotNull('fixed_day');

                    // If neither or only one bound is provided, recurring annual events always occur
                    // (either in future years for ?from=... or in past years for ?to=...)
                    if ($from && $to) {
                        $fromDt = Carbon::parse($from);
                        $toDt = Carbon::parse($to);

                        // If range spans a full year or more, all annual events occur within it
                        if ($fromDt->diffInDays($toDt) >= 365) {
                            return;
                        }

                        $fromMd = $fromDt->month * 100 + $fromDt->day;
                        $toMd = $toDt->month * 100 + $toDt->day;

                        if ($fromDt->year === $toDt->year) {
                            $fixedQ->whereRaw('(fixed_month * 100 + fixed_day) BETWEEN ? AND ?', [$fromMd, $toMd]);
                        } else {
                            // Crossing calendar year boundary (e.g. November 2026 to February 2027)
                            $fixedQ->where(function (Builder $orQ) use ($fromMd, $toMd) {
                                $orQ->whereRaw('(fixed_month * 100 + fixed_day) >= ?', [$fromMd])
                                    ->orWhereRaw('(fixed_month * 100 + fixed_day) <= ?', [$toMd]);
                            });
                        }
                    }
                });
            });
        }

        $perPage = (int) $request->input('per_page', 20);
        $paginator = $query->orderBy('created_at', 'desc')->paginate($perPage);

        $data = collect($paginator->items())->map(function (CalendarEvent $event) {
            return [
                'id' => $event->id,
                'title' => $event->title,
                'event_type' => $event->event_type,
                'region_code' => $event->region_code,
                'description' => $event->description,
                'date_type' => $event->date_type,
                'fixed_month' => $event->fixed_month,
                'fixed_day' => $event->fixed_day,
                'occurrences' => $event->occurrences->sortBy('start_date')->values()->map(fn ($row) => [
                    'id' => $row->id, 'academic_year' => $row->academic_year,
                    'start_date' => $row->start_date->format('Y-m-d'), 'end_date' => $row->end_date?->format('Y-m-d'),
                    'source_reference' => $row->source_reference,
                ]),
            ];
        });

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /**
     * Get detailed calendar event data with dynamically evaluated verified sources and cultural claims.
     */
    public function show(Request $request, string $id, TenantContext $tenantContext): JsonResponse
    {
        $user = $request->user();
        $school = $this->tenantResolver->resolve($request, $user, $tenantContext, allowSystemWithoutSchool: true);

        // Subordinate direct UUID lookup strictly to visibleTo scope
        $event = CalendarEvent::with([
            'sources.reviews',
            'culturalClaims.evidenceRecords.source.reviews',
            'culturalClaims.evidenceRecords.verifiedBy',
            'culturalClaims.event',
        ])->visibleTo($user, $school)->find($id);

        if (! $event) {
            return ApiResponse::error(
                code: 'EVENT_NOT_FOUND',
                message: 'Событие календаря не найдено.',
                details: [],
                status: 404
            );
        }

        $sources = $event->sources->map(function ($source) {
            return [
                'id' => $source->id,
                'title' => $source->title,
                'url' => $source->url,
            ];
        });

        $culturalClaims = $event->culturalClaims->map(function ($claim) {
            return [
                'id' => $claim->id,
                'claim_text' => $claim->claim_text,
                'culture_name' => $claim->culture_name,
                'evidence_records' => $claim->evidenceRecords->map(function ($rec) {
                    return [
                        'event_source_id' => $rec->event_source_id,
                        'citation_locator' => $rec->citation_locator,
                        'citation_excerpt' => $rec->citation_excerpt,
                        'verification_status' => $this->provenanceService->evaluateStatus($rec),
                        'notes' => $rec->notes,
                    ];
                }),
            ];
        });

        return ApiResponse::success([
            'id' => $event->id,
            'title' => $event->title,
            'event_type' => $event->event_type,
            'region_code' => $event->region_code,
            'description' => $event->description,
            'date_type' => $event->date_type, 'fixed_month' => $event->fixed_month, 'fixed_day' => $event->fixed_day,
            'occurrences' => $event->occurrences->sortBy('start_date')->values()->map(fn ($row) => [
                'id' => $row->id, 'academic_year' => $row->academic_year, 'start_date' => $row->start_date->format('Y-m-d'),
                'end_date' => $row->end_date?->format('Y-m-d'), 'source_reference' => $row->source_reference,
            ]),
            'sources' => $sources,
            'cultural_claims' => $culturalClaims,
        ]);
    }

    /**
     * Create a calendar event enforcing strict role and regional boundaries.
     */
    public function store(CreateCalendarEventRequest $request, TenantContext $tenantContext): JsonResponse
    {
        $user = $request->user();
        $school = $this->tenantResolver->resolve($request, $user, $tenantContext, allowSystemWithoutSchool: true);
        $roleInSchool = $tenantContext->getRole();
        $userRole = $user->system_role;

        $validated = $request->validated();
        $eventType = $validated['event_type'];

        // 1. Teachers cannot create catalog events
        if ($userRole === 'none' && $roleInSchool !== 'school_admin') {
            return ApiResponse::error(
                code: 'FORBIDDEN',
                message: 'Учителя не имеют прав на создание событий календаря.',
                details: [],
                status: 403
            );
        }

        // 2. School Admin creating school custom events
        if ($roleInSchool === 'school_admin' && $userRole === 'none') {
            if ($eventType !== 'school_custom') {
                return ApiResponse::error(
                    code: 'FORBIDDEN',
                    message: 'Администраторы школ могут создавать только пользовательские события своей школы (school_custom).',
                    details: [],
                    status: 403
                );
            }

            if (! $school) {
                return ApiResponse::error(
                    code: 'TENANT_REQUIRED',
                    message: 'Создание школьного события требует активного контекста школы.',
                    details: [],
                    status: 403
                );
            }

            $targetSchoolId = $school->id;
            $status = $validated['status'] ?? 'approved';
        } elseif ($userRole === 'catalog_curator') {
            // 3. Catalog Curator
            if ($eventType === 'school_custom') {
                return ApiResponse::error(
                    code: 'VALIDATION_FAILED',
                    message: 'События типа school_custom не могут быть созданы куратором каталога без привязки к школе.',
                    details: [],
                    status: 422
                );
            }

            if ($user->curator_scope === 'regional') {
                if ($eventType === 'federal') {
                    return ApiResponse::error(
                        code: 'FORBIDDEN',
                        message: 'Региональный куратор не имеет прав на создание федеральных событий.',
                        details: [],
                        status: 403
                    );
                }

                $region = $validated['region_code'] ?? null;
                $allowedRegions = $user->curated_region_codes ?? [];

                if (! $region || ! in_array($region, $allowedRegions, true)) {
                    return ApiResponse::error(
                        code: 'FORBIDDEN_REGIONAL_SCOPE',
                        message: "У вас нет кураторского доступа к региону {$region}.",
                        details: [],
                        status: 403
                    );
                }
            }

            $targetSchoolId = null;
            // New global catalog entries submitted by curators require moderation before publishing
            $status = 'pending_review';
        } else {
            // 4. System Administrator
            if ($eventType === 'school_custom') {
                if (! $school) {
                    return ApiResponse::error(
                        code: 'VALIDATION_FAILED',
                        message: 'Создание пользовательского события школы требует указания активной школы.',
                        details: [],
                        status: 422
                    );
                }
                $targetSchoolId = $school->id;
            } else {
                $targetSchoolId = null;
            }

            $status = $validated['status'] ?? 'approved';
        }

        $event = CalendarEvent::create([
            'school_id' => $targetSchoolId,
            'title' => $validated['title'],
            'event_type' => $validated['event_type'],
            'region_code' => $validated['region_code'] ?? null,
            'date_type' => $validated['date_type'],
            'fixed_month' => $validated['fixed_month'] ?? null,
            'fixed_day' => $validated['fixed_day'] ?? null,
            'description' => $validated['description'],
            'target_age_groups' => $validated['target_age_groups'],
            'recommended_value_directions' => $validated['recommended_value_directions'],
            'status' => $status,
        ]);

        return response()->json([
            'data' => [
                'id' => $event->id,
                'title' => $event->title,
                'event_type' => $event->event_type,
                'region_code' => $event->region_code,
                'description' => $event->description,
                'date_type' => $event->date_type,
                'fixed_month' => $event->fixed_month,
                'fixed_day' => $event->fixed_day,
                'occurrences' => $event->occurrences->sortBy('start_date')->values()->map(fn ($row) => [
                    'id' => $row->id, 'academic_year' => $row->academic_year,
                    'start_date' => $row->start_date->format('Y-m-d'), 'end_date' => $row->end_date?->format('Y-m-d'),
                    'source_reference' => $row->source_reference,
                ]),
                'sources' => [],
                'cultural_claims' => [],
            ],
        ], 201);
    }
}
