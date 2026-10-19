<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PreventTenantInjection
{
    /**
     * Prevent tenant injection in request body across all API endpoints.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->containsSchoolId($request->all()) || $this->containsSchoolId($request->json()->all())) {
            return response()->json([
                'error' => [
                    'code' => 'TENANT_INJECTION_FORBIDDEN',
                    'message' => 'Передача school_id в теле запроса запрещена. Используйте заголовок X-School-Id.',
                    'details' => [],
                    'trace_id' => (string) $request->attributes->get('trace_id'),
                ],
            ], 422);
        }

        return $next($request);
    }

    /**
     * Recursively inspect array payload for any 'school_id' keys.
     */
    protected function containsSchoolId(mixed $data): bool
    {
        if (! is_array($data)) {
            return false;
        }

        foreach ($data as $key => $value) {
            if ($key === 'school_id') {
                return true;
            }
            if (is_array($value) && $this->containsSchoolId($value)) {
                return true;
            }
        }

        return false;
    }
}
