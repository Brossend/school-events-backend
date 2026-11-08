<?php

declare(strict_types=1);

namespace App\Services\Llm;

use Illuminate\Validation\ValidationException;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Helper;
use Opis\JsonSchema\Validator;

class ServerNormalizationService
{
    protected Validator $validator;
    protected string $schemaId = 'https://school-events.sber-urfu.ru/schemas/v1/scenario.schema.json';

    public function __construct()
    {
        $this->validator = new Validator();
        $this->validator->resolver()->registerFile(
            $this->schemaId,
            base_path('docs/scenario.schema.json')
        );
    }

    /**
     * Canonicalize text for strict cultural claim comparison.
     */
    protected function canonicalizeText(string $text): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', trim($text)) ?? '';
        return mb_strtolower($collapsed, 'UTF-8');
    }

    /**
     * Normalize raw LLM output and enrich with server verification records.
     *
     * @param array $rawOutput Output from LLM that passed Contours 1 and 2
     * @param array $contextSnapshot Context snapshot from ScenarioRequest
     * @return array Canonical scenario payload conforming to docs/scenario.schema.json
     * @throws ValidationException
     */
    public function normalize(array $rawOutput, array $contextSnapshot): array
    {
        $normalizedClaims = [];
        $snapshotClaims = $contextSnapshot['cultural_provenance']['claims'] ?? [];

        foreach ($rawOutput['cultural_claims'] ?? [] as $rawClaim) {
            $rawClaimId = (string) ($rawClaim['claim_id'] ?? '');
            $rawClaimText = (string) ($rawClaim['claim_text'] ?? '');
            $rawCanonical = $this->canonicalizeText($rawClaimText);

            $matchedSnapshotClaim = null;

            foreach ($snapshotClaims as $sc) {
                $snapshotId = (string) ($sc['id'] ?? '');
                $snapshotFormattedId = 'claim_' . str_replace('-', '', $snapshotId);
                $snapshotHashId = 'claim_' . substr(md5((string) ($sc['claim_text'] ?? '')), 0, 10);

                $claimIdMatches = ($rawClaimId !== '' && (
                    $rawClaimId === $snapshotId ||
                    $rawClaimId === $snapshotFormattedId ||
                    $rawClaimId === $snapshotHashId
                ));
                $textMatches = ($rawCanonical !== '' && $rawCanonical === $this->canonicalizeText((string) ($sc['claim_text'] ?? '')));

                // P0 Invariant: Strict binding of claim ID + canonical text.
                // Logical OR is forbidden. If the model altered the text, or used a foreign ID,
                // the claim MUST NOT receive verified_authentic.
                if ($claimIdMatches && $textMatches) {
                    $matchedSnapshotClaim = $sc;
                    break;
                }
            }

            if ($matchedSnapshotClaim !== null) {
                $snapshotStatus = $matchedSnapshotClaim['effective_status'] ?? 'unverified';
                $evidenceRecords = [];

                if ($snapshotStatus === 'verified_authentic') {
                    // P0 Invariant: Evidence records are strictly taken from the server snapshot
                    // for this exact claim, never from LLM suggestions or other claims.
                    foreach ($matchedSnapshotClaim['evidence_records'] ?? [] as $ev) {
                        $isAuthentic = ($ev['verification_status'] ?? '') === 'verified_authentic';
                        $hasVerifiedAt = ! empty($ev['verified_at']);

                        if ($isAuthentic && $hasVerifiedAt) {
                            $rec = [
                                'event_source_id' => $ev['event_source_id'],
                                'source_title' => $ev['source_title'] ?? '',
                                'citation_locator' => $ev['citation_locator'] ?? '',
                                'verification_status' => 'verified_authentic',
                                'verified_at' => $ev['verified_at'],
                            ];
                            if (! empty($ev['verified_by'])) {
                                $rec['verified_by'] = $ev['verified_by'];
                            }
                            $evidenceRecords[] = $rec;
                        }
                    }
                }

                if ($snapshotStatus === 'verified_authentic' && ! empty($evidenceRecords)) {
                    $status = 'verified_authentic';
                } else {
                    $status = 'unverified';
                    $evidenceRecords = [];
                }
            } else {
                // Modified text, foreign ID, or hallucinated claim: strictly unverified with NO evidence records
                $status = 'unverified';
                $evidenceRecords = [];
            }

            // Ensure claim_id satisfies schema pattern ^claim_[a-z0-9_]+$
            $normalizedClaimId = $rawClaim['claim_id'] ?? '';
            if (! preg_match('/^claim_[a-z0-9_]+$/', $normalizedClaimId)) {
                if (! empty($matchedSnapshotClaim['id'])) {
                    $normalizedClaimId = 'claim_' . str_replace('-', '', (string) $matchedSnapshotClaim['id']);
                } elseif (\Illuminate\Support\Str::isUuid($normalizedClaimId)) {
                    $normalizedClaimId = 'claim_' . str_replace('-', '', $normalizedClaimId);
                } else {
                    $normalizedClaimId = 'claim_' . substr(md5($rawClaimText ?: 'claim'), 0, 10);
                }
            }

            $normalizedClaims[] = [
                'claim_id' => $normalizedClaimId,
                'claim_text' => $rawClaim['claim_text'],
                'culture_name' => $rawClaim['culture_name'],
                'claimed_origin' => $rawClaim['claimed_origin'] ?? 'Культурно-историческая традиция региона',
                'claimed_meaning' => $rawClaim['claimed_meaning'] ?? 'Ценностный ориентир преемственности поколений',
                'server_verification_status' => $status,
                'evidence_records' => $evidenceRecords,
            ];
        }

        $normalized = [
            'concept' => $rawOutput['concept'],
            'pedagogical_goal' => $rawOutput['pedagogical_goal'],
            'value_direction' => $rawOutput['value_direction'],
            'pedagogical_mechanism' => [
                'mechanism_type' => $rawOutput['pedagogical_mechanism']['mechanism_type'],
                'description' => $rawOutput['pedagogical_mechanism']['description'],
                'target_activity_title' => $rawOutput['pedagogical_mechanism']['target_activity_title'],
            ],
            'pedagogical_tasks' => [
                'personal' => $rawOutput['pedagogical_tasks']['personal'],
                'meta_subject' => $rawOutput['pedagogical_tasks']['meta_subject'],
                'subject_specific' => $rawOutput['pedagogical_tasks']['subject_specific'],
            ],
            'expected_outcomes' => [
                'knowledge' => $rawOutput['expected_outcomes']['knowledge'],
                'skills' => $rawOutput['expected_outcomes']['skills'],
                'values' => $rawOutput['expected_outcomes']['values'],
            ],
            'target_audience' => [
                'age_group' => $rawOutput['target_audience']['age_group'],
                'target_grades' => array_values($rawOutput['target_audience']['target_grades']),
                'participants_count_min' => (int) $rawOutput['target_audience']['participants_count_min'],
                'participants_count_max' => (int) $rawOutput['target_audience']['participants_count_max'],
                'family_involvement' => (bool) $rawOutput['target_audience']['family_involvement'],
            ],
            'format' => [
                'format_type' => $rawOutput['format']['format_type'],
                'scale' => $rawOutput['format']['scale'],
                'total_duration_minutes' => (int) $rawOutput['format']['total_duration_minutes'],
            ],
            'stages' => array_values($rawOutput['stages']),
            'roles' => array_values($rawOutput['roles']),
            'activities' => array_values($rawOutput['activities']),
            'materials' => [
                'presentation_slides' => array_values($rawOutput['materials']['presentation_slides'] ?? []),
                'printable_cards' => array_values($rawOutput['materials']['printable_cards'] ?? []),
                'handouts' => array_values($rawOutput['materials']['handouts'] ?? []),
            ],
            'props' => array_values($rawOutput['props']),
            'preparation_checklist' => array_values($rawOutput['preparation_checklist']),
            'teacher_instructions' => [
                'general_safety_rules' => array_values($rawOutput['teacher_instructions']['general_safety_rules']),
                'methodological_recommendations' => array_values($rawOutput['teacher_instructions']['methodological_recommendations']),
                'adaptation_for_disabilities_or_small_groups' => $rawOutput['teacher_instructions']['adaptation_for_disabilities_or_small_groups'],
            ],
            'cultural_claims' => $normalizedClaims,
        ];

        // Validate normalized payload against docs/scenario.schema.json
        $dataObject = Helper::toJSON($normalized);
        $result = $this->validator->validate($dataObject, $this->schemaId);

        if (! $result->isValid()) {
            $error = $result->error();
            $formatter = new ErrorFormatter();
            $formatted = $formatter->format($error);

            throw ValidationException::withMessages([
                'normalized_scenario_schema' => 'Normalized scenario does not conform to docs/scenario.schema.json: ' . json_encode($formatted, JSON_UNESCAPED_UNICODE),
            ]);
        }

        return $normalized;
    }
}
