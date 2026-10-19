<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CalendarEventController;
use App\Http\Controllers\Api\V1\SchoolTraditionController;
use App\Http\Controllers\HealthController;
use App\Services\TenantContext;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// 1. System healthcheck under /api/v1/health
Route::get('/health', [HealthController::class, 'check']);

// 2. Authentication routes under /api/v1/auth
Route::prefix('auth')->group(function () {
    // Login with rate limiting: 5 attempts per minute per lowercase email + IP
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');

    // Authenticated SPA session routes (user profile scope, no school tenant required)
    Route::middleware(['auth:sanctum', 'active'])->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
    });
});

// 3. System administrative routes (for system_admin and catalog_curator without school memberships)
Route::prefix('system')->middleware(['auth:sanctum', 'active'])->group(function () {
    Route::get('/status', function (Request $request) {
        $user = $request->user();
        if ($user->system_role === 'none') {
            return response()->json([
                'error' => [
                    'code' => 'FORBIDDEN_SYSTEM_ACCESS',
                    'message' => 'Доступ разрешен только системным администраторам и кураторам.',
                    'details' => [],
                    'trace_id' => (string) $request->attributes->get('trace_id'),
                ],
            ], 403);
        }

        return ApiResponse::success([
            'system_status' => 'operational',
            'system_role' => $user->system_role,
        ]);
    });
});

// 4. Calendar & Cultural Catalog routes (available to authenticated users, curators, admins)
Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::get('/calendar-events', [CalendarEventController::class, 'index']);
    Route::post('/calendar-events', [CalendarEventController::class, 'store']);
    Route::get('/calendar-events/{id}', [CalendarEventController::class, 'show']);
});

// 5. Authenticated school tenant routes (strictly requires active school membership & TenantContext)
Route::middleware(['auth:sanctum', 'active', 'tenant'])->group(function () {
    Route::get('/tenant/current', function (TenantContext $tenantContext) {
        return ApiResponse::success([
            'school_id' => $tenantContext->getSchoolId(),
            'school_name' => $tenantContext->getSchool()?->name,
            'role' => $tenantContext->getRole(),
        ]);
    });

    // School Traditions
    Route::get('/schools/{schoolId}/traditions', [SchoolTraditionController::class, 'listBySchool']);
    Route::post('/schools/{schoolId}/traditions', [SchoolTraditionController::class, 'store']);
    Route::post('/traditions/{id}/versions', [SchoolTraditionController::class, 'createVersion']);
});

// 5. Test harness routes (available strictly in local and testing environments)
if (app()->environment('local', 'testing')) {
    Route::prefix('test')->middleware(['auth:sanctum', 'active', 'tenant'])->group(function () {
        Route::post('/tenant/action', function () {
            return ApiResponse::success(['status' => 'action_processed']);
        });
    });
}
