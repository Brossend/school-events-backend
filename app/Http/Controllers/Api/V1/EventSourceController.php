<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\CreateSourceReviewRequest;
use App\Http\Requests\Catalog\UpdateEventSourceRequest;
use App\Models\CalendarEvent;
use App\Models\CulturalClaimEvidenceAuditEvent;
use App\Models\CulturalClaimSource;
use App\Models\EventSource;
use App\Models\SourceReview;
use App\Services\ProvenanceVerificationService;
use App\Services\TenantContext;
use App\Services\TenantResolver;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EventSourceController extends Controller
{
    public function __construct(
        protected TenantResolver $tenantResolver,
        protected ProvenanceVerificationService $provenanceService
    ) {}

    /**
     * Show event source details with bibliographic info and active reviews.
     */
    public function show(
        string $id,
        Request $request,
        TenantContext $tenantContext
    ): JsonResponse {
        $user = $request->user();
        $school = $this->tenantResolver->resolve($request, $user, $tenantContext, allowSystemWithoutSchool: true);

        $source = EventSource::with([
            'event',
            'verifiedBy',
            'reviews' => fn ($q) => $q->with('reviewer')->orderBy('reviewed_at', 'desc')->orderBy('id', 'desc'),
            'claimSources.claim',
            'claimSources.verifiedBy',
        ])->find($id);

        if (! $source) {
            return ApiResponse::error(
                code: 'EVENT_SOURCE_NOT_FOUND',
                message: 'Первоисточник не найден.',
                details: [],
                status: 404
            );
        }

        // Visibility check against event
        if ($source->event && ! CalendarEvent::query()->visibleTo($user, $school)->where('id', $source->event->id)->exists()) {
            return ApiResponse::error(
                code: 'EVENT_SOURCE_NOT_FOUND',
                message: 'Первоисточник не найден или недоступен для вашей образовательной организации.',
                details: [],
                status: 404
            );
        }

        $latestReview = $source->reviews->first();

        $claims = $source->claimSources->map(function (CulturalClaimSource $cs) {
            return [
                'id' => $cs->id,
                'cultural_claim_id' => $cs->cultural_claim_id,
                'claim_text' => $cs->claim?->claim_text,
                'culture_name' => $cs->claim?->culture_name,
                'citation_locator' => $cs->citation_locator,
                'citation_excerpt' => $cs->citation_excerpt,
                'verification_status' => $this->provenanceService->evaluateStatus($cs),
                'verified_by' => $cs->verifiedBy ? [
                    'id' => $cs->verifiedBy->id,
                    'name' => $cs->verifiedBy->name,
                ] : null,
                'verified_at' => $cs->verified_at?->toIso8601String(),
                'applicable_academic_year' => $cs->applicable_academic_year,
                'notes' => $cs->notes,
            ];
        });

        return ApiResponse::success([
            'id' => $source->id,
            'calendar_event_id' => $source->calendar_event_id,
            'calendar_event' => $source->event ? [
                'id' => $source->event->id,
                'title' => $source->event->title,
                'event_type' => $source->event->event_type,
                'region_code' => $source->event->region_code,
            ] : null,
            'source_type' => $source->source_type,
            'title' => $source->title,
            'author' => $source->author,
            'url' => $source->url,
            'doi_or_isbn' => $source->doi_or_isbn,
            'verified_by' => $source->verifiedBy ? [
                'id' => $source->verifiedBy->id,
                'name' => $source->verifiedBy->name,
            ] : null,
            'verification_date' => $source->verification_date?->format('Y-m-d'),
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
            'reviews_count' => $source->reviews->count(),
            'claims_count' => $claims->count(),
            'claims' => $claims,
            'created_at' => $source->created_at?->toIso8601String(),
        ]);
    }

    /**
     * Update event source bibliographic information.
     */
    public function update(
        string $id,
        UpdateEventSourceRequest $request
    ): JsonResponse {
        $user = $request->user();

        if (! $user || (! $user->isCurator() && ! $user->isSystemAdmin())) {
            return ApiResponse::error(
                code: 'FORBIDDEN_CURATOR_ACCESS',
                message: 'Редактирование первоисточников разрешено только кураторам каталога и системным администраторам.',
                details: [],
                status: 403
            );
        }

        $source = EventSource::with('event')->find($id);
        if (! $source) {
            return ApiResponse::error(
                code: 'EVENT_SOURCE_NOT_FOUND',
                message: 'Первоисточник не найден.',
                details: [],
                status: 404
            );
        }

        // Regional curator scoping check
        if ($user->isCurator() && $user->curator_scope === 'regional') {
            $event = $source->event;
            $allowedRegions = $user->curated_region_codes ?? [];
            if ($event && ($event->event_type === 'federal' || ! in_array($event->region_code, $allowedRegions, true))) {
                return ApiResponse::error(
                    code: 'FORBIDDEN_REGIONAL_SCOPE',
                    message: 'У вас нет полномочий на редактирование источников для данного региона или федерального события.',
                    details: [],
                    status: 403
                );
            }
        }

        $validated = $request->validated();
        DB::transaction(function () use ($source, $validated, $user) {
            // Serialize bibliography changes and compute the delta from the locked row.
            $source = EventSource::whereKey($source->id)->lockForUpdate()->firstOrFail();
            $changedFields = [];
            foreach (['title', 'author', 'source_type', 'url', 'doi_or_isbn'] as $field) {
                if (array_key_exists($field, $validated)) {
                    $oldVal = $source->{$field} !== null ? trim((string) $source->{$field}) : null;
                    $newVal = $validated[$field] !== null ? trim((string) $validated[$field]) : null;
                    if ($oldVal !== $newVal) {
                        $changedFields[] = $field;
                    }
                }
            }
            // SourceReview::saved invalidates these rows. Capture BEFORE its side effects.
            $affectedClaims = CulturalClaimSource::where('event_source_id', $source->id)
                ->where('verification_status', 'verified_authentic')->lockForUpdate()->get();
            $source->update($validated);

            // If meaningful bibliographic fields changed, invalidate existing approval
            if (! empty($changedFields)) {
                $source->update([
                    'verified_by_user_id' => null,
                    'verification_date' => null,
                ]);

                // Create a system audit review invalidating prior approval
                SourceReview::create([
                    'event_source_id' => $source->id,
                    'reviewer_user_id' => $user->id,
                    'decision' => 'requires_amendment',
                    'comments' => 'Изменение библиографических реквизитов ('.implode(', ', $changedFields).'). Ранее выданные подтверждения аннулированы, требуется повторная экспертиза первоисточника.',
                    'reviewed_at' => now(),
                ]);

                // Invalidate all linked claims that were verified_authentic and log audit events
                foreach ($affectedClaims as $cs) {
                    CulturalClaimEvidenceAuditEvent::create([
                        'cultural_claim_source_id' => $cs->id,
                        'cultural_claim_id' => $cs->cultural_claim_id,
                        'event_source_id' => $source->id,
                        'action' => 'invalidated_by_source_amendment',
                        'actor_user_id' => $user->id,
                        'previous_verification_status' => 'verified_authentic',
                        'new_verification_status' => 'unverified',
                        'citation_locator' => $cs->citation_locator,
                        'citation_excerpt' => $cs->citation_excerpt,
                        'reason' => 'Аннулировано вследствие изменения библиографических реквизитов ('.implode(', ', $changedFields).') первоисточника.',
                        'created_at' => now(),
                    ]);
                }

                CulturalClaimSource::where('event_source_id', $source->id)
                    ->update([
                        'verification_status' => 'unverified',
                        'verified_by_user_id' => null,
                        'verified_at' => null,
                    ]);
            }
        });

        $source->refresh();

        return ApiResponse::success([
            'id' => $source->id,
            'calendar_event_id' => $source->calendar_event_id,
            'source_type' => $source->source_type,
            'title' => $source->title,
            'author' => $source->author,
            'url' => $source->url,
            'doi_or_isbn' => $source->doi_or_isbn,
            'verified_by_user_id' => $source->verified_by_user_id,
            'verification_date' => $source->verification_date?->format('Y-m-d'),
            'created_at' => $source->created_at?->toIso8601String(),
        ]);
    }

    /**
     * List all reviews for a specific event source.
     */
    public function listReviews(
        string $id,
        Request $request
    ): JsonResponse {
        $user = $request->user();

        if (! $user || (! $user->isCurator() && ! $user->isSystemAdmin())) {
            return ApiResponse::error(
                code: 'FORBIDDEN_CURATOR_ACCESS',
                message: 'Просмотр журнала экспертных рецензий разрешен только кураторам каталога и системным администраторам.',
                details: [],
                status: 403
            );
        }

        $source = EventSource::with(['event', 'reviews.reviewer'])->find($id);
        if (! $source) {
            return ApiResponse::error(
                code: 'EVENT_SOURCE_NOT_FOUND',
                message: 'Первоисточник не найден.',
                details: [],
                status: 404
            );
        }

        // Regional curator scoping check
        if ($user->isCurator() && $user->curator_scope === 'regional') {
            $event = $source->event;
            $allowedRegions = $user->curated_region_codes ?? [];
            if (! $event || $event->event_type === 'federal' || $event->region_code === null || ! in_array($event->region_code, $allowedRegions, true)) {
                return ApiResponse::error(
                    code: 'FORBIDDEN_REGIONAL_SCOPE',
                    message: 'Региональный куратор не имеет полномочий просматривать журнал рецензий для источников других регионов или федеральных событий.',
                    details: [],
                    status: 403
                );
            }
        }

        $reviews = $source->reviews()
            ->with('reviewer')
            ->orderBy('reviewed_at', 'desc')
            ->orderBy('id', 'desc')
            ->get()
            ->map(function (SourceReview $r) {
                return [
                    'id' => $r->id,
                    'event_source_id' => $r->event_source_id,
                    'decision' => $r->decision,
                    'comments' => $r->comments,
                    'reviewer' => $r->reviewer ? [
                        'id' => $r->reviewer->id,
                        'name' => $r->reviewer->name,
                        'system_role' => $r->reviewer->system_role,
                        'curator_scope' => $r->reviewer->curator_scope,
                    ] : null,
                    'reviewed_at' => $r->reviewed_at?->toIso8601String(),
                ];
            });

        return ApiResponse::success($reviews);
    }

    /**
     * Create an expert review decision on an event source.
     */
    public function storeReview(
        string $id,
        CreateSourceReviewRequest $request
    ): JsonResponse {
        $user = $request->user();

        if (! $user || (! $user->isCurator() && ! $user->isSystemAdmin())) {
            return ApiResponse::error(
                code: 'FORBIDDEN_CURATOR_ACCESS',
                message: 'Рецензирование первоисточников разрешено только кураторам каталога и системным администраторам.',
                details: [],
                status: 403
            );
        }

        $source = EventSource::with('event')->find($id);
        if (! $source) {
            return ApiResponse::error(
                code: 'EVENT_SOURCE_NOT_FOUND',
                message: 'Первоисточник не найден.',
                details: [],
                status: 404
            );
        }

        // Regional curator scoping check
        if ($user->isCurator() && $user->curator_scope === 'regional') {
            $event = $source->event;
            $allowedRegions = $user->curated_region_codes ?? [];
            if (! $event || $event->event_type === 'federal' || $event->region_code === null || ! in_array($event->region_code, $allowedRegions, true)) {
                return ApiResponse::error(
                    code: 'FORBIDDEN_REGIONAL_SCOPE',
                    message: 'Региональный куратор не имеет полномочий рецензировать источники для федеральных событий или событий вне своей зоны ответственности.',
                    details: [],
                    status: 403
                );
            }
        }

        $validated = $request->validated();
        $decision = $validated['decision'];
        $comments = $validated['comments'];

        $review = DB::transaction(function () use ($source, $user, $decision, $comments) {
            $source = EventSource::whereKey($source->id)->lockForUpdate()->firstOrFail();
            $newReview = SourceReview::create([
                'event_source_id' => $source->id,
                'reviewer_user_id' => $user->id,
                'decision' => $decision,
                'comments' => $comments,
                'reviewed_at' => now(),
            ]);

            if ($decision === 'approved') {
                $source->update([
                    'verified_by_user_id' => $user->id,
                    'verification_date' => now()->toDateString(),
                ]);
                // NOTE: In accordance with BE-006.1 P0 separation:
                // Approving a source confirms the authenticity of the source itself.
                // It does NOT automatically elevate associated cultural claims to verified_authentic!
                // Claims remain unverified until individually attested with specific citations and locators.
            } elseif ($decision === 'rejected') {
                $source->update([
                    'verified_by_user_id' => null,
                    'verification_date' => null,
                ]);

                CulturalClaimSource::where('event_source_id', $source->id)
                    ->update([
                        'verification_status' => 'rejected_fake',
                        'verified_by_user_id' => null,
                        'verified_at' => null,
                    ]);
            } elseif ($decision === 'requires_amendment') {
                $source->update([
                    'verified_by_user_id' => null,
                    'verification_date' => null,
                ]);

                CulturalClaimSource::where('event_source_id', $source->id)
                    ->update([
                        'verification_status' => 'unverified',
                        'verified_by_user_id' => null,
                        'verified_at' => null,
                    ]);
            }

            return $newReview->fresh(['reviewer']);
        });

        return response()->json([
            'data' => [
                'id' => $review->id,
                'event_source_id' => $review->event_source_id,
                'decision' => $review->decision,
                'comments' => $review->comments,
                'reviewer' => [
                    'id' => $review->reviewer->id,
                    'name' => $review->reviewer->name,
                    'system_role' => $review->reviewer->system_role,
                ],
                'reviewed_at' => $review->reviewed_at?->toIso8601String(),
            ],
        ], 201);
    }
}
