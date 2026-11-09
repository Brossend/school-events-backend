<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\SchoolInvitation;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class RegistrationController extends Controller
{
    public function store(RegisterRequest $request): JsonResponse
    {
        $data = $request->validated();
        try {
            return DB::transaction(function () use ($data) {
                $invitation = SchoolInvitation::where('code_hash', hash('sha256', $data['invitation_code']))->lockForUpdate()->first();
                if (! $invitation || $invitation->revoked_at || ! $invitation->expires_at->isFuture() || $invitation->role !== 'teacher'
                    || ($invitation->email && $invitation->email !== $data['email'])) {
                    return ApiResponse::error('INVITATION_INVALID', 'Код не подходит или срок приглашения истёк. Проверьте почту и попросите администратора выдать новое приглашение.', [], 422);
                }
                if ($invitation->used_at) {
                    return ApiResponse::error('REGISTRATION_CONFLICT', 'Регистрацию нельзя завершить. Попробуйте войти или обратитесь к администратору школы.', [], 409);
                }
                // Serialize registrations of the same normalized email across different invitations.
                DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$data['email']]);
                if (User::whereRaw('lower(email) = ?', [$data['email']])->exists()) {
                    return ApiResponse::error('REGISTRATION_CONFLICT', 'Регистрацию нельзя завершить. Попробуйте войти или обратитесь к администратору школы.', [], 409);
                }
                $user = User::create(['name' => trim($data['name']), 'email' => $data['email'], 'password' => $data['password'],
                    'system_role' => 'none', 'is_active' => true]);
                SchoolMembership::create(['school_id' => $invitation->school_id, 'user_id' => $user->id, 'role' => 'teacher', 'is_default' => true]);
                $invitation->used_at = now();
                $invitation->save();

                return ApiResponse::success(['registered' => true], status: 201)->header('Cache-Control', 'no-store');
            });
        } catch (QueryException $exception) {
            if ($exception->getCode() !== '23505') {
                throw $exception;
            }

            return ApiResponse::error('REGISTRATION_CONFLICT', 'Регистрацию нельзя завершить. Попробуйте войти или обратитесь к администратору школы.', [], 409);
        }
    }
}
