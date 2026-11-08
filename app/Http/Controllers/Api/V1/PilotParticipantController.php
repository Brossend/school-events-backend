<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pilot\CreatePilotParticipantRequest;
use App\Models\PilotParticipant;
use App\Models\School;
use App\Services\PilotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PilotParticipantController extends Controller
{
    public function __construct(
        protected PilotService $pilotService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $school = $request->attributes->get('school');
        if (! $school) {
            throw new AccessDeniedHttpException('Требуется контекст образовательной организации (X-School-Id).');
        }

        $this->authorizePilotAdmin($request, $school);

        $participants = PilotParticipant::where('school_id', $school->id)
            ->with('response')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (PilotParticipant $p) => [
                'id' => $p->id,
                'anonymous_code' => $p->anonymous_code,
                'subject_area' => $p->subject_area,
                'teaching_experience_years' => $p->teaching_experience_years,
                'is_submitted' => $p->is_submitted,
                'is_synthetic' => $p->is_synthetic,
                'expires_at' => $p->expires_at->toIso8601String(),
                'is_expired' => $p->isExpired(),
                'is_revoked' => $p->isRevoked(),
                'used_at' => $p->used_at?->toIso8601String(),
                'has_response' => $p->response !== null,
                'response_id' => $p->response?->id,
                'created_at' => $p->created_at->toIso8601String(),
            ]);

        return response()->json([
            'data' => $participants,
        ]);
    }

    public function store(CreatePilotParticipantRequest $request): JsonResponse
    {
        $school = $request->attributes->get('school');
        if (! $school) {
            throw new AccessDeniedHttpException('Требуется контекст образовательной организации (X-School-Id).');
        }

        $this->authorizePilotAdmin($request, $school);

        $result = $this->pilotService->createParticipant($school, $request->validated());
        /** @var PilotParticipant $participant */
        $participant = $result['participant'];

        return response()->json([
            'data' => [
                'id' => $participant->id,
                'anonymous_code' => $participant->anonymous_code,
                'subject_area' => $participant->subject_area,
                'teaching_experience_years' => $participant->teaching_experience_years,
                'is_synthetic' => $participant->is_synthetic,
                'invite_token' => $result['invite_token'],
                'expires_at' => $participant->expires_at->toIso8601String(),
                'notice' => 'Одноразовый токен возвращается ровно один раз. В БД сохраняется только криптографический хэш.',
            ],
        ], 201);
    }

    public function reissue(Request $request, string $id): JsonResponse
    {
        $school = $request->attributes->get('school');
        $participant = PilotParticipant::with('school')->where('id', $id)->first();

        if (! $participant) {
            throw new NotFoundHttpException('Участник пилотного исследования не найден.');
        }

        $targetSchool = $participant->school ?? $school;
        if (! $targetSchool) {
            throw new NotFoundHttpException('Образовательная организация участника не найдена.');
        }

        if ($school && $participant->school_id !== $school->id) {
            throw new AccessDeniedHttpException('Доступ к участнику другой школы запрещен.');
        }

        $this->authorizePilotAdmin($request, $targetSchool);

        $result = $this->pilotService->reissueToken($participant);

        return response()->json([
            'data' => [
                'id' => $participant->id,
                'anonymous_code' => $participant->anonymous_code,
                'invite_token' => $result['invite_token'],
                'expires_at' => $participant->expires_at->toIso8601String(),
                'notice' => 'Новый пригласительный токен сгенерирован.',
            ],
        ]);
    }

    public function revoke(Request $request, string $id): JsonResponse
    {
        $school = $request->attributes->get('school');
        $participant = PilotParticipant::with('school')->where('id', $id)->first();

        if (! $participant) {
            throw new NotFoundHttpException('Участник пилотного исследования не найден.');
        }

        $targetSchool = $participant->school ?? $school;
        if (! $targetSchool) {
            throw new NotFoundHttpException('Образовательная организация участника не найдена.');
        }

        if ($school && $participant->school_id !== $school->id) {
            throw new AccessDeniedHttpException('Доступ к участнику другой школы запрещен.');
        }

        $this->authorizePilotAdmin($request, $targetSchool);

        $revoked = $this->pilotService->revokeParticipant($participant);

        return response()->json([
            'data' => [
                'id' => $revoked->id,
                'anonymous_code' => $revoked->anonymous_code,
                'revoked_at' => $revoked->revoked_at->toIso8601String(),
                'message' => 'Приглашение участника успешно отозвано.',
            ],
        ]);
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
                    throw new AccessDeniedHttpException("Куратор региона не имеет доступа к пилотным данным школы региона '{$school->region_code}'.");
                }
            }

            return;
        }

        if ($user->hasSchoolRole($school->id, 'school_admin')) {
            return;
        }

        throw new AccessDeniedHttpException('Доступ к управлению участниками пилота разрешен только администраторам школы, кураторам и системным администраторам.');
    }
}
