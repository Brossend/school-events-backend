<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\AttachClaimEvidenceRequest;
use App\Models\CalendarEvent;
use App\Models\CulturalClaim;
use App\Models\CulturalClaimEvidenceAuditEvent;
use App\Models\CulturalClaimSource;
use App\Models\EventSource;
use App\Services\ProvenanceVerificationService;
use App\Services\TenantContext;
use App\Services\TenantResolver;
use App\Support\ApiResponse;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CulturalClaimController extends Controller
{
    public function __construct(
        protected TenantResolver $tenantResolver,
        protected ProvenanceVerificationService $provenanceService
    ) {}

    /**
     * Show cultural claim details with attached evidence records.
     */
    public function show(
        string $id,
        Request $request,
        TenantContext $tenantContext
    ): JsonResponse {
        $user = $request->user();
        $school = $this->tenantResolver->resolve($request, $user, $tenantContext, allowSystemWithoutSchool: true);

        $claim = CulturalClaim::with([
            'event',
            'evidenceRecords.source',
            'evidenceRecords.verifiedBy',
            'evidenceAuditEvents.actor',
        ])->find($id);

        if (! $claim) {
            return ApiResponse::error(
                code: 'CULTURAL_CLAIM_NOT_FOUND',
                message: 'Культурное утверждение не найдено.',
                details: [],
                status: 404
            );
        }

        // Visibility check against event
        if ($claim->event && ! CalendarEvent::query()->visibleTo($user, $school)->where('id', $claim->event->id)->exists()) {
            return ApiResponse::error(
                code: 'CULTURAL_CLAIM_NOT_FOUND',
                message: 'Культурное утверждение не найдено или недоступно для вашей образовательной организации.',
                details: [],
                status: 404
            );
        }

        $evidenceRecords = $claim->evidenceRecords->map(function (CulturalClaimSource $cs) {
            return [
                'id' => $cs->id,
                'event_source_id' => $cs->event_source_id,
                'source' => $cs->source ? [
                    'id' => $cs->source->id,
                    'title' => $cs->source->title,
                    'author' => $cs->source->author,
                    'source_type' => $cs->source->source_type,
                    'url' => $cs->source->url,
                    'doi_or_isbn' => $cs->source->doi_or_isbn,
                ] : null,
                'citation_locator' => $cs->citation_locator,
                'citation_excerpt' => $cs->citation_excerpt,
                'verification_status' => $cs->verification_status,
                'effective_status' => $this->provenanceService->evaluateStatus($cs),
                'is_detached' => (bool) $cs->is_detached,
                'detached_at' => $cs->detached_at?->toIso8601String(),
                'detachment_reason' => $cs->detachment_reason,
                'verified_by' => $cs->verifiedBy ? [
                    'id' => $cs->verifiedBy->id,
                    'name' => $cs->verifiedBy->name,
                ] : null,
                'verified_at' => $cs->verified_at?->toIso8601String(),
                'applicable_academic_year' => $cs->applicable_academic_year,
                'notes' => $cs->notes,
                'created_at' => $cs->created_at?->toIso8601String(),
            ];
        });

        $auditEvents = $claim->evidenceAuditEvents->map(function (CulturalClaimEvidenceAuditEvent $ae) {
            return [
                'id' => $ae->id,
                'cultural_claim_source_id' => $ae->cultural_claim_source_id,
                'event_source_id' => $ae->event_source_id,
                'action' => $ae->action,
                'actor_user_id' => $ae->actor_user_id,
                'actor' => $ae->actor ? [
                    'id' => $ae->actor->id,
                    'name' => $ae->actor->name,
                ] : null,
                'previous_verification_status' => $ae->previous_verification_status,
                'new_verification_status' => $ae->new_verification_status,
                'citation_locator' => $ae->citation_locator,
                'citation_excerpt' => $ae->citation_excerpt,
                'reason' => $ae->reason,
                'created_at' => $ae->created_at?->toIso8601String(),
            ];
        });

        return ApiResponse::success([
            'id' => $claim->id,
            'calendar_event_id' => $claim->calendar_event_id,
            'calendar_event' => $claim->event ? [
                'id' => $claim->event->id,
                'title' => $claim->event->title,
                'event_type' => $claim->event->event_type,
                'region_code' => $claim->event->region_code,
            ] : null,
            'claim_text' => $claim->claim_text,
            'culture_name' => $claim->culture_name,
            'provenance_notes' => $claim->provenance_notes,
            'evidence_records' => $evidenceRecords,
            'evidence_audit_events' => $auditEvents,
            'created_at' => $claim->created_at?->toIso8601String(),
        ]);
    }

    /**
     * Attach or verify an evidence record linking an event source to a cultural claim.
     */
    public function attachEvidence(
        string $id,
        AttachClaimEvidenceRequest $request
    ): JsonResponse {
        $user = $request->user();

        if (! $user || (! $user->isCurator() && ! $user->isSystemAdmin())) {
            return ApiResponse::error(
                code: 'FORBIDDEN_CURATOR_ACCESS',
                message: 'Привязка и верификация первоисточников разрешена только кураторам каталога и системным администраторам.',
                details: [],
                status: 403
            );
        }

        $claim = CulturalClaim::with('event')->find($id);
        if (! $claim) {
            return ApiResponse::error(
                code: 'CULTURAL_CLAIM_NOT_FOUND',
                message: 'Культурное утверждение не найдено.',
                details: [],
                status: 404
            );
        }

        $validated = $request->validated();
        $source = EventSource::with('reviews')->find($validated['event_source_id']);
        if (! $source) {
            return ApiResponse::error(
                code: 'EVENT_SOURCE_NOT_FOUND',
                message: 'Первоисточник не найден.',
                details: [],
                status: 404
            );
        }

        // Cross-event injection check
        if ($claim->calendar_event_id !== $source->calendar_event_id) {
            return ApiResponse::error(
                code: 'CROSS_EVENT_BINDING_FORBIDDEN',
                message: 'Источник и утверждение принадлежат разным календарным событиям.',
                details: [],
                status: 422
            );
        }

        // Regional curator scoping check
        if ($user->isCurator() && $user->curator_scope === 'regional') {
            $event = $claim->event;
            $allowedRegions = $user->curated_region_codes ?? [];
            if (! $event || $event->event_type === 'federal' || $event->region_code === null || ! in_array($event->region_code, $allowedRegions, true)) {
                return ApiResponse::error(
                    code: 'FORBIDDEN_REGIONAL_SCOPE',
                    message: 'Региональный куратор не имеет полномочий работать с утверждениями для федеральных событий или событий других регионов.',
                    details: [],
                    status: 403
                );
            }
        }

        $record = DB::transaction(function () use ($validated, $claim, $source, $user) {
            // Every curator mutation for this source uses the same first lock.
            $source = EventSource::whereKey($source->id)->lockForUpdate()->firstOrFail();
            $existingRecord = CulturalClaimSource::where('cultural_claim_id', $claim->id)
                ->where('event_source_id', $source->id)->orderByDesc('created_at')->orderByDesc('id')->lockForUpdate()->first();
            $prevStatus = $existingRecord?->verification_status;
            $action = ! $existingRecord ? 'attached'
                : (($validated['verify'] ?? true) && ($existingRecord->is_detached || $prevStatus !== 'verified_authentic') ? 'reverified' : 'reattached');
            $shouldVerify = $validated['verify'] ?? true;
            if ($shouldVerify) {
                $record = $this->provenanceService->verifyClaim(
                    claim: $claim,
                    source: $source,
                    citationLocator: $validated['citation_locator'],
                    verifier: $user,
                    evidenceExcerpt: $validated['citation_excerpt'],
                    academicYear: $validated['applicable_academic_year'] ?? null,
                    notes: $validated['notes'] ?? null
                );
                $record->update([
                    'is_detached' => false,
                    'detached_at' => null,
                    'detached_by_user_id' => null,
                    'detachment_reason' => null,
                ]);
            } else {
                $record = CulturalClaimSource::updateOrCreate(
                    [
                        'cultural_claim_id' => $claim->id,
                        'event_source_id' => $source->id,
                        'citation_locator' => trim($validated['citation_locator']),
                    ],
                    [
                        'calendar_event_id' => $claim->calendar_event_id,
                        'citation_excerpt' => trim($validated['citation_excerpt']),
                        'verification_status' => 'unverified',
                        'verified_by_user_id' => null,
                        'verified_at' => null,
                        'applicable_academic_year' => $validated['applicable_academic_year'] ?? null,
                        'notes' => $validated['notes'] ?? null,
                        'is_detached' => false,
                        'detached_at' => null,
                        'detached_by_user_id' => null,
                        'detachment_reason' => null,
                    ]
                );
            }

            // Record immutable audit event for evidence attachment / reattachment / reverification
            CulturalClaimEvidenceAuditEvent::create([
                'cultural_claim_source_id' => $record->id,
                'cultural_claim_id' => $claim->id,
                'event_source_id' => $source->id,
                'action' => $action,
                'actor_user_id' => $user->id,
                'previous_verification_status' => $prevStatus,
                'new_verification_status' => $record->verification_status,
                'citation_locator' => $record->citation_locator,
                'citation_excerpt' => $record->citation_excerpt,
                'reason' => $validated['notes'] ?? ($action === 'reattached' ? 'Повторная привязка первоисточника' : null),
                'created_at' => now(),
            ]);

            return $record;
        });

        $record->loadMissing(['source', 'verifiedBy']);

        return response()->json([
            'data' => [
                'id' => $record->id,
                'cultural_claim_id' => $record->cultural_claim_id,
                'event_source_id' => $record->event_source_id,
                'source' => $record->source ? [
                    'id' => $record->source->id,
                    'title' => $record->source->title,
                    'author' => $record->source->author,
                    'source_type' => $record->source->source_type,
                ] : null,
                'citation_locator' => $record->citation_locator,
                'citation_excerpt' => $record->citation_excerpt,
                'verification_status' => $record->verification_status,
                'effective_status' => $this->provenanceService->evaluateStatus($record),
                'verified_by' => $record->verifiedBy ? [
                    'id' => $record->verifiedBy->id,
                    'name' => $record->verifiedBy->name,
                ] : null,
                'verified_at' => $record->verified_at?->toIso8601String(),
                'applicable_academic_year' => $record->applicable_academic_year,
                'notes' => $record->notes,
            ],
        ], 201);
    }

    /**
     * Detach an evidence record between cultural claim and event source.
     */
    public function detachEvidence(
        string $id,
        string $sourceId,
        Request $request
    ): JsonResponse {
        $user = $request->user();

        if (! $user || (! $user->isCurator() && ! $user->isSystemAdmin())) {
            return ApiResponse::error(
                code: 'FORBIDDEN_CURATOR_ACCESS',
                message: 'Удаление свидетельств первоисточников разрешено только кураторам каталога и системным администраторам.',
                details: [],
                status: 403
            );
        }

        $claim = CulturalClaim::with('event')->find($id);
        if (! $claim) {
            return ApiResponse::error(
                code: 'CULTURAL_CLAIM_NOT_FOUND',
                message: 'Культурное утверждение не найдено.',
                details: [],
                status: 404
            );
        }

        // Regional curator scoping check
        if ($user->isCurator() && $user->curator_scope === 'regional') {
            $event = $claim->event;
            $allowedRegions = $user->curated_region_codes ?? [];
            if (! $event || $event->event_type === 'federal' || $event->region_code === null || ! in_array($event->region_code, $allowedRegions, true)) {
                return ApiResponse::error(
                    code: 'FORBIDDEN_REGIONAL_SCOPE',
                    message: 'Региональный куратор не имеет полномочий удалять свидетельства для федеральных событий или событий других регионов.',
                    details: [],
                    status: 403
                );
            }
        }

        $records = CulturalClaimSource::where('cultural_claim_id', $claim->id)
            ->where('event_source_id', $sourceId)
            ->where('is_detached', false)
            ->get();

        if ($records->isEmpty()) {
            return ApiResponse::error(
                code: 'EVIDENCE_RECORD_NOT_FOUND',
                message: 'Активная запись свидетельства между данным утверждением и первоисточником не найдена.',
                details: [],
                status: 404
            );
        }

        $reason = (string) ($request->input('reason') ?? 'Откреплено куратором каталога');

        DB::transaction(function () use (&$records, $user, $reason, $claim, $sourceId) {
            EventSource::whereKey($sourceId)->lockForUpdate()->firstOrFail();
            $records = CulturalClaimSource::where('cultural_claim_id', $claim->id)->where('event_source_id', $sourceId)
                ->where('is_detached', false)->lockForUpdate()->get();
            if ($records->isEmpty()) {
                throw new HttpResponseException(ApiResponse::error(
                    code: 'EVIDENCE_RECORD_NOT_FOUND', message: 'Активное доказательство уже отсутствует.', details: [], status: 404
                ));
            }
            foreach ($records as $record) {
                $prevStatus = $record->verification_status;
                $record->update([
                    'is_detached' => true,
                    'detached_at' => now(),
                    'detached_by_user_id' => $user->id,
                    'detachment_reason' => $reason,
                    'previous_verification_status' => $prevStatus,
                    'verification_status' => 'unverified',
                    'verified_by_user_id' => null,
                    'verified_at' => null,
                ]);

                // Create immutable append-only audit event for detachment
                CulturalClaimEvidenceAuditEvent::create([
                    'cultural_claim_source_id' => $record->id,
                    'cultural_claim_id' => $claim->id,
                    'event_source_id' => $sourceId,
                    'action' => 'detached',
                    'actor_user_id' => $user->id,
                    'previous_verification_status' => $prevStatus,
                    'new_verification_status' => 'unverified',
                    'citation_locator' => $record->citation_locator,
                    'citation_excerpt' => $record->citation_excerpt,
                    'reason' => $reason,
                    'created_at' => now(),
                ]);
            }
        });

        return ApiResponse::success([
            'message' => 'Свидетельство первоисточника успешно отвязано от утверждения.',
            'detached_records_count' => $records->count(),
        ]);
    }
}
