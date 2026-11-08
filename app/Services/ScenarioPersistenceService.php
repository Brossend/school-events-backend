<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\GenerationRun;
use App\Models\RevisionModerationState;
use App\Models\Scenario;
use App\Models\ScenarioMaterial;
use App\Models\ScenarioRequest;
use App\Models\ScenarioRevision;
use App\Models\ScenarioStage;
use Illuminate\Support\Facades\DB;

class ScenarioPersistenceService
{
    /**
     * Persist generated scenario and its relational representations in an atomic transaction.
     *
     * @param ScenarioRequest $request
     * @param GenerationRun $run
     * @param array $normalizedPayload
     * @return Scenario
     */
    public function persist(
        ScenarioRequest $request,
        GenerationRun $run,
        array $normalizedPayload
    ): Scenario {
        return DB::transaction(function () use ($request, $run, $normalizedPayload) {
            $occasionTitle = $request->context_snapshot['occasion']['title'] ?? 'Методический сценарий';
            $title = 'Сценарий: ' . $occasionTitle;

            // 1. Create Scenario shell
            $scenario = Scenario::create([
                'school_id' => $request->school_id,
                'user_id' => $request->user_id,
                'scenario_request_id' => $request->id,
                'current_revision_id' => null,
                'title' => $title,
                'optimistic_lock_version' => 1,
                'is_archived' => false,
            ]);

            // 2. Create initial ScenarioRevision v1
            $revision = ScenarioRevision::create([
                'scenario_id' => $scenario->id,
                'version_number' => 1,
                'source_generation_run_id' => $run->id,
                'created_by_user_id' => $request->user_id,
                'content_payload' => $normalizedPayload,
                'concept' => $normalizedPayload['concept'],
                'pedagogical_goal' => $normalizedPayload['pedagogical_goal'],
                'pedagogical_mechanism' => $normalizedPayload['pedagogical_mechanism'],
                'pedagogical_tasks' => $normalizedPayload['pedagogical_tasks'],
                'expected_outcomes' => $normalizedPayload['expected_outcomes'],
                'target_audience' => $normalizedPayload['target_audience'],
                'format' => $normalizedPayload['format'],
                'total_duration_minutes' => (int) $normalizedPayload['format']['total_duration_minutes'],
                'teacher_instructions' => $normalizedPayload['teacher_instructions'],
            ]);

            // 3. Link current_revision_id to Scenario
            $scenario->current_revision_id = $revision->id;
            $scenario->save();

            // 4. Populate relational scenario_stages
            foreach ($normalizedPayload['stages'] ?? [] as $stage) {
                ScenarioStage::create([
                    'revision_id' => $revision->id,
                    'order_index' => (int) $stage['order_index'],
                    'stage_identifier' => $stage['id'],
                    'title' => $stage['title'],
                    'duration_minutes' => (int) $stage['duration_minutes'],
                    'stage_type' => $stage['stage_type'],
                    'teacher_actions' => $stage['teacher_actions'],
                    'student_actions' => $stage['student_actions'],
                    'dialogue_prompts' => $stage['dialogue_prompts'] ?? [],
                    'teacher_tips' => $stage['teacher_tips'] ?? null,
                    'transition_text' => $stage['transition_text'] ?? null,
                ]);
            }

            // 5. Populate relational scenario_materials
            $materials = $normalizedPayload['materials'] ?? [];

            foreach ($materials['presentation_slides'] ?? [] as $idx => $slide) {
                ScenarioMaterial::create([
                    'revision_id' => $revision->id,
                    'material_type' => 'presentation_slide',
                    'title' => $slide['title'] ?? ('Слайд ' . ($slide['slide_number'] ?? ($idx + 1))),
                    'order_index' => $idx + 1,
                    'content' => $slide,
                ]);
            }

            foreach ($materials['printable_cards'] ?? [] as $idx => $card) {
                ScenarioMaterial::create([
                    'revision_id' => $revision->id,
                    'material_type' => 'printable_card',
                    'title' => $card['title'],
                    'order_index' => $idx + 1,
                    'content' => $card,
                ]);
            }

            foreach ($materials['handouts'] ?? [] as $idx => $handout) {
                ScenarioMaterial::create([
                    'revision_id' => $revision->id,
                    'material_type' => 'handout_sheet',
                    'title' => $handout['title'],
                    'order_index' => $idx + 1,
                    'content' => $handout,
                ]);
            }

            if (! empty($normalizedPayload['props'])) {
                ScenarioMaterial::create([
                    'revision_id' => $revision->id,
                    'material_type' => 'props_checklist',
                    'title' => 'Чек-лист реквизита и оборудования',
                    'order_index' => 1,
                    'content' => $normalizedPayload['props'],
                ]);
            }

            // 6. Initialize revision_moderation_states
            RevisionModerationState::create([
                'revision_id' => $revision->id,
                'status' => 'pending_verification',
                'p0_violations_count' => 0,
                'p1_violations_count' => 0,
                'updated_at' => now(),
            ]);

            // 7. Update GenerationRun to completed
            $run->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);

            return $scenario;
        });
    }
}
