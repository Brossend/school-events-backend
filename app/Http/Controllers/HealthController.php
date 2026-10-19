<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

class HealthController extends Controller
{
    /**
     * System healthcheck endpoint.
     * Safely returns 'connected' or 'disconnected' without exposing internal error messages or infrastructure addresses.
     */
    public function check(): JsonResponse
    {
        $status = 'ok';
        $services = [
            'database' => 'connected',
            'redis' => 'connected',
        ];

        // 1. Check PostgreSQL database connection
        try {
            DB::connection()->getPdo();
        } catch (Throwable $e) {
            $status = 'degraded';
            $services['database'] = 'disconnected';
            Log::error('Healthcheck database probe failed', [
                'error' => $e->getMessage(),
            ]);
        }

        // 2. Check Redis connection
        try {
            Redis::connection()->ping();
        } catch (Throwable $e) {
            $status = 'degraded';
            $services['redis'] = 'disconnected';
            Log::error('Healthcheck Redis probe failed', [
                'error' => $e->getMessage(),
            ]);
        }

        $statusCode = ($status === 'ok') ? 200 : 503;

        return response()->json([
            'status' => $status,
            'timestamp' => now()->toIso8601String(),
            'services' => $services,
        ], $statusCode);
    }
}
