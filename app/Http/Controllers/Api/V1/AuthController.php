<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    /**
     * Authenticate user and initiate stateful SPA session.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $user = User::where('email', $validated['email'])->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            return ApiResponse::error(
                code: 'INVALID_CREDENTIALS',
                message: 'Неверный адрес электронной почты или пароль.',
                details: [
                    [
                        'field' => 'email',
                        'message' => 'Неверные учетные данные.',
                    ],
                ],
                status: 422
            );
        }

        if (! $user->is_active) {
            return ApiResponse::error(
                code: 'ACCOUNT_INACTIVE',
                message: 'Учетная запись деактивирована.',
                details: [],
                status: 403
            );
        }

        // Authenticate into web guard and regenerate session ID
        Auth::guard('web')->setRememberDuration(60 * 24 * 30);
        Auth::guard('web')->login($user, (bool) ($validated['remember'] ?? false));
        if (! ($validated['remember'] ?? false)) {
            Cookie::queue(Cookie::forget(Auth::guard('web')->getRecallerName()));
        }
        $request->session()->regenerate();

        return $this->formatUserResponse($user);
    }

    /**
     * Terminate user session and invalidate tokens.
     */
    public function logout(Request $request): Response
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    /**
     * Get current authenticated user profile.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return ApiResponse::error(
                code: 'UNAUTHORIZED',
                message: 'Требуется авторизация.',
                details: [],
                status: 401
            );
        }

        if (! $user->is_active) {
            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }
            Auth::guard('web')->logout();

            return ApiResponse::error(
                code: 'ACCOUNT_INACTIVE',
                message: 'Учетная запись деактивирована.',
                details: [],
                status: 403
            );
        }

        return $this->formatUserResponse($user);
    }

    /**
     * Format user profile and memberships payload.
     */
    protected function formatUserResponse(User $user): JsonResponse
    {
        $memberships = $user->memberships()->with('school')->get();

        $schools = $memberships->map(function ($membership) {
            return [
                'school_id' => $membership->school_id,
                'name' => $membership->school?->name,
                'role' => $membership->role,
                'is_default' => $membership->is_default,
            ];
        });

        return ApiResponse::success([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'system_role' => $user->system_role,
            ],
            'schools' => $schools,
        ]);
    }
}
