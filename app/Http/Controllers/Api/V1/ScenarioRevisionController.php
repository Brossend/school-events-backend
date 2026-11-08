<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\RunVerificationJob;
use App\Models\RevisionModerationState;
use App\Models\Scenario;
use App\Models\ScenarioMaterial;
use App\Models\ScenarioRevision;
use App\Models\ScenarioStage;
use App\Models\VerificationRun;
use App\Services\Llm\PedagogicalQualityGate;
use App\Services\Llm\ScenarioSchemaValidator;
use App\Services\Llm\ScenarioSemanticValidator;
use App\Services\Moderation\ModerationEngineService;
use App\Services\TenantContext;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ScenarioRevisionController extends Controller
{
    /**
     * Patch a scenario revision: creates a new immutable revision N+1.
     */
    public function update(
        string $id,
        int $version,
        Request $request,
        TenantContext $tenantContext,
        ScenarioSchemaValidator $schemaValidator,
        ScenarioSemanticValidator $semanticValidator,
        ModerationEngineService $engine
    ): JsonResponse {
        $schoolId = $tenantContext->getSchoolId();
        $user = $request->user();

        $scenario = Scenario::with('scenarioRequest')->find($id);
        if (! $scenario) {
            return ApiResponse::error(
                code: 'SCENARIO_NOT_FOUND',
                message: 'Сценарий не найден.',
                details: [],
                status: 404
            );
        }

        if ($scenario->school_id !== $schoolId && ! ($user && $user->isSystemAdmin())) {
            return ApiResponse::error(
                code: 'FORBIDDEN_TENANT_ACCESS',
                message: 'Доступ к сценарию другой школы запрещен.',
                details: [],
                status: 403
            );
        }

        $canAccess = ($user && $user->isSystemAdmin())
            || ($user && $user->hasSchoolRole($schoolId, 'school_admin'))
            || ($scenario->user_id === $user?->id);

        if (! $canAccess) {
            return ApiResponse::error(
                code: 'FORBIDDEN_AUTHOR_ACCESS',
                message: 'Редактирование сценария другого педагога запрещено.',
                details: [],
                status: 403
            );
        }

        // 1. Validate If-Match header against optimistic_lock_version
        $ifMatch = $request->header('If-Match');
        if (! $ifMatch) {
            return ApiResponse::error(
                code: 'IF_MATCH_HEADER_REQUIRED',
                message: 'Заголовок If-Match обязателен для редактирования сценария.',
                details: [],
                status: 409
            );
        }

        $cleanIfMatch = trim($ifMatch, "\"' \t\n\r\0\x0B");

        // 2. Validate request input fields
        $rules = [
            'concept' => 'sometimes|string|min:20',
            'pedagogical_goal' => 'sometimes|string|min:15',
            'pedagogical_tasks' => 'sometimes|array',
            'stages' => 'sometimes|array|min:1',
            'stages.*.title' => 'required_with:stages|string',
            'stages.*.duration_minutes' => 'required_with:stages|integer|min:1',
            'stages.*.stage_type' => 'required_with:stages|string',
            'stages.*.teacher_actions' => 'required_with:stages|string',
            'stages.*.student_actions' => 'required_with:stages|string',
            'materials' => 'sometimes',
        ];

        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            return ApiResponse::error(
                code: 'VALIDATION_ERROR',
                message: 'Ошибка валидации полей редактирования.',
                details: $validator->errors()->toArray(),
                status: 422
            );
        }

        // 3. Atomically lock scenario and persist Revision N+1
        $transactionResult = DB::transaction(function () use (
            $id, $version, $cleanIfMatch, $schoolId, $user, $request, $schemaValidator, $semanticValidator
        ) {
            // Lock scenario row in PostgreSQL
            $scenario = Scenario::with('scenarioRequest')->where('id', $id)->lockForUpdate()->first();
            if (! $scenario) {
                return [
                    'error' => ApiResponse::error('SCENARIO_NOT_FOUND', 'Сценарий не найден.', [], 404),
                ];
            }

            if ($scenario->school_id !== $schoolId && ! ($user && $user->isSystemAdmin())) {
                return [
                    'error' => ApiResponse::error('FORBIDDEN_TENANT_ACCESS', 'Доступ к сценарию другой школы запрещен.', [], 403),
                ];
            }

            $canAccess = ($user && $user->isSystemAdmin())
                || ($user && $user->hasSchoolRole($schoolId, 'school_admin'))
                || ($scenario->user_id === $user?->id);

            if (! $canAccess) {
                return [
                    'error' => ApiResponse::error('FORBIDDEN_AUTHOR_ACCESS', 'Редактирование сценария другого педагога запрещено.', [], 403),
                ];
            }

            // Post-lock check: ensure If-Match matches current optimistic_lock_version
            if ((string) $scenario->optimistic_lock_version !== $cleanIfMatch) {
                return [
                    'error' => ApiResponse::error(
                        'OPTIMISTIC_LOCK_CONFLICT',
                        'Версия сценария устарела (конфликт блокировки). Обновите данные перед сохранением.',
                        [
                            'provided_version' => $cleanIfMatch,
                            'current_version' => $scenario->optimistic_lock_version,
                        ],
                        409
                    ),
                ];
            }

            // Fetch current revision to edit
            $currentRevision = ScenarioRevision::where('scenario_id', $scenario->id)
                ->where('version_number', $version)
                ->first();

            if (! $currentRevision) {
                return [
                    'error' => ApiResponse::error('REVISION_NOT_FOUND', "Ревизия версии {$version} не найдена.", [], 404),
                ];
            }

            // Must be current active revision
            if ($scenario->current_revision_id !== $currentRevision->id) {
                return [
                    'error' => ApiResponse::error(
                        'CANNOT_EDIT_HISTORICAL_REVISION',
                        'Редактирование возможно только для текущей активной ревизии сценария.',
                        [
                            'requested_revision_id' => $currentRevision->id,
                            'current_revision_id' => $scenario->current_revision_id,
                        ],
                        409
                    ),
                ];
            }

            // Merge changes into content payload
            $oldPayload = $currentRevision->content_payload ?? [];
            $mergedPayload = $oldPayload;

            if ($request->has('concept')) {
                $mergedPayload['concept'] = $request->input('concept');
            }
            if ($request->has('pedagogical_goal')) {
                $mergedPayload['pedagogical_goal'] = $request->input('pedagogical_goal');
            }
            if ($request->has('pedagogical_tasks')) {
                $mergedPayload['pedagogical_tasks'] = $request->input('pedagogical_tasks');
            }
            if ($request->has('stages')) {
                $mergedPayload['stages'] = $request->input('stages');
                $sum = 0;
                foreach ($mergedPayload['stages'] as $st) {
                    $sum += (int) ($st['duration_minutes'] ?? 0);
                }
                if ($sum > 0) {
                    if (! isset($mergedPayload['format']) || ! is_array($mergedPayload['format'])) {
                        $mergedPayload['format'] = [];
                    }
                    $mergedPayload['format']['total_duration_minutes'] = $sum;
                }
            }
            if ($request->has('materials')) {
                $mergedPayload['materials'] = $request->input('materials');
            }

            // Validate merged payload with schema and semantic validator
            try {
                $contextSnapshot = $scenario->scenarioRequest?->context_snapshot ?? [];
                $schemaValidator->validate($mergedPayload);
                $semanticValidator->validate($mergedPayload, $contextSnapshot);
            } catch (ValidationException $ve) {
                return [
                    'error' => ApiResponse::error(
                        'SEMANTIC_VALIDATION_FAILED',
                        'Сценарий не соответствует педагогическим нормам или схеме.',
                        $ve->errors(),
                        422
                    ),
                ];
            }

            $nextVersionNumber = (int) ScenarioRevision::where('scenario_id', $scenario->id)->max('version_number') + 1;

            $rev = ScenarioRevision::create([
                'scenario_id' => $scenario->id,
                'version_number' => $nextVersionNumber,
                'source_generation_run_id' => null,
                'created_by_user_id' => $user->id,
                'content_payload' => $mergedPayload,
                'concept' => (string) ($mergedPayload['concept'] ?? $currentRevision->concept),
                'pedagogical_goal' => (string) ($mergedPayload['pedagogical_goal'] ?? $currentRevision->pedagogical_goal),
                'pedagogical_mechanism' => $mergedPayload['pedagogical_mechanism'] ?? $currentRevision->pedagogical_mechanism,
                'pedagogical_tasks' => $mergedPayload['pedagogical_tasks'] ?? $currentRevision->pedagogical_tasks,
                'expected_outcomes' => $mergedPayload['expected_outcomes'] ?? $currentRevision->expected_outcomes,
                'target_audience' => $mergedPayload['target_audience'] ?? $currentRevision->target_audience,
                'format' => $mergedPayload['format'] ?? $currentRevision->format,
                'total_duration_minutes' => (int) ($mergedPayload['format']['total_duration_minutes'] ?? $currentRevision->total_duration_minutes),
                'teacher_instructions' => $mergedPayload['teacher_instructions'] ?? $currentRevision->teacher_instructions,
                'created_at' => now(),
            ]);

            // Project relational stages
            $stages = $mergedPayload['stages'] ?? [];
            foreach ($stages as $idx => $st) {
                ScenarioStage::create([
                    'revision_id' => $rev->id,
                    'order_index' => (int) ($st['order_index'] ?? ($idx + 1)),
                    'stage_identifier' => (string) ($st['stage_identifier'] ?? ('stage_'.($idx + 1))),
                    'title' => (string) ($st['title'] ?? 'Этап'),
                    'duration_minutes' => (int) ($st['duration_minutes'] ?? 10),
                    'stage_type' => (string) ($st['stage_type'] ?? 'interactive'),
                    'teacher_actions' => (string) ($st['teacher_actions'] ?? ''),
                    'student_actions' => (string) ($st['student_actions'] ?? ''),
                    'dialogue_prompts' => $st['dialogue_prompts'] ?? [],
                    'teacher_tips' => $st['teacher_tips'] ?? null,
                    'transition_text' => $st['transition_text'] ?? null,
                ]);
            }

            // Project relational materials
            $materials = $mergedPayload['materials'] ?? [];
            if (is_array($materials)) {
                $matIdx = 1;
                $knownTypes = ['presentation_slide', 'printable_card', 'handout_sheet', 'props_checklist'];
                $isMap = false;
                foreach (array_keys($materials) as $key) {
                    if (in_array($key, $knownTypes, true)) {
                        $isMap = true;
                        break;
                    }
                }

                if ($isMap) {
                    foreach ($materials as $mType => $items) {
                        if (! in_array($mType, $knownTypes, true) || ! is_array($items)) {
                            continue;
                        }
                        foreach ($items as $item) {
                            $itemTitle = is_array($item) ? ($item['title'] ?? ($item['name'] ?? ucfirst(str_replace('_', ' ', $mType)))) : (string) $item;
                            ScenarioMaterial::create([
                                'revision_id' => $rev->id,
                                'material_type' => $mType,
                                'title' => (string) $itemTitle,
                                'order_index' => $matIdx++,
                                'content' => is_array($item) ? $item : ['text' => (string) $item],
                                'created_at' => now(),
                            ]);
                        }
                    }
                } else {
                    foreach ($materials as $item) {
                        if (! is_array($item)) {
                            continue;
                        }
                        $mType = (string) ($item['material_type'] ?? 'props_checklist');
                        if (! in_array($mType, $knownTypes, true)) {
                            $mType = 'props_checklist';
                        }
                        ScenarioMaterial::create([
                            'revision_id' => $rev->id,
                            'material_type' => $mType,
                            'title' => (string) ($item['title'] ?? 'Материал'),
                            'order_index' => (int) ($item['order_index'] ?? $matIdx++),
                            'content' => $item['content'] ?? $item,
                            'created_at' => now(),
                        ]);
                    }
                }
            }

            // Automatically create running verification run & moderation state for new revision
            $vRun = VerificationRun::create([
                'revision_id' => $rev->id,
                'rule_engine_version' => ModerationEngineService::RULE_ENGINE_VERSION,
                'status' => 'running',
                'p0_violations_count' => 0,
                'p1_violations_count' => 0,
                'execution_time_ms' => null,
                'created_at' => now(),
            ]);

            RevisionModerationState::create([
                'revision_id' => $rev->id,
                'status' => 'running',
                'p0_violations_count' => 0,
                'p1_violations_count' => 0,
                'latest_verification_run_id' => $vRun->id,
                'updated_at' => now(),
            ]);

            RunVerificationJob::dispatch($rev->id, $vRun->id)->afterCommit();

            // Atomically switch scenario pointer and increment optimistic_lock_version
            $scenario->current_revision_id = $rev->id;
            $scenario->optimistic_lock_version += 1;
            $scenario->updated_at = now();
            $scenario->save();

            return [
                'scenario' => $scenario,
                'revision' => $rev,
            ];
        });

        if (isset($transactionResult['error'])) {
            return $transactionResult['error'];
        }

        $newRevision = $transactionResult['revision'];
        $scenario = $transactionResult['scenario'];

        // Return updated scenario envelope with 201 Created
        $freshScenario = Scenario::with([
            'currentRevision.moderationState',
            'currentRevision.stages',
            'currentRevision.materials',
        ])->find($scenario->id);

        $rev = $freshScenario->currentRevision;

        return ApiResponse::success([
            'id' => $freshScenario->id,
            'school_id' => $freshScenario->school_id,
            'scenario_request_id' => $freshScenario->scenario_request_id,
            'title' => $freshScenario->title,
            'status' => $freshScenario->is_archived ? 'archived' : 'draft',
            'is_archived' => $freshScenario->is_archived,
            'optimistic_lock_version' => $freshScenario->optimistic_lock_version,
            'moderation_status' => $rev?->moderationState?->status ?? 'pending_verification',
            'created_at' => $freshScenario->created_at?->toIso8601String(),
            'updated_at' => $freshScenario->updated_at?->toIso8601String(),
            'current_revision' => $rev ? [
                'id' => $rev->id,
                'version_number' => $rev->version_number,
                'concept' => $rev->concept,
                'pedagogical_goal' => $rev->pedagogical_goal,
                'content_payload' => $rev->content_payload,
                'moderation_status' => $rev->moderationState?->status ?? 'pending_verification',
                'created_at' => $rev->created_at?->toIso8601String(),
                'stages' => $rev->stages->map(fn ($st) => [
                    'id' => $st->id,
                    'order_index' => $st->order_index,
                    'stage_identifier' => $st->stage_identifier,
                    'title' => $st->title,
                    'duration_minutes' => $st->duration_minutes,
                    'stage_type' => $st->stage_type,
                    'teacher_actions' => $st->teacher_actions,
                    'student_actions' => $st->student_actions,
                ]),
                'materials' => $rev->materials->map(fn ($mat) => [
                    'id' => $mat->id,
                    'order_index' => $mat->order_index,
                    'material_type' => $mat->material_type,
                    'title' => $mat->title,
                    'content' => $mat->content,
                ]),
            ] : null,
        ], status: 201);
    }

    /**
     * Get a specific revision of a scenario.
     */
    public function show(
        string $id,
        int $version,
        TenantContext $tenantContext,
        Request $request
    ): JsonResponse {
        $schoolId = $tenantContext->getSchoolId();
        $user = $request->user();

        $scenario = Scenario::find($id);
        if (! $scenario) {
            return ApiResponse::error(
                code: 'SCENARIO_NOT_FOUND',
                message: 'Сценарий не найден.',
                details: [],
                status: 404
            );
        }

        if ($scenario->school_id !== $schoolId && ! ($user && $user->isSystemAdmin())) {
            return ApiResponse::error(
                code: 'FORBIDDEN_TENANT_ACCESS',
                message: 'Доступ к сценарию другой школы запрещен.',
                details: [],
                status: 403
            );
        }

        $canAccess = ($user && $user->isSystemAdmin())
            || ($user && $user->hasSchoolRole($schoolId, 'school_admin'))
            || ($scenario->user_id === $user?->id);

        if (! $canAccess) {
            return ApiResponse::error(
                code: 'FORBIDDEN_AUTHOR_ACCESS',
                message: 'Доступ к ревизии сценария другого педагога запрещен.',
                details: [],
                status: 403
            );
        }

        $revision = ScenarioRevision::with(['moderationState', 'stages', 'materials', 'teacherSignoffs.user'])
            ->where('scenario_id', $scenario->id)
            ->where('version_number', $version)
            ->first();

        if (! $revision) {
            return ApiResponse::error(
                code: 'REVISION_NOT_FOUND',
                message: "Ревизия версии {$version} не найдена.",
                details: [],
                status: 404
            );
        }

        $latestSignoff = $revision->teacherSignoffs->first();

        return ApiResponse::success([
            'id' => $revision->id,
            'scenario_id' => $scenario->id,
            'version_number' => $revision->version_number,
            'is_current' => $scenario->current_revision_id === $revision->id,
            'concept' => $revision->concept,
            'pedagogical_goal' => $revision->pedagogical_goal,
            'total_duration_minutes' => $revision->total_duration_minutes,
            'moderation_status' => $revision->moderationState?->status ?? 'pending_verification',
            'moderation_state' => $revision->moderationState ? [
                'status' => $revision->moderationState->status,
                'p0_violations_count' => $revision->moderationState->p0_violations_count,
                'p1_violations_count' => $revision->moderationState->p1_violations_count,
                'moderator_notes' => $revision->moderationState->moderator_notes,
                'checked_at' => $revision->moderationState->checked_at?->toIso8601String(),
            ] : null,
            'signoff' => $latestSignoff ? [
                'id' => $latestSignoff->id,
                'is_confirmed' => $latestSignoff->is_confirmed,
                'checklist_confirmed' => $latestSignoff->checklist_confirmed,
                'teacher_notes' => $latestSignoff->teacher_notes,
                'confirmed_at' => $latestSignoff->confirmed_at?->toIso8601String(),
                'user' => $latestSignoff->user ? [
                    'id' => $latestSignoff->user->id,
                    'name' => $latestSignoff->user->name,
                ] : null,
            ] : null,
            'created_at' => $revision->created_at?->toIso8601String(),
            'stages' => $revision->stages->map(fn ($st) => [
                'id' => $st->id,
                'order_index' => $st->order_index,
                'stage_identifier' => $st->stage_identifier,
                'title' => $st->title,
                'duration_minutes' => $st->duration_minutes,
                'stage_type' => $st->stage_type,
                'teacher_actions' => $st->teacher_actions,
                'student_actions' => $st->student_actions,
            ]),
            'materials' => $revision->materials->map(fn ($mat) => [
                'id' => $mat->id,
                'order_index' => $mat->order_index,
                'material_type' => $mat->material_type,
                'title' => $mat->title,
                'content' => $mat->content,
            ]),
            'content_payload' => $revision->content_payload,
            'quality_review' => app(PedagogicalQualityGate::class)->forRevision($revision),
        ]);
    }
}
