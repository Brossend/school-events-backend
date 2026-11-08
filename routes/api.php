<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CalendarEventController;
use App\Http\Controllers\Api\V1\CatalogReviewController;
use App\Http\Controllers\Api\V1\CulturalClaimController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\EventSourceController;
use App\Http\Controllers\Api\V1\GenerationRunController;
use App\Http\Controllers\Api\V1\PilotMetricsController;
use App\Http\Controllers\Api\V1\PilotParticipantController;
use App\Http\Controllers\Api\V1\PilotResponseController;
use App\Http\Controllers\Api\V1\RevisionModerationController;
use App\Http\Controllers\Api\V1\ScenarioComparisonController;
use App\Http\Controllers\Api\V1\ScenarioController;
use App\Http\Controllers\Api\V1\ScenarioExportController;
use App\Http\Controllers\Api\V1\ScenarioRequestController;
use App\Http\Controllers\Api\V1\ScenarioRevisionController;
use App\Http\Controllers\Api\V1\SchoolProfileController;
use App\Http\Controllers\Api\V1\SchoolTraditionController;
use App\Http\Controllers\Api\V1\TeacherSignoffController;
use App\Http\Controllers\HealthController;
use App\Services\TenantContext;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

// 1. System healthcheck under /api/v1/health
Route::get('/health', [HealthController::class, 'check']);

// 2. Authentication routes under /api/v1/auth
Route::prefix('auth')->group(function () {
    // Login with rate limiting: 5 attempts per minute per lowercase email + IP
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login')->name('login');

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

    // BE-006: Cultural Catalog Curator Review & Evidence Management
    Route::get('/catalog/review-queue', [CatalogReviewController::class, 'index']);
    Route::get('/event-sources/{id}', [EventSourceController::class, 'show']);
    Route::patch('/event-sources/{id}', [EventSourceController::class, 'update']);
    Route::get('/event-sources/{id}/reviews', [EventSourceController::class, 'listReviews']);
    Route::post('/event-sources/{id}/reviews', [EventSourceController::class, 'storeReview']);

    Route::get('/cultural-claims/{id}', [CulturalClaimController::class, 'show']);
    Route::post('/cultural-claims/{id}/evidence', [CulturalClaimController::class, 'attachEvidence']);
    Route::delete('/cultural-claims/{id}/evidence/{sourceId}', [CulturalClaimController::class, 'detachEvidence']);

    // School Profile
    Route::get('/schools/{id}', [SchoolProfileController::class, 'show']);
    Route::patch('/schools/{id}', [SchoolProfileController::class, 'update']);

    // BE-008: Pilot Expert Review Findings & System Metrics
    Route::get('/pilot/responses/{id}', [PilotResponseController::class, 'show']);
    Route::get('/pilot/responses/{id}/reviews', [PilotResponseController::class, 'reviews']);
    Route::post('/pilot/responses/{id}/reviews', [PilotResponseController::class, 'submitReview']);
    Route::get('/pilot/metrics', [PilotMetricsController::class, 'summary']);
    Route::get('/pilot/export', [PilotMetricsController::class, 'export']);
});

// 4.1. Public Pilot Response Submission (Token-based invitation)
Route::post('/pilot/responses', [PilotResponseController::class, 'store']);

// 5. Authenticated school tenant routes (strictly requires active school membership & TenantContext)
Route::middleware(['auth:sanctum', 'active', 'tenant'])->group(function () {
    Route::get('/tenant/current', function (TenantContext $tenantContext) {
        return ApiResponse::success([
            'school_id' => $tenantContext->getSchoolId(),
            'school_name' => $tenantContext->getSchool()?->name,
            'role' => $tenantContext->getRole(),
        ]);
    });

    // Dashboard summary
    Route::get('/dashboard/summary', [DashboardController::class, 'summary']);

    // School Traditions
    Route::get('/schools/{schoolId}/traditions', [SchoolTraditionController::class, 'listBySchool']);
    Route::post('/schools/{schoolId}/traditions', [SchoolTraditionController::class, 'store']);
    Route::get('/traditions/{id}', [SchoolTraditionController::class, 'show']);
    Route::patch('/traditions/{id}', [SchoolTraditionController::class, 'update']);
    Route::post('/traditions/{id}/archive', [SchoolTraditionController::class, 'archive']);
    Route::post('/traditions/{id}/versions', [SchoolTraditionController::class, 'createVersion']);

    // BE-003: Scenario Constructor & Asynchronous Generation
    Route::post('/scenario-requests', [ScenarioRequestController::class, 'store']);
    Route::get('/scenario-requests/{id}', [ScenarioRequestController::class, 'show']);
    Route::post('/scenario-requests/{id}/generate', [ScenarioRequestController::class, 'generate']);

    Route::get('/generation-runs/{id}', [GenerationRunController::class, 'show']);

    Route::get('/scenarios', [ScenarioController::class, 'index']);
    Route::get('/scenarios/{id}', [ScenarioController::class, 'show']);
    Route::post('/scenarios/{id}/archive', [ScenarioController::class, 'archive']);
    Route::post('/scenarios/{id}/restore', [ScenarioController::class, 'restore']);
    Route::delete('/scenarios/{id}', [ScenarioController::class, 'destroy']);

    // BE-004: Moderation Engine, Revision Editor, Human Sign-Off & PDF Export
    Route::get('/scenarios/{id}/revisions/{version}', [ScenarioRevisionController::class, 'show']);
    Route::get('/scenarios/{id}/revisions/{version}/verifications', [RevisionModerationController::class, 'getVerifications']);
    Route::post('/scenarios/{id}/revisions/{version}/verify', [RevisionModerationController::class, 'verify']);
    Route::patch('/scenarios/{id}/revisions/{version}', [ScenarioRevisionController::class, 'update']);
    Route::post('/scenarios/{id}/revisions/{version}/signoff', [TeacherSignoffController::class, 'signoff']);
    Route::get('/scenarios/{id}/revisions/{version}/exports', [ScenarioExportController::class, 'listExports']);
    Route::post('/scenarios/{id}/revisions/{version}/exports', [ScenarioExportController::class, 'requestExport']);

    Route::get('/export-jobs/{id}', [ScenarioExportController::class, 'showJob']);
    Route::get('/export-jobs/{id}/download', [ScenarioExportController::class, 'download']);

    // BE-008: Scenario Comparisons (A/B testing)
    Route::get('/scenario-comparisons', [ScenarioComparisonController::class, 'index']);
    Route::post('/scenario-comparisons', [ScenarioComparisonController::class, 'store']);
    Route::get('/scenario-comparisons/{id}', [ScenarioComparisonController::class, 'show']);
    Route::get('/scenario-comparisons/{id}/variants', [ScenarioComparisonController::class, 'variants']);
    Route::post('/scenario-comparisons/{id}/selection', [ScenarioComparisonController::class, 'selection']);

    // BE-008: Pilot Study Participants & Metrics
    Route::get('/pilot/participants', [PilotParticipantController::class, 'index']);
    Route::post('/pilot/participants', [PilotParticipantController::class, 'store']);
    Route::post('/pilot/participants/{id}/reissue', [PilotParticipantController::class, 'reissue']);
    Route::post('/pilot/participants/{id}/revoke', [PilotParticipantController::class, 'revoke']);

});

// 5. Test harness routes (strictly restricted to dedicated test contour APP_ENV=testing; never in local or production)
if (app()->environment('testing') && ! app()->environment('local') && ! app()->isProduction()) {
    Route::prefix('test')->group(function () {
        Route::get('/environment-probe', function () {
            if (! app()->environment('testing') || app()->environment('local') || app()->isProduction()) {
                abort(404);
            }

            $actualDb = DB::selectOne('SELECT current_database() as db')->db;

            return response()->json([
                'app_name' => config('app.name'),
                'environment' => app()->environment(),
                'database' => $actualDb,
                'configured_database' => config('database.connections.pgsql.database'),
                'hostname' => gethostname(),
                'server_port' => request()->server('SERVER_PORT'),
            ]);
        });

        Route::middleware(['auth:sanctum', 'active', 'tenant'])->group(function () {
            Route::post('/tenant/action', function () {
                if (! app()->environment('testing') || app()->environment('local') || app()->isProduction()) {
                    abort(404);
                }

                return ApiResponse::success(['status' => 'action_processed']);
            });
        });
    });
}
