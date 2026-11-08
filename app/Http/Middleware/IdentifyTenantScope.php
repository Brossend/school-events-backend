<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\TenantContext;
use App\Services\TenantResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class IdentifyTenantScope
{
    public function __construct(
        protected TenantContext $tenantContext,
        protected TenantResolver $tenantResolver
    ) {}

    /**
     * Handle an incoming request for school-scoped tenant routes.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $traceId = (string) $request->attributes->get('trace_id');

        // 1. Strictly prevent tenant injection in request body (including nested arrays and JSON)
        if ($this->containsSchoolId($request->all()) || $this->containsSchoolId($request->json()->all())) {
            return response()->json([
                'error' => [
                    'code' => 'TENANT_INJECTION_FORBIDDEN',
                    'message' => 'Передача school_id в теле запроса запрещена. Используйте заголовок X-School-Id.',
                    'details' => [],
                    'trace_id' => $traceId,
                ],
            ], 422);
        }

        // 2. Ensure user is authenticated
        $user = $request->user();
        if (! $user) {
            return response()->json([
                'error' => [
                    'code' => 'UNAUTHORIZED',
                    'message' => 'Требуется авторизация.',
                    'details' => [],
                    'trace_id' => $traceId,
                ],
            ], 401);
        }

        // 3. Reject inactive accounts
        if (! $user->is_active) {
            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }
            \Illuminate\Support\Facades\Auth::guard('web')->logout();

            return response()->json([
                'error' => [
                    'code' => 'ACCOUNT_INACTIVE',
                    'message' => 'Учетная запись деактивирована.',
                    'details' => [],
                    'trace_id' => $traceId,
                ],
            ], 403);
        }

        // 4. Resolve tenant using centralized resolver
        $this->tenantResolver->resolve($request, $user, $this->tenantContext, allowSystemWithoutSchool: false);

        // 5. Hard invariant: Empty TenantContext never grants access to school resources
        if (! $this->tenantContext->hasTenant()) {
            return response()->json([
                'error' => [
                    'code' => 'TENANT_REQUIRED',
                    'message' => 'Доступ к ресурсу требует активного контекста образовательной организации.',
                    'details' => [],
                    'trace_id' => $traceId,
                ],
            ], 403);
        }

        $request->attributes->set('school', $this->tenantContext->getSchool());
        $request->attributes->set('school_id', $this->tenantContext->getSchoolId());

        $response = $next($request);

        // Echo active school id header
        $response->headers->set('X-School-Id', $this->tenantContext->getSchoolId());

        return $response;
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

    /**
     * Terminate request lifecycle and clear tenant context.
     */
    public function terminate(Request $request, Response $response): void
    {
        $this->tenantContext->clear();
    }
}
