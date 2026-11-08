<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Scenarios\CreateScenarioComparisonRequest;
use App\Http\Requests\Scenarios\SelectScenarioComparisonVariantRequest;
use App\Models\ScenarioComparison;
use App\Models\ScenarioRequest;
use App\Models\ScenarioRevision;
use App\Services\Llm\PedagogicalQualityGate;
use App\Services\ScenarioComparisonService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ScenarioComparisonController extends Controller
{
    public function __construct(
        protected ScenarioComparisonService $comparisonService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $school = $request->attributes->get('school');
        if (! $school) {
            throw new AccessDeniedHttpException('Требуется контекст образовательной организации (X-School-Id).');
        }

        $items = ScenarioComparison::where('school_id', $school->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (ScenarioComparison $c) => [
                'id' => $c->id,
                'school_id' => $c->school_id,
                'comparison_type' => $c->comparison_type,
                'status' => $c->status,
                'selected_variant' => $c->selected_variant,
                'selection_reason' => $c->selection_reason,
                'selection_recorded_at' => $c->selection_recorded_at?->toIso8601String(),
                'cultural_usefulness_rating' => $c->cultural_usefulness_rating,
                'created_at' => $c->created_at->toIso8601String(),
            ]);

        return response()->json([
            'data' => $items,
        ]);
    }

    public function store(CreateScenarioComparisonRequest $request): JsonResponse
    {
        $school = $request->attributes->get('school');
        $user = $request->user();

        if (! $school) {
            throw new AccessDeniedHttpException('Требуется контекст образовательной организации (X-School-Id).');
        }

        $scenarioRequest = ScenarioRequest::where('id', $request->input('scenario_request_id'))->first();
        if (! $scenarioRequest) {
            throw new NotFoundHttpException('Запрос сценария не найден.');
        }

        $isSynthetic = $request->boolean('is_synthetic', false);

        $comparison = $this->comparisonService->createComparison(
            $school,
            $user,
            $scenarioRequest,
            $request->input('comparison_type'),
            $isSynthetic,
            $request->header('Idempotency-Key')
        );

        return response()->json([
            'data' => $this->formatEnvelope($comparison),
        ], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $school = $request->attributes->get('school');
        $comparison = ScenarioComparison::where('id', $id)->first();

        if (! $comparison) {
            throw new NotFoundHttpException('Сессия сравнения сценариев не найдена.');
        }

        if ($school && $comparison->school_id !== $school->id) {
            throw new AccessDeniedHttpException('Доступ к сессии сравнения другой школы запрещен.');
        }

        return response()->json([
            'data' => $this->formatEnvelope($comparison),
        ]);
    }

    public function variants(Request $request, string $id): JsonResponse
    {
        $school = $request->attributes->get('school');
        $comparison = ScenarioComparison::where('id', $id)->first();

        if (! $comparison) {
            throw new NotFoundHttpException('Сессия сравнения сценариев не найдена.');
        }

        if ($school && $comparison->school_id !== $school->id) {
            throw new AccessDeniedHttpException('Доступ к сессии сравнения другой школы запрещен.');
        }

        return response()->json([
            'data' => [
                'comparison_id' => $comparison->id,
                'comparison_type' => $comparison->comparison_type,
                'differences_summary' => $comparison->differences_summary,
                'quality_review' => ['A' => $this->qualityReview($comparison->variant_a_revision_id), 'B' => $this->qualityReview($comparison->variant_b_revision_id)],
                'selected_variant' => $comparison->selected_variant,
                'selection_reason' => $comparison->selection_reason,
                'variants' => [
                    [
                        'variant_key' => 'A',
                        'variant_label' => ($comparison->comparison_type === 'universal_vs_personalized')
                            ? 'Вариант A: Универсальный сценарий'
                            : 'Вариант A: Без этнокультурного компонента',
                        'scenario_id' => $comparison->variant_a_scenario_id,
                        'scenario_payload' => $comparison->variant_a_payload,
                        'cultural_provenance_data' => $comparison->variant_a_payload['cultural_claims'] ?? [],
                        'is_cultural_available' => true,
                    ],
                    [
                        'variant_key' => 'B',
                        'variant_label' => ($comparison->comparison_type === 'universal_vs_personalized')
                            ? 'Вариант B: Персонализированный сценарий'
                            : 'Вариант B: С верифицированным региональным компонентом',
                        'scenario_id' => $comparison->variant_b_scenario_id,
                        'scenario_payload' => $comparison->variant_b_payload,
                        'cultural_provenance_data' => $comparison->variant_b_payload['cultural_claims'] ?? [],
                        'is_cultural_available' => $this->comparisonService->isCulturalAvailable($comparison),
                    ],
                ],
            ],
        ]);
    }

    public function selection(SelectScenarioComparisonVariantRequest $request, string $id): JsonResponse
    {
        $school = $request->attributes->get('school');
        $comparison = ScenarioComparison::where('id', $id)->first();

        if (! $comparison) {
            throw new NotFoundHttpException('Сессия сравнения сценариев не найдена.');
        }

        if ($school && $comparison->school_id !== $school->id) {
            throw new AccessDeniedHttpException('Доступ к сессии сравнения другой школы запрещен.');
        }

        $updated = $this->comparisonService->recordSelection(
            $comparison,
            $request->user(),
            $request->input('selected_variant'),
            $request->input('selection_reason'),
            $request->input('cultural_usefulness_rating')
        );

        return response()->json([
            'data' => $this->formatEnvelope($updated),
        ]);
    }

    private function qualityReview(?string $revisionId): ?array
    {
        $revision = $revisionId ? ScenarioRevision::find($revisionId) : null;

        return $revision ? app(PedagogicalQualityGate::class)->forRevision($revision) : null;
    }

    protected function formatEnvelope(ScenarioComparison $comparison): array
    {
        return [
            'id' => $comparison->id,
            'school_id' => $comparison->school_id,
            'comparison_type' => $comparison->comparison_type,
            'status' => $this->comparisonService->recoverInterrupted($comparison)->status,
            'selected_variant' => $comparison->selected_variant,
            'selected_by_user_id' => $comparison->selected_by_user_id,
            'selection_reason' => $comparison->selection_reason,
            'cultural_usefulness_rating' => $comparison->cultural_usefulness_rating,
            'selection_recorded_at' => $comparison->selection_recorded_at?->toIso8601String(),
            'differences_summary' => $comparison->differences_summary,
            'quality_review' => ['A' => $this->qualityReview($comparison->variant_a_revision_id), 'B' => $this->qualityReview($comparison->variant_b_revision_id)],
            'is_cultural_available' => $this->comparisonService->isCulturalAvailable($comparison),
            'is_synthetic' => (bool) $comparison->is_synthetic,
            'variants' => [
                [
                    'variant_key' => 'A',
                    'scenario_id' => $comparison->variant_a_scenario_id,
                    'scenario_payload' => $comparison->variant_a_payload,
                    'is_cultural_available' => true,
                ],
                [
                    'variant_key' => 'B',
                    'scenario_id' => $comparison->variant_b_scenario_id,
                    'scenario_payload' => $comparison->variant_b_payload,
                    'is_cultural_available' => $this->comparisonService->isCulturalAvailable($comparison),
                ],
            ],
            'created_at' => $comparison->created_at->toIso8601String(),
        ];
    }
}
