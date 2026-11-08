<?php

declare(strict_types=1);

namespace App\Services\Llm;

use Illuminate\Validation\ValidationException;

class ScenarioSemanticValidator
{
    protected const DIRECTION_MECHANISM_MAP = [
        'family' => 'family_investigation',
        'mutual_aid' => 'team_mutual_support_task',
        'historical_memory' => 'historical_source_analysis',
        'labor_and_creation' => 'collaborative_creative_product',
        'cultural_diversity' => 'comparative_cultural_study',
        'civic_responsibility' => 'civic_community_action',
    ];

    /**
     * Validate semantic pedagogical and structural rules of the generated scenario.
     *
     * @param array $payload Raw LLM payload
     * @param int|array $expectedDurationOrContext Expected duration in minutes or context snapshot
     * @throws ValidationException
     */
    public function validate(array $payload, int|array $expectedDurationOrContext): void
    {
        $expectedDurationMinutes = is_array($expectedDurationOrContext)
            ? (int) ($expectedDurationOrContext['pedagogical_parameters']['duration_minutes']
                ?? $expectedDurationOrContext['duration_minutes']
                ?? $payload['format']['total_duration_minutes']
                ?? 45)
            : $expectedDurationOrContext;

        $errors = [];

        // 1. Check prohibited status fields
        if (isset($payload['verification_status']) || isset($payload['server_verification_status'])) {
            $errors['RULE_PROHIBITED_STATUS_FIELDS'] = 'LLM payload contains prohibited status declaration fields.';
        }

        // 2. Validate stages uniqueness
        $stages = $payload['stages'] ?? [];
        $stageIds = [];
        $totalStageMinutes = 0;

        foreach ($stages as $index => $stage) {
            $id = $stage['id'] ?? null;
            if ($id === null || in_array($id, $stageIds, true)) {
                $errors['RULE_STAGE_ID_DUPLICATE'] = "Duplicate or missing stage ID '{$id}' at stage index {$index}.";
            } else {
                $stageIds[] = $id;
            }

            $totalStageMinutes += (int) ($stage['duration_minutes'] ?? 0);
        }

        // 3. Validate timing sum
        $formatDuration = (int) ($payload['format']['total_duration_minutes'] ?? 0);
        if ($totalStageMinutes !== $formatDuration || $totalStageMinutes !== $expectedDurationMinutes) {
            $errors['RULE_TIMING_SUM_MISMATCH'] = "Timing sum mismatch: sum of stages ({$totalStageMinutes} min) does not match total duration ({$formatDuration} min, expected: {$expectedDurationMinutes} min).";
        }

        // 4. Validate activity links to stages
        $activities = $payload['activities'] ?? [];
        $activityTitles = [];

        foreach ($activities as $actIndex => $activity) {
            $title = $activity['title'] ?? '';
            $activityTitles[] = $title;

            $targetStage = $activity['target_stage_id'] ?? null;
            if (! in_array($targetStage, $stageIds, true)) {
                $errors['RULE_ACTIVITY_STAGE_NOT_FOUND'] = "Activity '{$title}' refers to non-existent stage '{$targetStage}'.";
            }
        }

        // 5. Validate mechanism target activity existence
        $targetActivityTitle = $payload['pedagogical_mechanism']['target_activity_title'] ?? null;
        if (! in_array($targetActivityTitle, $activityTitles, true)) {
            $errors['RULE_MECHANISM_ACTIVITY_NOT_FOUND'] = "Pedagogical mechanism references non-existent target activity '{$targetActivityTitle}'.";
        }

        // 6. Validate value direction and mechanism compatibility
        $direction = $payload['value_direction'] ?? '';
        $mechanismType = $payload['pedagogical_mechanism']['mechanism_type'] ?? '';
        $expectedMechanism = self::DIRECTION_MECHANISM_MAP[$direction] ?? null;

        if ($expectedMechanism === null || $mechanismType !== $expectedMechanism) {
            $errors['RULE_DIRECTION_MECHANISM_MISMATCH'] = "Incompatible value direction '{$direction}' and mechanism type '{$mechanismType}'. Expected: '{$expectedMechanism}'.";
        }

        // 7. Validate audience range
        $audienceMin = (int) ($payload['target_audience']['participants_count_min'] ?? 0);
        $audienceMax = (int) ($payload['target_audience']['participants_count_max'] ?? 0);

        if ($audienceMin > $audienceMax) {
            $errors['RULE_AUDIENCE_RANGE_INVALID'] = "participants_count_min ({$audienceMin}) > participants_count_max ({$audienceMax}).";
        }

        if (! empty($errors)) {
            throw ValidationException::withMessages($errors);
        }
    }
}
