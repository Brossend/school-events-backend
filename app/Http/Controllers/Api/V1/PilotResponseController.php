<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pilot\CreatePilotResponseRequest;
use App\Http\Requests\Pilot\CreatePilotReviewFindingRequest;
use App\Models\PilotResponse;
use App\Models\PilotReviewFinding;
use App\Models\School;
use App\Services\PilotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PilotResponseController extends Controller
{
    public function __construct(
        protected PilotService $pilotService
    ) {}

    public function store(CreatePilotResponseRequest $request): JsonResponse
    {
        $token = $request->input('invite_token');
        $response = $this->pilotService->submitResponse($token, $request->validated());

        return response()->json([
            'data' => $this->formatResponse($response),
        ], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $schoolId = $request->attributes->get('school')?->id ?? $request->header('X-School-Id');
        $response = PilotResponse::with(['participant.school'])->where('id', $id)->first();

        if (! $response) {
            throw new NotFoundHttpException('Анкета пилотного исследования не найдена.');
        }

        $targetSchool = $response->participant?->school;
        if (! $targetSchool) {
            throw new NotFoundHttpException('Образовательная организация анкеты не найдена.');
        }

        if ($schoolId && $targetSchool->id !== $schoolId) {
            throw new AccessDeniedHttpException('Доступ к анкете участника другой школы запрещен.');
        }

        $this->authorizePilotAdmin($request, $targetSchool);

        return response()->json([
            'data' => $this->formatResponse($response),
        ]);
    }

    public function reviews(Request $request, string $id): JsonResponse
    {
        $response = PilotResponse::with('participant.school')->find($id);
        if (! $response || ! $response->participant?->school) {
            throw new NotFoundHttpException('Анкета пилотного исследования не найдена.');
        }
        $school = $response->participant->school;
        $this->authorizePilotAdmin($request, $school);
        if ($request->hasHeader('X-School-Id') && $request->header('X-School-Id') !== $school->id) {
            throw new AccessDeniedHttpException('Контекст школы не соответствует анкете.');
        }
        // A single statement gives effective finding and history from one consistent snapshot.
        $versions = PilotReviewFinding::where('pilot_response_id', $response->id)
            ->orderByDesc('finding_version')->get()->map(fn ($finding) => [
                'id' => $finding->id, 'pilot_response_id' => $finding->pilot_response_id,
                'expert_name' => $finding->expert_name, 'expert_audit_status' => $finding->expert_audit_status,
                'critical_cultural_errors' => $finding->critical_cultural_errors,
                'methodological_remarks' => $finding->methodological_remarks,
                'finding_version' => $finding->finding_version, 'is_effective' => $finding->is_effective,
                'reviewed_at' => $finding->reviewed_at?->toIso8601String(),
            ])->values();

        return response()->json(['data' => ['pilot_response_id' => $response->id,
            'effective_review' => $versions->firstWhere('is_effective', true), 'versions' => $versions]]);
    }

    public function submitReview(CreatePilotReviewFindingRequest $request, string $id): JsonResponse
    {
        $response = PilotResponse::with('participant.school')->where('id', $id)->first();
        if (! $response) {
            throw new NotFoundHttpException('Анкета пилотного исследования не найдена.');
        }

        $finding = $this->pilotService->submitReviewFinding(
            $response,
            $request->user(),
            $request->validated()
        );

        return response()->json([
            'data' => [
                'id' => $finding->id,
                'pilot_response_id' => $finding->pilot_response_id,
                'expert_name' => $finding->expert_name,
                'expert_audit_status' => $finding->expert_audit_status,
                'critical_cultural_errors' => $finding->critical_cultural_errors,
                'methodological_remarks' => $finding->methodological_remarks,
                'finding_version' => $finding->finding_version,
                'is_effective' => $finding->is_effective,
                'reviewed_at' => $finding->reviewed_at?->toIso8601String(),
            ],
        ]);
    }

    protected function formatResponse(PilotResponse $response): array
    {
        return [
            'id' => $response->id,
            'participant_id' => $response->participant_id,
            'scenario_id' => $response->scenario_id,
            'comparison_id' => $response->comparison_id,
            'is_synthetic' => (bool) $response->is_synthetic,
            'prep_time_baseline_minutes' => $response->prep_time_baseline_minutes,
            'prep_time_ai_minutes' => $response->prep_time_ai_minutes,
            'relative_time_saving' => (float) $response->relative_time_saving,
            'relative_time_saving_percent' => round((float) $response->relative_time_saving * 100, 2),
            'personalization_selected' => $response->personalization_selected,
            'manual_edit_percentage' => (float) $response->manual_edit_percentage,
            'cultural_context_used' => $response->cultural_context_used,
            'cultural_usefulness_score' => $response->cultural_usefulness_score,
            'feedback_text' => $response->feedback_text,
            'created_at' => $response->created_at->toIso8601String(),
        ];
    }

    protected function authorizePilotAdmin(Request $request, School $school): void
    {
        $user = $request->user();
        if (! $user || ! $user->is_active) {
            throw new AccessDeniedHttpException('Требуется авторизация активного пользователя.');
        }

        if ($user->isSystemAdmin()) {
            return;
        }

        if ($user->isCurator()) {
            if ($user->curator_scope === 'regional') {
                $allowedRegions = $user->curated_region_codes ?? [];
                if (! in_array($school->region_code, $allowedRegions, true)) {
                    throw new AccessDeniedHttpException("Куратор региона не имеет доступа к анкетам школы региона '{$school->region_code}'.");
                }
            }

            return;
        }

        if ($user->hasSchoolRole($school->id, 'school_admin')) {
            return;
        }

        throw new AccessDeniedHttpException('Доступ к анкетам пилота разрешен только администраторам школы, кураторам и системным администраторам.');
    }
}
