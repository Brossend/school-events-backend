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

        // 1. If explicit X-School-Id is provided, validate strictly against user memberships
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

    /**
     * Resolve school context specifically for analytical and curator operations.
     * Enforces curator regional boundaries and system admin privileges without granting
     * operational access to standard school tenant routes.
     *
     * @throws HttpResponseException
     */
    public function resolveAnalyticalSchool(Request $request, User $user): School
    {
        $traceId = (string) $request->attributes->get('trace_id');
        $requestedSchoolId = $request->header('X-School-Id');

        if ($requestedSchoolId) {
            $school = School::where('id', $requestedSchoolId)->first();

            if (! $school) {
                throw new HttpResponseException(response()->json([
                    'error' => [
                        'code' => 'SCHOOL_NOT_FOUND',
                        'message' => 'Указанная образовательная организация не найдена.',
                        'details' => [],
                        'trace_id' => $traceId,
                    ],
                ], 404));
            }

            // 1. System admin has global analytical access
            if ($user->isSystemAdmin()) {
                return $school;
            }

            // 2. Catalog curator: verify regional scope
            if ($user->isCurator()) {
                if ($user->curator_scope !== 'regional') {
                    return $school;
                }
                $allowedRegions = $user->curated_region_codes ?? [];
                if (in_array($school->region_code, $allowedRegions, true)) {
                    return $school;
                }

                throw new HttpResponseException(response()->json([
                    'error' => [
                        'code' => 'FORBIDDEN_TENANT_ACCESS',
                        'message' => "Куратор региона не имеет доступа к показателям школы региона '{$school->region_code}'.",
                        'details' => [],
                        'trace_id' => $traceId,
                    ],
                ], 403));
            }

            // 3. Regular users: must have active membership in this school
            $hasMembership = $user->memberships()->where('school_id', $school->id)->exists();
            if ($hasMembership) {
                return $school;
            }

            throw new HttpResponseException(response()->json([
                'error' => [
                    'code' => 'FORBIDDEN_TENANT_ACCESS',
                    'message' => 'Вы не являетесь участником указанной школы.',
                    'details' => [],
                    'trace_id' => $traceId,
                ],
            ], 403));
        }

        // No X-School-Id provided: check if user has school memberships
        $memberships = $user->memberships()->with('school')->get();

        if ($memberships->isEmpty()) {
            throw new HttpResponseException(response()->json([
                'error' => [
                    'code' => 'SCHOOL_SELECTION_REQUIRED',
                    'message' => 'Для просмотра аналитических данных школы передайте заголовок X-School-Id.',
                    'details' => [],
                    'trace_id' => $traceId,
                ],
            ], 422));
        }

        if ($memberships->count() === 1) {
            $singleSchool = $memberships->first()->school;
            if ($singleSchool) {
                return $singleSchool;
            }
        }

        $defaultMembership = $memberships->firstWhere('is_default', true);
        if ($defaultMembership && $defaultMembership->school) {
            return $defaultMembership->school;
        }

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
