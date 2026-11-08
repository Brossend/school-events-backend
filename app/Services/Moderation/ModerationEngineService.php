<?php

declare(strict_types=1);

namespace App\Services\Moderation;

use App\Models\CulturalClaim;
use App\Models\RevisionModerationState;
use App\Models\ScenarioRevision;
use App\Models\VerificationFinding;
use App\Models\VerificationRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ModerationEngineService
{
    public const RULE_ENGINE_VERSION = '1.0.0';

    /**
     * Prohibited stereotypes and harmful ethnic generalizations.
     *
     * @var list<string>
     */
    protected array $prohibitedStereotypePatterns = [
        'все представители этой национальности',
        'все русские обязательно',
        'все татары обязательно',
        'все башкиры обязательно',
        'типичный представитель нации всегда',
        'все цыгане склонны',
        'все кавказцы обязательно',
        'генетическая предрасположенность народа к',
        'врожденная лень этого этноса',
        'примитивная культура этих народов',
        'дикие обычаи племен',
        'неполноценные традиции',
        'отсталый народ',
    ];

    /**
     * Phrases that falsely claim unverified culture is definitive fact.
     *
     * @var list<string>
     */
    protected array $fabricationClaimPatterns = [
        'абсолютно доказано',
        'неоспоримый факт',
        'официально гарантированная подлинность',
        'научно доказано что этот миф чистая правда',
        'подтверждено академией наук как стопроцентная истина',
    ];

    /**
     * Execute full automated verification for a given scenario revision.
     */
    public function verifyRevision(ScenarioRevision $revision, ?string $runId = null): VerificationRun
    {
        $startTime = microtime(true);

        if ($runId) {
            $run = VerificationRun::findOrFail($runId);
            // If already completed, safely return without re-running or duplicating findings
            if (in_array($run->status, ['passed', 'blocked', 'passed_with_warnings'], true)) {
                Log::info("Verification run {$run->id} is already completed ({$run->status}). Skipping re-execution.");
                return $run;
            }
            if ($run->status !== 'running') {
                DB::table('verification_runs')->where('id', $run->id)->update(['status' => 'running']);
                $run->status = 'running';
            }
        } else {
            // Check if there is already a running verification run for this revision
            $existingRun = VerificationRun::where('revision_id', $revision->id)
                ->where('status', 'running')
                ->first();
            if ($existingRun) {
                return $existingRun;
            }
            // Create running verification run
            $run = VerificationRun::create([
                'revision_id' => $revision->id,
                'rule_engine_version' => self::RULE_ENGINE_VERSION,
                'status' => 'running',
                'p0_violations_count' => 0,
                'p1_violations_count' => 0,
                'execution_time_ms' => null,
                'created_at' => now(),
            ]);
        }

        // Update moderation state to running
        $revision->moderationState()->updateOrCreate(
            ['revision_id' => $revision->id],
            [
                'status' => 'running',
                'latest_verification_run_id' => $run->id,
                'updated_at' => now(),
            ]
        );

        $findings = [];

        try {
            // A. Check Cultural Claims & Provenance Binding (P0 / P1)
            $this->checkCulturalClaims($revision, $findings);

            // B. Check Prohibited Stereotypes (P0)
            $this->checkStereotypes($revision, $findings);

            // C. Check Timing Mismatch (P1)
            $this->checkTimingMismatch($revision, $findings);

            // D. Check Pedagogical Workload (P1)
            $this->checkPedagogicalWorkload($revision, $findings);

            // E. Check Recommendations Completeness (P1)
            $this->checkRecommendations($revision, $findings);

            // Persist findings
            $p0Count = 0;
            $p1Count = 0;

            // Clear any previous findings for this run if re-running
            DB::table('verification_findings')->where('verification_run_id', $run->id)->delete();

            foreach ($findings as $item) {
                if ($item['severity'] === 'P0') {
                    $p0Count++;
                } else {
                    $p1Count++;
                }

                VerificationFinding::create([
                    'verification_run_id' => $run->id,
                    'severity' => $item['severity'],
                    'rule_code' => $item['rule_code'],
                    'target_path' => $item['target_path'],
                    'message' => $item['message'],
                    'suggested_fix' => $item['suggested_fix'] ?? null,
                    'status' => 'open',
                    'created_at' => now(),
                ]);
            }

            $executionTimeMs = (int) round((microtime(true) - $startTime) * 1000);

            // Compute run status
            $runStatus = 'passed';
            $moderationStatus = 'verification_passed';

            if ($p0Count > 0) {
                $runStatus = 'blocked';
                $moderationStatus = 'verification_blocked';
            } elseif ($p1Count > 0) {
                $runStatus = 'passed_with_warnings';
                $moderationStatus = 'verification_passed_with_warnings';
            }

            // Update run record directly in DB (model has immutable guard on Eloquent update)
            DB::table('verification_runs')
                ->where('id', $run->id)
                ->update([
                    'status' => $runStatus,
                    'p0_violations_count' => $p0Count,
                    'p1_violations_count' => $p1Count,
                    'execution_time_ms' => $executionTimeMs,
                ]);

            $currentFingerprint = app(\App\Services\ProvenanceVerificationService::class)->computeRevisionProvenanceFingerprint($revision);

            // Update revision moderation state
            $revision->moderationState()->updateOrCreate(
                ['revision_id' => $revision->id],
                [
                    'status' => $moderationStatus,
                    'provenance_fingerprint' => $currentFingerprint,
                    'p0_violations_count' => $p0Count,
                    'p1_violations_count' => $p1Count,
                    'latest_verification_run_id' => $run->id,
                    'updated_at' => now(),
                ]
            );

            $run->status = $runStatus;
            $run->p0_violations_count = $p0Count;
            $run->p1_violations_count = $p1Count;
            $run->execution_time_ms = $executionTimeMs;

            return $run;
        } catch (\Throwable $e) {
            Log::error('Moderation engine failure: '.$e->getMessage(), [
                'revision_id' => $revision->id,
                'run_id' => $run->id,
                'exception' => $e,
            ]);

            DB::table('verification_runs')
                ->where('id', $run->id)
                ->update([
                    'status' => 'failed_engine',
                    'execution_time_ms' => (int) round((microtime(true) - $startTime) * 1000),
                ]);

            $revision->moderationState()->updateOrCreate(
                ['revision_id' => $revision->id],
                [
                    'status' => 'failed_engine',
                    'latest_verification_run_id' => $run->id,
                    'updated_at' => now(),
                ]
            );

            $run->status = 'failed_engine';

            throw $e;
        }
    }

    /**
     * Check cultural claims and provenance integrity.
     */
    protected function checkCulturalClaims(ScenarioRevision $revision, array &$findings): void
    {
        $payload = $revision->content_payload ?? [];
        $claims = [];
        if (! empty($payload['cultural_claims']) && is_array($payload['cultural_claims'])) {
            $claims = array_merge($claims, $payload['cultural_claims']);
        }
        if (! empty($payload['cultural_context']['verified_claims']) && is_array($payload['cultural_context']['verified_claims'])) {
            $claims = array_merge($claims, $payload['cultural_context']['verified_claims']);
        }
        if (! empty($payload['cultural_context']['cultural_claims']) && is_array($payload['cultural_context']['cultural_claims'])) {
            $claims = array_merge($claims, $payload['cultural_context']['cultural_claims']);
        }

        if (empty($claims)) {
            return;
        }

        $provenanceService = app(\App\Services\ProvenanceVerificationService::class);

        foreach ($claims as $index => $claim) {
            if (! is_array($claim)) {
                continue;
            }

            $claimStatus = (string) ($claim['server_verification_status'] ?? $claim['status'] ?? $claim['verification_status'] ?? 'unverified');
            $claimText = trim((string) ($claim['claim_text'] ?? ($claim['text'] ?? '')));
            $claimId = $claim['claim_id'] ?? null;
            $targetPath = "cultural_claims[{$index}]";

            // P0 Rule 1: Attempt to present unverified claim as verified_authentic
            if ($claimStatus === 'verified_authentic') {
                if (! $claimId) {
                    $findings[] = [
                        'severity' => 'P0',
                        'rule_code' => 'P0_FABRICATED_CULTURE',
                        'target_path' => $targetPath,
                        'message' => 'Культурное утверждение помечено как verified_authentic, но отсутствует идентификатор в реестре первоисточников (claim_id).',
                        'suggested_fix' => 'Привяжите утверждение к подтверждённому источнику через реестр или укажите статус unverified.',
                    ];
                    continue;
                }

                $cleanClaimId = (string) $claimId;
                $claimUuid = $provenanceService->resolveClaimUuid($cleanClaimId);

                $dbClaim = $claimUuid
                    ? CulturalClaim::with(['evidenceRecords.source.reviews.reviewer', 'evidenceRecords.verifiedBy', 'event'])->find($claimUuid)
                    : null;

                // Do NOT fall back to text lookup if ID is unknown/unmatched.
                // verified_authentic strictly requires a valid identified claim in the registry.
                if (! $dbClaim) {
                    $findings[] = [
                        'severity' => 'P0',
                        'rule_code' => 'P0_FABRICATED_CULTURE',
                        'target_path' => $targetPath,
                        'message' => "Утверждение ссылается на несуществующий в реестре claim_id: {$claimId}.",
                        'suggested_fix' => 'Укажите действительный идентификатор культурного утверждения.',
                    ];
                    continue;
                }

                // Check calendar event context (claim must belong to the scenario event if event-based)
                $scenarioEventId = $revision->scenario?->scenarioRequest?->calendar_event_id;
                if ($scenarioEventId && $dbClaim->calendar_event_id !== $scenarioEventId) {
                    $findings[] = [
                        'severity' => 'P0',
                        'rule_code' => 'P0_FABRICATED_CULTURE',
                        'target_path' => $targetPath,
                        'message' => 'Культурное утверждение принадлежит другому календарному событию.',
                        'suggested_fix' => 'Используйте утверждения, относящиеся к текущему календарному событию.',
                    ];
                    continue;
                }

                // Canonical text matching
                if (mb_strtolower(trim($dbClaim->claim_text)) !== mb_strtolower($claimText)) {
                    $findings[] = [
                        'severity' => 'P0',
                        'rule_code' => 'P0_FABRICATED_CULTURE',
                        'target_path' => "{$targetPath}.claim_text",
                        'message' => 'Текст утверждения был изменён после серверной аттестации подлинности.',
                        'suggested_fix' => 'Восстановите исходный канонический текст утверждения или смените статус на unverified.',
                    ];
                    continue;
                }

                // Read and check evidence_records: must not be empty and each cited source must be valid
                $citedEvidence = $claim['evidence_records'] ?? null;
                if (! is_array($citedEvidence) || empty($citedEvidence)) {
                    $findings[] = [
                        'severity' => 'P0',
                        'rule_code' => 'P0_FABRICATED_CULTURE',
                        'target_path' => "{$targetPath}.evidence_records",
                        'message' => 'Культурное утверждение помечено как verified_authentic, но отсутствует список подтверждающих первоисточников (evidence_records).',
                        'suggested_fix' => 'Привяжите подтверждённый первоисточник или измените статус утверждения на unverified.',
                    ];
                    continue;
                }

                foreach ($citedEvidence as $evIndex => $ev) {
                    $evPath = "{$targetPath}.evidence_records[{$evIndex}]";
                    if (! is_array($ev)) {
                        $findings[] = [
                            'severity' => 'P0',
                            'rule_code' => 'P0_FABRICATED_CULTURE',
                            'target_path' => $evPath,
                            'message' => 'Запись evidence_records имеет некорректный формат.',
                            'suggested_fix' => 'Укажите корректный объект первоисточника.',
                        ];
                        continue;
                    }

                    $sourceId = (string) ($ev['event_source_id'] ?? '');
                    if (! \Illuminate\Support\Str::isUuid($sourceId)) {
                        $findings[] = [
                            'severity' => 'P0',
                            'rule_code' => 'P0_FABRICATED_CULTURE',
                            'target_path' => "{$evPath}.event_source_id",
                            'message' => "Указан некорректный идентификатор первоисточника (event_source_id): '{$sourceId}'.",
                            'suggested_fix' => 'Укажите действительный UUID первоисточника.',
                        ];
                        continue;
                    }

                    $claimSource = $dbClaim->evidenceRecords->first(function ($cs) use ($sourceId) {
                        return (string) $cs->event_source_id === $sourceId;
                    });

                    if (! $claimSource) {
                        $findings[] = [
                            'severity' => 'P0',
                            'rule_code' => 'P0_FABRICATED_CULTURE',
                            'target_path' => "{$evPath}.event_source_id",
                            'message' => "Первоисточник {$sourceId} не привязан к утверждению {$claimId} в реестре первоисточников.",
                            'suggested_fix' => 'Используйте только первоисточники, привязанные к данному утверждению.',
                        ];
                        continue;
                    }

                    if ($claimSource->calendar_event_id !== $dbClaim->calendar_event_id) {
                        $findings[] = [
                            'severity' => 'P0',
                            'rule_code' => 'P0_FABRICATED_CULTURE',
                            'target_path' => "{$evPath}.event_source_id",
                            'message' => "Первоисточник {$sourceId} привязан к другому календарному событию.",
                            'suggested_fix' => 'Используйте первоисточник текущего календарного события.',
                        ];
                        continue;
                    }

                    $citedLocator = trim((string) ($ev['citation_locator'] ?? ''));
                    $attestedLocator = trim((string) $claimSource->citation_locator);
                    if ($citedLocator === '' || mb_strtolower($citedLocator) !== mb_strtolower($attestedLocator)) {
                        $findings[] = [
                            'severity' => 'P0',
                            'rule_code' => 'P0_FABRICATED_CULTURE',
                            'target_path' => "{$evPath}.citation_locator",
                            'message' => "Локатор цитаты '{$citedLocator}' не соответствует серверной аттестации первоисточника ('{$attestedLocator}').",
                            'suggested_fix' => 'Укажите точный аттестованный локатор цитаты первоисточника.',
                        ];
                        continue;
                    }

                    $effectiveStatus = $provenanceService->evaluateStatus($claimSource);
                    if ($effectiveStatus !== 'verified_authentic') {
                        $findings[] = [
                            'severity' => 'P0',
                            'rule_code' => 'P0_FABRICATED_CULTURE',
                            'target_path' => $evPath,
                            'message' => "Первоисточник {$sourceId} не подтверждён (статус: '{$effectiveStatus}'). Рецензия отозвана или отклонена.",
                            'suggested_fix' => 'Оставьте утверждение со статусом unverified до завершения или восстановления экспертизы первоисточника.',
                        ];
                        continue;
                    }
                }
            } elseif ($claimStatus === 'unverified') {
                // If claim is honestly unverified, do NOT flag as P0 unless text aggressively asserts false certainty
                foreach ($this->fabricationClaimPatterns as $pattern) {
                    if (mb_stripos($claimText, $pattern) !== false) {
                        $findings[] = [
                            'severity' => 'P0',
                            'rule_code' => 'P0_FABRICATED_CULTURE',
                            'target_path' => "{$targetPath}.claim_text",
                            'message' => "Неподтверждённый культурный факт содержит формулировку безусловной истинности ('{$pattern}').",
                            'suggested_fix' => 'Сформулируйте утверждение как культурную традицию или гипотезу, требующую проверки.',
                        ];
                        break;
                    }
                }
            } elseif ($claimStatus === 'rejected_fake') {
                $findings[] = [
                    'severity' => 'P0',
                    'rule_code' => 'P0_FABRICATED_CULTURE',
                    'target_path' => $targetPath,
                    'message' => 'Использование опровергнутого или фальсифицированного культурного утверждения (rejected_fake) запрещено.',
                    'suggested_fix' => 'Удалите данное утверждение из сценария.',
                ];
            }
        }
    }

    /**
     * Check prohibited stereotypes across the entire scenario payload.
     *
     * Note: Automated keyword matching serves as a limited P0 guardrail.
     * Complex ethnocultural and pedagogical issues require human expert review.
     */
    protected function checkStereotypes(ScenarioRevision $revision, array &$findings): void
    {
        $payload = $revision->content_payload ?? [];
        $textsToCheck = [];

        $this->extractSubstantiveTexts($payload, '', $textsToCheck);

        foreach ($textsToCheck as $path => $text) {
            if ($text === '') {
                continue;
            }

            foreach ($this->prohibitedStereotypePatterns as $pattern) {
                if (mb_stripos($text, $pattern) !== false) {
                    $findings[] = [
                        'severity' => 'P0',
                        'rule_code' => 'P0_STEREOTYPE',
                        'target_path' => $path,
                        'message' => "Обнаружено недопустимое этническое или культурное обобщение/стереотип: '{$pattern}'.",
                        'suggested_fix' => 'Переформулируйте формулировку с уважением к этнокультурному разнообразию без категоричных обобщений.',
                    ];
                    break;
                }
            }
        }
    }

    /**
     * Recursively extract all substantive text values along with their exact dot/bracket path.
     *
     * @param mixed $node Current JSON AST node
     * @param string $currentPath JSON path in dot/bracket notation
     * @param array<string, string> $result Accumulated path => text map
     */
    protected function extractSubstantiveTexts(mixed $node, string $currentPath, array &$result): void
    {
        if (is_string($node)) {
            $trimmed = trim($node);
            if ($trimmed !== '') {
                $result[$currentPath] = $trimmed;
            }
            return;
        }

        if (! is_array($node)) {
            return;
        }

        $isList = array_is_list($node);
        $technicalKeys = [
            'id', 'claim_id', 'stage_type', 'format_type', 'scale', 'timeline_phase',
            'card_type', 'target_stage_id', 'order_index', 'slide_number', 'duration_minutes',
            'group_size', 'participants_count_min', 'participants_count_max',
            'total_duration_minutes', 'quantity_per_group', 'is_mandatory',
            'server_verification_status', 'verification_status', 'verified_at',
            'schema_version', 'target_participants', 'value_direction', 'mechanism_type',
        ];

        foreach ($node as $key => $child) {
            if (! $isList && in_array((string) $key, $technicalKeys, true)) {
                continue;
            }

            $childPath = $currentPath === ''
                ? (string) $key
                : ($isList ? "{$currentPath}[{$key}]" : "{$currentPath}.{$key}");

            $this->extractSubstantiveTexts($child, $childPath, $result);
        }
    }

    /**
     * Check timing mismatch between stages sum and total duration.
     */
    protected function checkTimingMismatch(ScenarioRevision $revision, array &$findings): void
    {
        $payload = $revision->content_payload ?? [];
        $totalDuration = (int) ($payload['total_duration_minutes'] ?? $revision->total_duration_minutes ?? 0);
        $stages = $payload['stages'] ?? [];

        if (! is_array($stages) || empty($stages)) {
            return;
        }

        $stagesSum = 0;
        foreach ($stages as $stage) {
            $stagesSum += (int) ($stage['duration_minutes'] ?? 0);
        }

        if ($stagesSum !== $totalDuration) {
            $findings[] = [
                'severity' => 'P1',
                'rule_code' => 'P1_TIMING_MISMATCH',
                'target_path' => 'total_duration_minutes',
                'message' => "Суммарная длительность этапов ({$stagesSum} мин) не совпадает с заявленной продолжительностью мероприятия ({$totalDuration} мин).",
                'suggested_fix' => "Скорректируйте продолжительность этапов, чтобы их сумма равнялась {$totalDuration} мин, либо измените общую длительность.",
            ];
        }
    }

    /**
     * Check pedagogical workload.
     */
    protected function checkPedagogicalWorkload(ScenarioRevision $revision, array &$findings): void
    {
        $payload = $revision->content_payload ?? [];
        $stages = $payload['stages'] ?? [];

        if (! is_array($stages)) {
            return;
        }

        foreach ($stages as $i => $stage) {
            if (! is_array($stage)) {
                continue;
            }

            $duration = (int) ($stage['duration_minutes'] ?? 0);
            $stageType = (string) ($stage['stage_type'] ?? '');

            // Single lecture/monologue > 35 minutes or single stage > 45 minutes
            if ($duration > 40 || ($stageType === 'lecture' && $duration > 25)) {
                $findings[] = [
                    'severity' => 'P1',
                    'rule_code' => 'P1_PEDAGOGICAL_WORKLOAD',
                    'target_path' => "stages[{$i}].duration_minutes",
                    'message' => "Этап '{$stage['title']}' длительностью {$duration} мин может быть перегруженным для непрерывного детского внимания.",
                    'suggested_fix' => 'Разделите этап на динамические интерактивные блоки по 10-15 минут со сменой активности.',
                ];
            }
        }
    }

    /**
     * Check completeness of teacher instructions and recommendations.
     */
    protected function checkRecommendations(ScenarioRevision $revision, array &$findings): void
    {
        $payload = $revision->content_payload ?? [];
        $instructions = $payload['teacher_instructions'] ?? [];

        if (empty($instructions) || (is_array($instructions) && count($instructions) === 0)) {
            $findings[] = [
                'severity' => 'P1',
                'rule_code' => 'P1_INCOMPLETE_RECOMMENDATIONS',
                'target_path' => 'teacher_instructions',
                'message' => 'В сценарии отсутствуют подробные методические инструкции для педагога.',
                'suggested_fix' => 'Добавьте рекомендации по подготовке помещения, технике безопасности и вовлечению участников.',
            ];
        }
    }
}
