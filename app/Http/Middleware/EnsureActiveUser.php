<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveUser
{
    /**
     * Handle an incoming request for authenticated users.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user) {
            $isActive = $user->fresh()?->is_active ?? $user->is_active;

            if (! $isActive) {
                // Invalidate session immediately to prevent further active session usage
                if ($request->hasSession()) {
                    $request->session()->invalidate();
                    $request->session()->regenerateToken();
                }

                Auth::guard('web')->logout();
                auth()->forgetGuards();
                $request->setUserResolver(fn () => null);

                $token = method_exists($user, 'currentAccessToken') ? $user->currentAccessToken() : null;
                if ($token instanceof \Laravel\Sanctum\PersonalAccessToken) {
                    $token->delete();
                }

                $traceId = (string) $request->attributes->get('trace_id');

                return response()->json([
                    'error' => [
                        'code' => 'ACCOUNT_INACTIVE',
                        'message' => 'Учетная запись деактивирована.',
                        'details' => [],
                        'trace_id' => $traceId,
                    ],
                ], 403);
            }
        }

        return $next($request);
    }
}
