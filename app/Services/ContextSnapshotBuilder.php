<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CalendarEvent;
use App\Models\School;
use App\Models\TraditionVersion;
use App\Models\User;

class ContextSnapshotBuilder
{
    public function __construct(
        protected ProvenanceVerificationService $provenanceService
    ) {}

    /**
     * Build an immutable snapshot from validated data resolving models automatically.
     */
    public function buildSnapshot(
        User $user,
        School $school,
        array $validatedData
    ): array {
        $event = null;
        if (! empty($validatedData['calendar_event_id'])) {
            $event = CalendarEvent::visibleTo($user, $school)->find($validatedData['calendar_event_id']);
            if (! $event || $event->status !== 'approved') {
                throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException(
                    'Событие календаря недоступно или не утверждено для использования в текущей школе.'
                );
            }
        }

        $traditionVersion = null;
        if (! empty($validatedData['tradition_version_id'])) {
            $traditionVersion = TraditionVersion::with('tradition')->find($validatedData['tradition_version_id']);
            if (! $traditionVersion || ! $traditionVersion->tradition || ($traditionVersion->tradition->school_id !== $school->id && ! $user->isSystemAdmin())) {
                throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException(
                    'Версия школьной традиции принадлежит другой образовательной организации.'
                );
            }
        }

        return $this->build($school, $user, $validatedData, $event, $traditionVersion);
    }

    /**
     * Build an immutable, deterministic context snapshot for scenario generation.
     */
    public function build(
        School $school,
        User $user,
        array $validatedData,
        ?CalendarEvent $event = null,
        ?TraditionVersion $traditionVersion = null
    ): array {
        $occasionType = 'custom_title';
        $occasionTitle = $validatedData['custom_event_title'] ?? '';

        if ($event) {
            $occasionType = 'calendar_event';
            $occasionTitle = $event->title;
        } elseif ($traditionVersion) {
            $occasionType = 'school_tradition';
            $occasionTitle = $traditionVersion->tradition?->title ?? 'Школьная традиция';
        }

        $sourcesList = [];
        $claimsList = [];

        if ($event) {
            $event->loadMissing(['sources', 'culturalClaims.evidenceRecords.source', 'culturalClaims.evidenceRecords.verifiedBy']);

            foreach ($event->sources as $source) {
                $sourcesList[] = [
                    'id' => $source->id,
                    'title' => $source->title,
                    'source_type' => $source->source_type,
                    'author' => $source->author,
                    'url' => $source->url,
                ];
            }

            foreach ($event->culturalClaims as $claim) {
                $evidenceRecords = [];
                $hasVerifiedAuthentic = false;

                foreach ($claim->evidenceRecords as $rec) {
                    $effectiveStatus = $this->provenanceService->evaluateStatus($rec);
                    if ($effectiveStatus === 'verified_authentic') {
                        $hasVerifiedAuthentic = true;
                    }

                    $evidenceRecords[] = [
                        'event_source_id' => $rec->event_source_id,
                        'source_title' => $rec->source?->title ?? '',
                        'citation_locator' => $rec->citation_locator,
                        'citation_excerpt' => $rec->citation_excerpt,
                        'verification_status' => $effectiveStatus,
                        'verified_at' => $rec->verified_at?->toIso8601String(),
                        'verified_by' => $rec->verifiedBy?->name ?? null,
                        'author' => $rec->source?->author ?? null,
                        'url' => $rec->source?->url ?? null,
                    ];
                }

                $claimsList[] = [
                    'id' => $claim->id,
                    'claim_text' => $claim->claim_text,
                    'culture_name' => $claim->culture_name,
                    'provenance_notes' => $claim->provenance_notes,
                    'effective_status' => $hasVerifiedAuthentic ? 'verified_authentic' : 'unverified',
                    'evidence_records' => $evidenceRecords,
                ];
            }
        }

        return [
            'schema_version' => '1.0.0',
            'snapshot_timestamp' => now()->toIso8601String(),
            'author' => [
                'user_id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
            'school' => [
                'id' => $school->id,
                'name' => $school->name,
                'region_code' => $school->region_code,
            ],
            'occasion' => [
                'type' => $occasionType,
                'title' => $occasionTitle,
                'calendar_event' => $event ? [
                    'id' => $event->id,
                    'title' => $event->title,
                    'event_type' => $event->event_type,
                    'region_code' => $event->region_code,
                    'description' => $event->description,
                ] : null,
                'tradition_version' => $traditionVersion ? [
                    'id' => $traditionVersion->id,
                    'tradition_id' => $traditionVersion->tradition_id,
                    'tradition_title' => $traditionVersion->tradition?->title,
                    'academic_year' => $traditionVersion->academic_year,
                    'parameters_snapshot' => $traditionVersion->parameters_snapshot,
                ] : null,
                'custom_event_title' => $validatedData['custom_event_title'] ?? null,
                'custom_region_code' => $validatedData['region_code'] ?? $school->region_code,
            ],
            'pedagogical_parameters' => [
                'scale' => $validatedData['scale'],
                'format_type' => $validatedData['format_type'],
                'target_grades' => array_values($validatedData['target_grades']),
                'participants_count' => (int) $validatedData['participants_count'],
                'duration_minutes' => (int) $validatedData['duration_minutes'],
                'value_direction' => $validatedData['value_direction'],
                'family_involvement' => (bool) $validatedData['family_involvement'],
                'available_resources' => array_values($validatedData['available_resources'] ?? []),
                'pedagogical_constraints' => $validatedData['pedagogical_constraints'] ?? null,
            ],
            'cultural_provenance' => [
                'sources' => $sourcesList,
                'claims' => $claimsList,
            ],
        ];
    }
}
