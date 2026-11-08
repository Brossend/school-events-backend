<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PilotParticipant;
use App\Models\PilotResponse;
use App\Models\PilotReviewFinding;
use App\Models\Scenario;
use App\Models\ScenarioComparison;
use App\Models\School;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class PilotService
{
    /**
     * Create participant invitation record. Returns plain token only once.
     */
    public function createParticipant(School $school, array $data): array
    {
        $plainToken = 'pilot_'.Str::random(36);
        $tokenHash = hash('sha256', $plainToken);

        $anonymousCode = $data['anonymous_code'] ?? null;
        if (empty($anonymousCode)) {
            $count = PilotParticipant::where('school_id', $school->id)->count() + 1;
            $anonymousCode = sprintf('TEACHER-%s-%03d', strtoupper(substr($school->region_code ?? 'RU', 0, 4)), $count);
        }

        // Verify uniqueness of code
        if (PilotParticipant::where('anonymous_code', $anonymousCode)->exists()) {
            $anonymousCode .= '-'.strtoupper(Str::random(4));
        }

        $validDays = (int) ($data['valid_days'] ?? $data['expires_days'] ?? 30);
        $expiresAt = now()->addDays($validDays);
        $isSynthetic = (bool) ($data['is_synthetic'] ?? false);

        $participant = PilotParticipant::create([
            'school_id' => $school->id,
            'anonymous_code' => $anonymousCode,
            'invite_token_hash' => $tokenHash,
            'subject_area' => $data['subject_area'],
            'teaching_experience_years' => (int) $data['teaching_experience_years'],
            'is_submitted' => false,
            'is_synthetic' => $isSynthetic,
            'expires_at' => $expiresAt,
            'revoked_at' => null,
            'used_at' => null,
        ]);

        return [
            'participant' => $participant,
            'invite_token' => $plainToken,
        ];
    }

    /**
     * Reissue token for an existing participant who has not submitted yet.
     */
    public function reissueToken(PilotParticipant $participant): array
    {
        if ($participant->is_submitted) {
            throw new ConflictHttpException('Участник уже отправил анкету, перевыпуск пригласительного токена невозможен.');
        }

        if ($participant->isRevoked()) {
            throw new ConflictHttpException('Приглашение участника отозвано. Перевыпуск невозможен без снятия отзыва.');
        }

        $plainToken = 'pilot_'.Str::random(36);
        $participant->invite_token_hash = hash('sha256', $plainToken);
        $participant->expires_at = now()->addDays(30);
        $participant->save();

        return [
            'participant' => $participant,
            'invite_token' => $plainToken,
        ];
    }

    /**
     * Revoke invitation token.
     */
    public function revokeParticipant(PilotParticipant $participant): PilotParticipant
    {
        if ($participant->is_submitted) {
            throw new ConflictHttpException('Участник уже отправил анкету, отзыв приглашения невозможен.');
        }

        $participant->revoked_at = now();
        $participant->save();

        return $participant;
    }

    /**
     * Submit response by participant invite token.
     */
    public function submitResponse(string $token, array $data): PilotResponse
    {
        $tokenHash = hash('sha256', trim($token));

        return DB::transaction(function () use ($tokenHash, $data) {
            /** @var PilotParticipant|null $participant */
            $participant = PilotParticipant::where('invite_token_hash', $tokenHash)
                ->lockForUpdate()
                ->first();

            if (! $participant) {
                throw new NotFoundHttpException('Пригласительный токен участника пилота не найден или недействителен.');
            }

            if ($participant->isRevoked()) {
                throw new AccessDeniedHttpException('Приглашение участника пилотного исследования было отозвано организаторами.');
            }

            if ($participant->isExpired()) {
                throw new AccessDeniedHttpException('Срок действия пригласительного токена истек.');
            }

            if ($participant->is_submitted) {
                throw new ConflictHttpException('Анкета по данному пригласительному токену уже была отправлена в систему.');
            }

            // Verify scenario exists and belongs to participant's school
            $scenario = Scenario::where('id', $data['scenario_id'])->first();
            if (! $scenario) {
                throw new NotFoundHttpException('Оцениваемый сценарий не найден в системе.');
            }

            if ($scenario->school_id !== $participant->school_id) {
                throw new AccessDeniedHttpException('Оцениваемый сценарий принадлежит другой образовательной организации.');
            }

            // Server-side calculation of time reduction
            $baseline = (int) $data['prep_time_baseline_minutes'];
            $ai = (int) $data['prep_time_ai_minutes'];

            if ($baseline <= 0) {
                throw new UnprocessableEntityHttpException('Базовое время подготовки должно быть положительным числом минут.');
            }
            if ($ai < 0) {
                throw new UnprocessableEntityHttpException('Время подготовки с ИИ не может быть отрицательным.');
            }

            $relativeTimeSaving = round(($baseline - $ai) / $baseline, 4);

            $manualEdit = (float) $data['manual_edit_percentage'];
            if ($manualEdit < 0 || $manualEdit > 100) {
                throw new UnprocessableEntityHttpException('Объем ручных доработок должен находиться в диапазоне от 0.00% до 100.00%.');
            }

            $culturalUsed = (bool) $data['cultural_context_used'];
            $culturalScore = null;
            if ($culturalUsed) {
                if (! isset($data['cultural_usefulness_score'])) {
                    throw new UnprocessableEntityHttpException('При использовании этнокультурного контекста обязательна оценка его полезности (1-5).');
                }
                $culturalScore = (int) $data['cultural_usefulness_score'];
                if ($culturalScore < 1 || $culturalScore > 5) {
                    throw new UnprocessableEntityHttpException('Оценка полезности этнокультурного контекста должна быть от 1 до 5.');
                }
            }

            // Server-side trusted origin: strictly derived from participant, cannot be set or downgraded by anonymous client
            $isSynthetic = (bool) $participant->is_synthetic;

            $comparisonId = $data['comparison_id'] ?? null;
            if ($comparisonId !== null) {
                $comparison = ScenarioComparison::whereKey($comparisonId)->lockForUpdate()->first();
                if (! $comparison || $comparison->school_id !== $participant->school_id
                    || $comparison->status !== 'completed'
                    || ! in_array($scenario->id, [$comparison->variant_a_scenario_id, $comparison->variant_b_scenario_id], true)
                    || ($scenario->id === $comparison->variant_b_scenario_id && ! app(ScenarioComparisonService::class)->isCulturalAvailable($comparison))) {
                    throw ValidationException::withMessages([
                        'comparison_id' => 'Сравнение должно быть завершено, принадлежать школе участника и включать оцениваемый допустимый сценарий.',
                    ]);
                }
            }

            $response = PilotResponse::create([
                'participant_id' => $participant->id,
                'scenario_id' => $scenario->id,
                'comparison_id' => $comparisonId,
                'prep_time_baseline_minutes' => $baseline,
                'prep_time_ai_minutes' => $ai,
                'relative_time_saving' => $relativeTimeSaving,
                'personalization_selected' => (bool) $data['personalization_selected'],
                'manual_edit_percentage' => $manualEdit,
                'cultural_context_used' => $culturalUsed,
                'cultural_usefulness_score' => $culturalScore,
                'feedback_text' => $data['feedback_text'] ?? null,
                'is_synthetic' => $isSynthetic,
                'submitted_at' => now(),
            ]);

            // Atomic update of participant submission status
            $participant->is_submitted = true;
            $participant->used_at = now();
            $participant->save();

            return $response;
        });
    }

    /**
     * Submit expert review finding with strict RBAC and append-only versioning.
     */
    public function submitReviewFinding(PilotResponse $response, User $expert, array $data): PilotReviewFinding
    {
        if (! $expert->is_active) {
            throw new AccessDeniedHttpException('Учетная запись эксперта заблокирована или неактивна.');
        }

        // Determine school strictly via participant relation, never from client input
        $response->loadMissing('participant.school');
        $participant = $response->participant;
        $school = $participant?->school;

        if (! $participant || ! $school) {
            throw new NotFoundHttpException('Образовательная организация участника анкетирования не найдена.');
        }

        // Strict RBAC evaluation
        $isAuthorized = false;

        if ($expert->isSystemAdmin()) {
            $isAuthorized = true;
        } elseif ($expert->isCurator()) {
            if ($expert->curator_scope === 'regional') {
                $allowedRegions = $expert->curated_region_codes ?? [];
                if (in_array($school->region_code, $allowedRegions, true)) {
                    $isAuthorized = true;
                } else {
                    throw new AccessDeniedHttpException("Куратор региона не имеет полномочий для школы региона '{$school->region_code}'.");
                }
            } else {
                // Global / all_regions curator
                $isAuthorized = true;
            }
        } elseif ($expert->hasSchoolRole($school->id, 'school_admin')) {
            $isAuthorized = true;
        }

        if (! $isAuthorized) {
            throw new AccessDeniedHttpException('Только уполномоченные администраторы школы, кураторы региона и системные администраторы имеют право вносить экспертные заключения.');
        }

        $auditStatus = $data['expert_audit_status'] ?? 'audited';
        $criticalErrors = (int) ($data['critical_cultural_errors'] ?? 0);
        if ($criticalErrors < 0) {
            throw new UnprocessableEntityHttpException('Количество критических культурных ошибок не может быть отрицательным.');
        }

        // Append-only versioning within atomic transaction with row locking
        return DB::transaction(function () use ($response, $expert, $auditStatus, $criticalErrors, $data) {
            /** @var PilotResponse $lockedResponse */
            $lockedResponse = PilotResponse::where('id', $response->id)
                ->lockForUpdate()
                ->firstOrFail();

            $latestFinding = PilotReviewFinding::where('pilot_response_id', $lockedResponse->id)
                ->lockForUpdate()
                ->orderByDesc('finding_version')
                ->first();

            $nextVersion = $latestFinding ? ($latestFinding->finding_version + 1) : 1;

            // Mark previous active findings as not effective
            PilotReviewFinding::where('pilot_response_id', $lockedResponse->id)
                ->where('is_effective', true)
                ->update(['is_effective' => false]);

            return PilotReviewFinding::create([
                'pilot_response_id' => $lockedResponse->id,
                'expert_user_id' => $expert->id,
                'expert_name' => $expert->name,
                'expert_audit_status' => $auditStatus,
                'critical_cultural_errors' => $criticalErrors,
                'methodological_remarks' => $data['methodological_remarks'] ?? $data['expert_comments'] ?? null,
                'finding_version' => $nextVersion,
                'is_effective' => true,
                'reviewed_at' => now(),
            ]);
        });
    }

    /**
     * Calculate pilot summary metrics with sample size ladder, incomplete review logic, and synthetic filtering.
     */
    public function calculateMetrics(?string $schoolId = null, string $scope = 'school', bool $includeSynthetic = false): array
    {
        $query = PilotResponse::query()->with(['participant.school']);

        if (! $includeSynthetic) {
            $query->where('is_synthetic', false)
                ->whereHas('participant', fn ($q) => $q->where('is_synthetic', false));
        }

        if ($scope === 'school' && $schoolId) {
            $query->whereHas('participant', fn ($q) => $q->where('school_id', $schoolId));
        }

        $responses = $query->get();
        $total = $responses->count();

        if ($total === 0) {
            return [
                'status' => 'no_data',
                'total_participants' => 0,
                'submitted_responses_count' => 0,
                'audited_responses_count' => 0,
                'audited_reviews_count' => 0,
                'pending_expert_reviews_count' => 0,
                'expert_review_coverage_percent' => null,
                'median_relative_time_saving_percent' => null,
                'personalization_choice_percent' => null,
                'self_reported_personalization_choice_percent' => null,
                'manual_edit_under_20_percent_ratio' => null,
                'cultural_context_usage_percent' => null,
                'average_cultural_usefulness_score' => null,
                'critical_cultural_errors_count' => null,
                'sample_size' => 0,
                'target_sample_size' => 20,
                'is_survey_completed' => false,
                'is_expert_evaluation_completed' => false,
                'is_pilot_completed' => false,
                'criteria_progress' => [
                    'time_saving_ge_50' => false,
                    'personalization_ge_70' => false,
                    'manual_edit_under_20_ge_70' => false,
                    'cultural_usage_ge_60' => false,
                    'cultural_usefulness_ge_4' => false,
                    'zero_critical_cultural_errors' => false,
                ],
                'criteria_status' => [
                    'time_saving_ge_50' => 'pending_data',
                    'personalization_ge_70' => 'pending_data',
                    'manual_edit_under_20_ge_70' => 'pending_data',
                    'cultural_usage_ge_60' => 'pending_data',
                    'cultural_usefulness_ge_4' => 'pending_data',
                    'zero_critical_cultural_errors' => 'pending_review',
                ],
                'sample_breakdown' => [
                    'personalization' => ['selected' => 0, 'total' => 0],
                    'manual_edits' => ['under_20_percent' => 0, 'total' => 0],
                    'cultural_context' => ['used' => 0, 'rated_count' => 0, 'total' => 0],
                    'expert_reviews' => ['audited' => 0, 'pending' => 0, 'coverage_percent' => null, 'critical_errors' => null],
                ],
            ];
        }

        // 1. Median relative time reduction percent
        $savings = $responses->map(fn ($r) => round((float) $r->relative_time_saving * 100, 2))
            ->sort()
            ->values()
            ->all();

        $count = count($savings);
        $mid = (int) floor($count / 2);
        if ($count % 2 === 1) {
            $medianSaving = $savings[$mid];
        } else {
            $medianSaving = round(($savings[$mid - 1] + $savings[$mid]) / 2, 2);
        }

        // 2. Personalization choice percent (Self-reported by teachers in questionnaire)
        $persCount = $responses->where('personalization_selected', true)->count();
        $persPercent = round(($persCount / $total) * 100, 2);

        // 3. Teachers with manual edit <= 20% (Target: >= 70%)
        $under20Count = $responses->where('manual_edit_percentage', '<=', 20.00)->count();
        $under20Ratio = round(($under20Count / $total) * 100, 2);

        // 4. Cultural context usage percent (Target: >= 60%)
        $cultCount = $responses->where('cultural_context_used', true)->count();
        $cultPercent = round(($cultCount / $total) * 100, 2);

        // 5. Average cultural usefulness score (Target: >= 4.0 / 5.0)
        $cultResponses = $responses->where('cultural_context_used', true)->whereNotNull('cultural_usefulness_score');
        $avgCultScore = $cultResponses->count() > 0 ? round((float) $cultResponses->avg('cultural_usefulness_score'), 2) : null;

        // 6. Expert review findings (Strictly evaluate only effective version per response)
        $responseIds = $responses->pluck('id');
        $auditedReviews = PilotReviewFinding::whereIn('pilot_response_id', $responseIds)
            ->where('is_effective', true)
            ->where('expert_audit_status', 'audited')
            ->get();

        $auditedCount = $auditedReviews->unique('pilot_response_id')->count();
        $pendingReviewsCount = max(0, $total - $auditedCount);
        $coveragePercent = round(($auditedCount / $total) * 100, 2);

        // If no reviews audited yet, return null
        if ($auditedCount === 0) {
            $criticalErrors = null;
        } else {
            $criticalErrors = (int) $auditedReviews->sum('critical_cultural_errors');
        }

        // Validation & Research Integrity Rules:
        // Survey is completed only when N >= 20
        $isSurveyCompleted = ($total >= 20);

        // Expert evaluation is completed only when N >= 20 AND 100% of responses are audited
        $isExpertEvaluationCompleted = ($total >= 20 && $auditedCount >= $total);

        // Zero critical errors criterion receives PASS ONLY when sample audit is 100% complete AND errors == 0
        $zeroCriticalErrorsPass = ($total > 0 && $auditedCount >= $total && $criticalErrors === 0);

        $hasCriticalErrors = ($criticalErrors !== null && $criticalErrors > 0);

        // Pilot completed strictly requires both survey completion and full audit without critical errors
        $isPilotCompleted = ($isSurveyCompleted && $isExpertEvaluationCompleted && ! $hasCriticalErrors);

        $status = ($total === 0) ? 'no_data' : ($isPilotCompleted ? 'completed' : 'active_pilot');

        return [
            'status' => $status,
            'total_participants' => $total,
            'submitted_responses_count' => $total,
            'audited_responses_count' => $auditedCount,
            'audited_reviews_count' => $auditedCount,
            'pending_expert_reviews_count' => $pendingReviewsCount,
            'expert_review_coverage_percent' => $coveragePercent,
            'median_relative_time_saving_percent' => $medianSaving,
            'personalization_choice_percent' => $persPercent,
            'self_reported_personalization_choice_percent' => $persPercent,
            'manual_edit_under_20_percent_ratio' => $under20Ratio,
            'cultural_context_usage_percent' => $cultPercent,
            'average_cultural_usefulness_score' => $avgCultScore,
            'critical_cultural_errors_count' => $criticalErrors,
            'sample_size' => $total,
            'target_sample_size' => 20,
            'is_survey_completed' => $isSurveyCompleted,
            'is_expert_evaluation_completed' => $isExpertEvaluationCompleted,
            'is_pilot_completed' => $isPilotCompleted,
            'criteria_progress' => [
                'time_saving_ge_50' => ($medianSaving !== null && $medianSaving >= 50.0),
                'personalization_ge_70' => ($persPercent >= 70.0),
                'manual_edit_under_20_ge_70' => ($under20Ratio >= 70.0),
                'cultural_usage_ge_60' => ($cultPercent >= 60.0),
                'cultural_usefulness_ge_4' => ($avgCultScore !== null && $avgCultScore >= 4.0),
                'zero_critical_cultural_errors' => $zeroCriticalErrorsPass,
            ],
            'criteria_status' => [
                'time_saving_ge_50' => ($medianSaving !== null && $medianSaving >= 50.0) ? 'pass' : 'fail',
                'personalization_ge_70' => ($persPercent >= 70.0) ? 'pass' : 'fail',
                'manual_edit_under_20_ge_70' => ($under20Ratio >= 70.0) ? 'pass' : 'fail',
                'cultural_usage_ge_60' => ($cultPercent >= 60.0) ? 'pass' : 'fail',
                'cultural_usefulness_ge_4' => ($avgCultScore !== null && $avgCultScore >= 4.0) ? 'pass' : ($avgCultScore === null ? 'pending_data' : 'fail'),
                'zero_critical_cultural_errors' => ($auditedCount === 0)
                    ? 'pending_review'
                    : ($auditedCount < $total
                        ? ($criticalErrors > 0 ? 'fail' : 'pending_review')
                        : ($criticalErrors === 0 ? 'pass' : 'fail')),
            ],
            'sample_breakdown' => [
                'personalization' => ['selected' => $persCount, 'total' => $total],
                'manual_edits' => ['under_20_percent' => $under20Count, 'total' => $total],
                'cultural_context' => ['used' => $cultCount, 'rated_count' => $cultResponses->count(), 'total' => $total],
                'expert_reviews' => [
                    'audited' => $auditedCount,
                    'pending' => $pendingReviewsCount,
                    'coverage_percent' => $coveragePercent,
                    'critical_errors' => $criticalErrors,
                ],
            ],
        ];
    }

    /**
     * Diagnostic inventory of historical and current pilot dataset provenance.
     * Safely classifies records without deleting or modifying any data.
     */
    public function diagnoseDataset(): array
    {
        $responses = PilotResponse::with(['participant.school'])->get();

        $demoSchools = ['Лицей № 130', 'Гимназия № 9', 'Лицей 130', 'Гимназия 9'];
        $demoInns = ['6660012345', '6660067890', '6670012345', '6670054321', '6600000000'];

        $confirmedSynthetic = [];
        $suspectedSynthetic = [];
        $unclassified = [];
        $verifiedReal = [];

        foreach ($responses as $resp) {
            $participant = $resp->participant;
            $school = $participant?->school;

            $isExplicitSynthetic = (bool) $resp->is_synthetic || (bool) $participant?->is_synthetic;

            if ($isExplicitSynthetic) {
                $confirmedSynthetic[] = [
                    'response_id' => $resp->id,
                    'participant_code' => $participant?->anonymous_code,
                    'school_name' => $school?->name ?? 'N/A',
                    'reason' => 'Explicit is_synthetic flag is true',
                ];

                continue;
            }

            // Heuristic detection of legacy demo data (marked is_synthetic=false by migration default)
            $schoolName = $school?->name ?? '';
            $schoolShortName = $school?->short_name ?? '';
            $inn = $school?->inn ?? '';
            $anonCode = $participant?->anonymous_code ?? '';
            $feedback = $resp->feedback_text ?? '';

            $isSuspected = false;
            $suspectReasons = [];

            if (in_array($schoolShortName, $demoSchools, true) || in_array($schoolName, $demoSchools, true)) {
                $isSuspected = true;
                $suspectReasons[] = 'School matches known seeded demo school';
            }

            if (in_array($inn, $demoInns, true) || str_starts_with($inn, '667099')) {
                $isSuspected = true;
                $suspectReasons[] = "School INN '{$inn}' matches demo/audit generator pattern";
            }

            if (str_contains($schoolName, 'Аудит') || str_contains($schoolName, 'Тестовая') || str_contains($schoolName, 'СОШ 177') || str_contains($schoolName, 'Пилотная школа')) {
                $isSuspected = true;
                $suspectReasons[] = 'School title contains test/audit keyword';
            }

            if (str_starts_with($anonCode, 'PILOT-')) {
                $isSuspected = true;
                $suspectReasons[] = 'Participant code matches synthetic batch format PILOT-*';
            }

            if (str_contains($feedback, 'Отличная экономия') || str_contains($feedback, 'Сценарий сэкономил') || str_contains($feedback, 'Отзыв участника')) {
                $isSuspected = true;
                $suspectReasons[] = 'Feedback text matches synthetic evaluation fixture';
            }

            if ($isSuspected) {
                $suspectedSynthetic[] = [
                    'response_id' => $resp->id,
                    'participant_code' => $anonCode,
                    'school_name' => $schoolName,
                    'reasons' => $suspectReasons,
                ];
            } else {
                $isAffirmativelyReal = ($participant?->is_verified_real ?? false) || ($participant?->verified_at !== null);
                if ($isAffirmativelyReal) {
                    $verifiedReal[] = [
                        'response_id' => $resp->id,
                        'participant_code' => $anonCode,
                        'school_name' => $schoolName,
                        'status' => 'verified_real',
                    ];
                } else {
                    $unclassified[] = [
                        'response_id' => $resp->id,
                        'participant_code' => $anonCode,
                        'school_name' => $schoolName,
                        'status' => 'requires_review',
                        'note' => 'Не классифицировано: происхождение не подтверждено документально (требуется ручная верификация).',
                    ];
                }
            }
        }

        return [
            'diagnosed_at' => now()->toIso8601String(),
            'total_responses_scanned' => $responses->count(),
            'summary' => [
                'confirmed_synthetic_count' => count($confirmedSynthetic),
                'suspected_synthetic_count' => count($suspectedSynthetic),
                'unclassified_count' => count($unclassified),
                'requires_review_count' => count($unclassified),
                'verified_real_count' => count($verifiedReal),
            ],
            'safety_notice' => 'Безопасная инвентаризация: 0 записей удалено, 0 записей изменено автоматически. Все исторические данные сохранены для ручной верификации.',
            'confirmed_synthetic' => $confirmedSynthetic,
            'suspected_synthetic' => $suspectedSynthetic,
            'unclassified' => $unclassified,
            'requires_review' => $unclassified,
            'verified_real' => $verifiedReal,
        ];
    }
}
