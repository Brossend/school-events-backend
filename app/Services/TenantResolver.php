<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\School;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;

class TenantResolver
{
    /**
     * Resolve the active school tenant for the request with strict security invariants.
     *
     * Invariants enforced:
     * 1. X-School-Id header must belong to the user's active memberships.
     * 2. Invalid or foreign X-School-Id NEVER silently falls back to a default school.
     * 3. Multiple memberships without a default school require explicit selection (422 SCHOOL_SELECTION_REQUIRED).
     * 4. System users without school memberships are allowed only when $allowSystemWithoutSchool is true.
     *
     * @throws HttpResponseException
     */
    public function resolve(
        Request $request,
        User $user,
        TenantContext $tenantContext,
        bool $allowSystemWithoutSchool = false
    ): ?School {
        $traceId = (string) $request->attributes->get('trace_id');
        $requestedSchoolId = $request->header('X-School-Id');

        // 1. If explicit X-School-Id is provided, validate strictly
        if ($requestedSchoolId) {
            $membership = $user->memberships()->with('school')->where('school_id', $requestedSchoolId)->first();

            if ($membership && $membership->school) {
                $tenantContext->set($membership->school, $membership);
                return $membership->school;
            }

            // Check if school exists at all
            $schoolExists = School::where('id', $requestedSchoolId)->exists();

            if ($schoolExists) {
                throw new HttpResponseException(response()->json([
                    'error' => [
                        'code' => 'FORBIDDEN_TENANT_ACCESS',
                        'message' => 'Вы не являетесь участником указанной школы.',
                        'details' => [],
                        'trace_id' => $traceId,
                    ],
                ], 403));
            }

            throw new HttpResponseException(response()->json([
                'error' => [
                    'code' => 'SCHOOL_NOT_FOUND',
                    'message' => 'Указанная образовательная организация не найдена.',
                    'details' => [],
                    'trace_id' => $traceId,
                ],
            ], 404));
        }

        // 2. No header provided: handle system users
        $memberships = $user->memberships()->with('school')->get();

        if ($memberships->isEmpty()) {
            if ($user->system_role !== 'none') {
                if ($allowSystemWithoutSchool) {
                    return null;
                }

                throw new HttpResponseException(response()->json([
                    'error' => [
                        'code' => 'SYSTEM_USER_TENANT_FORBIDDEN',
                        'message' => 'Системные пользователи без членства в школе не имеют доступа к школьным ресурсам.',
                        'details' => [],
                        'trace_id' => $traceId,
                    ],
                ], 403));
            }

            throw new HttpResponseException(response()->json([
                'error' => [
                    'code' => 'NO_ACTIVE_SCHOOL_MEMBERSHIP',
                    'message' => 'У вас нет активного членства ни в одной образовательной организации.',
                    'details' => [],
                    'trace_id' => $traceId,
                ],
            ], 403));
        }

        // 3. User has single membership
        if ($memberships->count() === 1) {
            $single = $memberships->first();
            $tenantContext->set($single->school, $single);
            return $single->school;
        }

        // 4. User has multiple memberships: check for default
        $defaultMembership = $memberships->firstWhere('is_default', true);

        if ($defaultMembership && $defaultMembership->school) {
            $tenantContext->set($defaultMembership->school, $defaultMembership);
            return $defaultMembership->school;
        }

        // 5. Multiple memberships without default: explicit selection strictly required
        throw new HttpResponseException(response()->json([
            'error' => [
                'code' => 'SCHOOL_SELECTION_REQUIRED',
                'message' => 'У вас несколько школ без установленной школы по умолчанию. Передайте заголовок X-School-Id.',
                'details' => [],
                'trace_id' => $traceId,
            ],
        ], 422));
    }
}
