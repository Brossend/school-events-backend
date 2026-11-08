<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\EventSource;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CatalogReviewController extends Controller
{
    /**
     * Get queue of event sources and claims for curator review.
     * Accessible strictly to catalog_curator and system_admin.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user || (! $user->isCurator() && ! $user->isSystemAdmin())) {
            return ApiResponse::error(
                code: 'FORBIDDEN_CURATOR_ACCESS',
                message: 'Доступ к очереди рецензирования разрешен только кураторам каталога и системным администраторам.',
                details: [],
                status: 403
            );
        }

        $query = EventSource::query()->with([
            'event',
            'verifiedBy',
            'reviews' => fn ($q) => $q->with('reviewer')->orderBy('reviewed_at', 'desc')->orderBy('id', 'desc'),
            'claimSources',
        ]);

        // Regional curator scoping: only see curated regions + federal events
        if ($user->isCurator() && $user->curator_scope === 'regional') {
            $allowedRegions = $user->curated_region_codes ?? [];
            $query->whereHas('event', function (Builder $q) use ($allowedRegions) {
                $q->where('event_type', 'federal')
                    ->orWhereNull('region_code')
                    ->orWhereIn('region_code', $allowedRegions);
            });
        }

        // Filter by region_code
        if ($request->filled('region_code')) {
            $regionCode = (string) $request->input('region_code');
            $query->whereHas('event', function (Builder $q) use ($regionCode) {
                $q->where('region_code', $regionCode);
            });
        }

        // Filter by event_type
        if ($request->filled('event_type')) {
            $eventType = (string) $request->input('event_type');
            $query->whereHas('event', function (Builder $q) use ($eventType) {
                $q->where('event_type', $eventType);
            });
        }

        // Correlated subquery for deterministic latest review decision (reviewed_at DESC, id DESC)
        $latestDecisionSubSql = DB::table('source_reviews')
            ->select('decision')
            ->whereColumn('source_reviews.event_source_id', 'event_sources.id')
            ->orderByDesc('reviewed_at')
            ->orderByDesc('id')
            ->limit(1)
            ->toSql();

        // Filter by review status (effective latest decision)
        $status = $request->input('status', 'pending');
        if ($status === 'pending') {
            $query->where(function (Builder $q) use ($latestDecisionSubSql) {
                $q->whereDoesntHave('reviews')
                    ->orWhereRaw("({$latestDecisionSubSql}) = ?", ['requires_amendment']);
            });
        } elseif (in_array($status, ['approved', 'rejected', 'requires_amendment'], true)) {
            $query->whereRaw("({$latestDecisionSubSql}) = ?", [$status]);
        }

        $perPage = min(100, max(5, (int) $request->input('per_page', 20)));
        $sources = $query->orderBy('created_at', 'desc')->orderBy('id', 'desc')->paginate($perPage);

        $items = collect($sources->items())->map(function (EventSource $source) {
            $latestReview = $source->reviews->first();

            return [
                'id' => $source->id,
                'calendar_event_id' => $source->calendar_event_id,
                'calendar_event' => $source->event ? [
                    'id' => $source->event->id,
                    'title' => $source->event->title,
                    'event_type' => $source->event->event_type,
                    'region_code' => $source->event->region_code,
                    'status' => $source->event->status,
                ] : null,
                'source_type' => $source->source_type,
                'title' => $source->title,
                'author' => $source->author,
                'url' => $source->url,
                'doi_or_isbn' => $source->doi_or_isbn,
                'claims_count' => $source->claimSources->count(),
                'status' => $latestReview?->decision ?? 'pending',
                'latest_review' => $latestReview ? [
                    'id' => $latestReview->id,
                    'decision' => $latestReview->decision,
                    'comments' => $latestReview->comments,
                    'reviewer' => $latestReview->reviewer ? [
                        'id' => $latestReview->reviewer->id,
                        'name' => $latestReview->reviewer->name,
                        'system_role' => $latestReview->reviewer->system_role,
                    ] : null,
                    'reviewed_at' => $latestReview->reviewed_at?->toIso8601String(),
                ] : null,
                'verified_by' => $source->verifiedBy ? [
                    'id' => $source->verifiedBy->id,
                    'name' => $source->verifiedBy->name,
                ] : null,
                'verification_date' => $source->verification_date?->format('Y-m-d'),
                'created_at' => $source->created_at?->toIso8601String(),
            ];
        });

        return ApiResponse::success(
            data: $items,
            meta: [
                'current_page' => $sources->currentPage(),
                'total' => $sources->total(),
                'per_page' => $sources->perPage(),
                'last_page' => $sources->lastPage(),
            ]
        );
    }
}
