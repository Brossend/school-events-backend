<?php

declare(strict_types=1);

namespace App\Services\Llm;

use App\Models\CulturalClaim;
use App\Models\ScenarioRequest;
use App\Services\ProvenanceVerificationService;

class TrustedGenerationContext
{
    public function forRequest(ScenarioRequest $request): array
    {
        $context = $request->context_snapshot ?? [];
        $claims = [];
        foreach ($context['cultural_provenance']['claims'] ?? [] as $selected) {
            $live = CulturalClaim::with(['evidenceRecords.source.reviews', 'evidenceRecords.verifiedBy'])
                ->whereKey($selected['id'] ?? null)->where('calendar_event_id', $request->calendar_event_id)->first();
            if (! $live) {
                continue;
            }
            $sourceIds = array_column($selected['evidence_records'] ?? [], 'event_source_id');
            $evidence = [];
            foreach ($live->evidenceRecords as $record) {
                if (in_array($record->event_source_id, $sourceIds, true)
                    && app(ProvenanceVerificationService::class)->evaluateStatus($record) === 'verified_authentic') {
                    $evidence[] = ['event_source_id' => $record->event_source_id, 'source_title' => $record->source->title,
                        'citation_locator' => $record->citation_locator, 'citation_excerpt' => $record->citation_excerpt,
                        'verification_status' => 'verified_authentic', 'verified_at' => $record->verified_at?->toIso8601String(),
                        'verified_by' => $record->verifiedBy?->name, 'author' => $record->source->author, 'url' => $record->source->url];
                }
            }
            if ($evidence) {
                $claims[] = ['id' => $live->id, 'claim_text' => $live->claim_text, 'culture_name' => $live->culture_name,
                    'effective_status' => 'verified_authentic', 'evidence_records' => $evidence];
            }
        }
        $context['cultural_provenance']['claims'] = $claims;

        return $context;
    }
}
