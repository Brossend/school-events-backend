<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AssignTraceId
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // 1. Authoritative server-generated ordered UUIDv7 trace ID
        $serverTraceId = 'req-'.(string) Str::uuid7();

        // 2. Validate and sanitize optional client-provided trace/request ID
        $clientHeader = $request->header('X-Trace-Id') ?: $request->header('X-Request-Id');
        $validClientId = null;
        if ($clientHeader && is_string($clientHeader) && preg_match('/^[a-zA-Z0-9_\-\.]{8,64}$/', $clientHeader)) {
            $validClientId = $clientHeader;
        }

        $request->attributes->set('trace_id', $serverTraceId);
        if ($validClientId) {
            $request->attributes->set('client_request_id', $validClientId);
        }

        // 3. Bind into Monolog context for structured logging
        Log::withContext(array_filter([
            'trace_id' => $serverTraceId,
            'client_request_id' => $validClientId,
        ]));

        $response = $next($request);

        // 4. Return authoritative server trace ID
        $response->headers->set('X-Trace-Id', $serverTraceId);

        return $response;
    }
}
