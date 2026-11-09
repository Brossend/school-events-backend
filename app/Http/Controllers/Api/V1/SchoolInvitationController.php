<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Schools\StoreSchoolInvitationRequest;
use App\Models\School;
use App\Models\SchoolInvitation;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class SchoolInvitationController extends Controller
{
    public function index(Request $request, string $schoolId): JsonResponse
    {
        Gate::authorize('manage-school-invitations', School::findOrFail($schoolId));
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);
        $page = SchoolInvitation::where('school_id', $schoolId)->orderByDesc('created_at')->orderByDesc('id')->paginate(20);

        return ApiResponse::success(['items' => $page->map(fn ($item) => $item->publicData())->all(),
            'current_page' => $page->currentPage(), 'last_page' => $page->lastPage()])->header('Cache-Control', 'no-store');
    }

    public function store(StoreSchoolInvitationRequest $request, string $schoolId): JsonResponse
    {
        $code = bin2hex(random_bytes(32));
        $invitation = SchoolInvitation::create(['school_id' => $schoolId, 'role' => 'teacher',
            'email' => $request->validated('email'), 'code_hash' => hash('sha256', $code),
            'expires_at' => now()->addDays(7), 'created_by' => $request->user()->id]);

        return ApiResponse::success(['invitation' => $invitation->publicData(), 'code' => $code], status: 201)->header('Cache-Control', 'no-store');
    }

    public function revoke(Request $request, string $schoolId, string $invitationId): JsonResponse
    {
        Gate::authorize('manage-school-invitations', School::findOrFail($schoolId));

        return DB::transaction(function () use ($schoolId, $invitationId) {
            $invitation = SchoolInvitation::where('school_id', $schoolId)->whereKey($invitationId)->lockForUpdate()->firstOrFail();
            if ($invitation->used_at) {
                return ApiResponse::error('INVITATION_USED', 'Приглашение уже использовано. Членство педагога не изменено.', [], 409);
            }
            if (! $invitation->revoked_at) {
                $invitation->revoked_at = now();
                $invitation->save();
            }

            return ApiResponse::success($invitation->publicData())->header('Cache-Control', 'no-store');
        });
    }
}
