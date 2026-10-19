<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

class ApiResponse
{
    /**
     * Create a standard success JSON response envelope.
     */
    public static function success(mixed $data = null, mixed $meta = null, int $status = 200, array $headers = []): JsonResponse
    {
        $payload = ['data' => $data];

        if ($meta !== null) {
            $payload['meta'] = $meta;
        }

        return response()->json($payload, $status, $headers);
    }

    /**
     * Create a standard error JSON response envelope.
     */
    public static function error(string $code, string $message, array $details = [], int $status = 400, ?string $traceId = null): JsonResponse
    {
        $traceId = $traceId ?: request()->attributes->get('trace_id') ?: 'req-system';

        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => $details,
                'trace_id' => $traceId,
            ],
        ], $status);
    }
}
