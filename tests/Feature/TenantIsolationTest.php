<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Services\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use DatabaseTransactions;

    protected School $schoolA;
    protected School $schoolB;
    protected School $schoolC;

    protected User $teacherA;
    protected User $teacherB;
    protected User $multiTeacher;
    protected User $systemAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        // Create Schools
        $this->schoolA = School::factory()->create(['name' => 'Лицей № 130']);
        $this->schoolB = School::factory()->create(['name' => 'Гимназия № 9']);
        $this->schoolC = School::factory()->create(['name' => 'Школа № 1']);

        // Teacher of School A
        $this->teacherA = User::factory()->create(['email' => 'teacherA@school.ru']);
        SchoolMembership::factory()->create([
            'school_id' => $this->schoolA->id,
            'user_id' => $this->teacherA->id,
            'role' => 'teacher',
            'is_default' => true,
        ]);

        // Teacher of School B
        $this->teacherB = User::factory()->create(['email' => 'teacherB@school.ru']);
        SchoolMembership::factory()->create([
            'school_id' => $this->schoolB->id,
            'user_id' => $this->teacherB->id,
            'role' => 'teacher',
            'is_default' => true,
        ]);

        // User with memberships in both School A and School B
        $this->multiTeacher = User::factory()->create(['email' => 'multi@school.ru']);
        SchoolMembership::factory()->create([
            'school_id' => $this->schoolA->id,
            'user_id' => $this->multiTeacher->id,
            'role' => 'teacher',
            'is_default' => true,
        ]);
        SchoolMembership::factory()->create([
            'school_id' => $this->schoolB->id,
            'user_id' => $this->multiTeacher->id,
            'role' => 'teacher',
            'is_default' => false,
        ]);

        // System Admin without school memberships
        $this->systemAdmin = User::factory()->systemAdmin()->create(['email' => 'admin@system.ru']);
    }

    /**
     * Test Teacher of School A automatically resolves to School A.
     */
    public function test_teacher_of_school_a(): void
    {
        $response = $this->actingAs($this->teacherA, 'web')
            ->getJson('/api/v1/tenant/current');

        $response->assertStatus(200)
            ->assertJsonPath('data.school_id', $this->schoolA->id)
            ->assertJsonPath('data.role', 'teacher')
            ->assertHeader('X-School-Id', $this->schoolA->id);
    }

    /**
     * Test Teacher of School B automatically resolves to School B.
     */
    public function test_teacher_of_school_b(): void
    {
        $response = $this->actingAs($this->teacherB, 'web')
            ->getJson('/api/v1/tenant/current');

        $response->assertStatus(200)
            ->assertJsonPath('data.school_id', $this->schoolB->id)
            ->assertJsonPath('data.role', 'teacher')
            ->assertHeader('X-School-Id', $this->schoolB->id);
    }

    /**
     * Test multi-school teacher defaults to default school when no header is passed.
     */
    public function test_multi_school_user_defaults_to_default_school(): void
    {
        $response = $this->actingAs($this->multiTeacher, 'web')
            ->getJson('/api/v1/tenant/current');

        $response->assertStatus(200)
            ->assertJsonPath('data.school_id', $this->schoolA->id)
            ->assertHeader('X-School-Id', $this->schoolA->id);
    }

    /**
     * Test multi-school teacher can explicitly switch school using X-School-Id.
     */
    public function test_multi_school_user_switches_school_via_header(): void
    {
        $response = $this->actingAs($this->multiTeacher, 'web')
            ->withHeader('X-School-Id', $this->schoolB->id)
            ->getJson('/api/v1/tenant/current');

        $response->assertStatus(200)
            ->assertJsonPath('data.school_id', $this->schoolB->id)
            ->assertHeader('X-School-Id', $this->schoolB->id);
    }

    /**
     * Test passing foreign X-School-Id where user has no membership returns 403.
     */
    public function test_foreign_school_id_is_forbidden(): void
    {
        $response = $this->actingAs($this->teacherA, 'web')
            ->withHeader('X-School-Id', $this->schoolB->id)
            ->getJson('/api/v1/tenant/current');

        $response->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN_TENANT_ACCESS');
    }

    /**
     * Test passing non-existent X-School-Id returns 404 SCHOOL_NOT_FOUND.
     */
    public function test_non_existent_school_id_returns_404(): void
    {
        $nonExistentUuid = '018d9b1c-0000-7000-a000-000000000000';

        $response = $this->actingAs($this->teacherA, 'web')
            ->withHeader('X-School-Id', $nonExistentUuid)
            ->getJson('/api/v1/tenant/current');

        $response->assertStatus(404)
            ->assertJsonPath('error.code', 'SCHOOL_NOT_FOUND');
    }

    /**
     * Test PostgreSQL database rejects two default memberships via partial unique index.
     */
    public function test_two_default_memberships_rejected_by_database(): void
    {
        $user = User::factory()->create();

        SchoolMembership::create([
            'school_id' => $this->schoolA->id,
            'user_id' => $user->id,
            'role' => 'teacher',
            'is_default' => true,
        ]);

        $this->expectException(QueryException::class);

        // This must fail because uq_user_default_school prevents duplicate is_default = true
        SchoolMembership::create([
            'school_id' => $this->schoolB->id,
            'user_id' => $user->id,
            'role' => 'teacher',
            'is_default' => true,
        ]);
    }

    /**
     * Test user with multiple schools and no default returns 422 SCHOOL_SELECTION_REQUIRED.
     */
    public function test_multiple_schools_without_default_requires_selection(): void
    {
        $user = User::factory()->create();

        SchoolMembership::create([
            'school_id' => $this->schoolA->id,
            'user_id' => $user->id,
            'role' => 'teacher',
            'is_default' => false,
        ]);

        SchoolMembership::create([
            'school_id' => $this->schoolB->id,
            'user_id' => $user->id,
            'role' => 'teacher',
            'is_default' => false,
        ]);

        $response = $this->actingAs($user, 'web')
            ->getJson('/api/v1/tenant/current');

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'SCHOOL_SELECTION_REQUIRED');
    }

    /**
     * Test passing school_id in request body is rejected with 422 TENANT_INJECTION_FORBIDDEN.
     */
    public function test_school_id_body_injection_rejected(): void
    {
        $response = $this->actingAs($this->teacherA, 'web')
            ->postWithCsrf('/api/v1/test/tenant/action', [
                'school_id' => $this->schoolB->id,
                'action_data' => 'test',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'TENANT_INJECTION_FORBIDDEN');
    }

    /**
     * Test passing nested school_id in request body is recursively rejected with 422.
     */
    public function test_school_id_nested_body_injection_rejected(): void
    {
        $response = $this->actingAs($this->teacherA, 'web')
            ->postWithCsrf('/api/v1/test/tenant/action', [
                'payload' => [
                    'deep' => [
                        'school_id' => $this->schoolB->id,
                    ],
                ],
                'action_data' => 'nested_test',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'TENANT_INJECTION_FORBIDDEN');
    }

    /**
     * Test regular user without memberships is rejected with 403 NO_ACTIVE_SCHOOL_MEMBERSHIP.
     */
    public function test_user_without_memberships(): void
    {
        $userWithoutSchools = User::factory()->create([
            'email' => 'lonely@teacher.ru',
            'system_role' => 'none',
        ]);

        $response = $this->actingAs($userWithoutSchools, 'web')
            ->getJson('/api/v1/tenant/current');

        $response->assertStatus(403)
            ->assertJsonPath('error.code', 'NO_ACTIVE_SCHOOL_MEMBERSHIP');
    }

    /**
     * Test system user without memberships cannot access school tenant endpoints.
     */
    public function test_system_user_denied_school_tenant(): void
    {
        $response = $this->actingAs($this->systemAdmin, 'web')
            ->getJson('/api/v1/tenant/current');

        $response->assertStatus(403)
            ->assertJsonPath('error.code', 'SYSTEM_USER_TENANT_FORBIDDEN');
    }

    /**
     * Test system user is authorized on explicit system routes, while teachers are denied.
     */
    public function test_system_user_allowed_system_route(): void
    {
        // 1. System admin allowed
        $responseAdmin = $this->actingAs($this->systemAdmin, 'web')
            ->getJson('/api/v1/system/status');

        $responseAdmin->assertStatus(200)
            ->assertJsonPath('data.system_status', 'operational')
            ->assertJsonPath('data.system_role', 'system_admin');

        // 2. Regular teacher denied access to system routes
        auth()->forgetGuards();
        $this->flushSession();
        $this->cookies = [];

        $responseTeacher = $this->actingAs($this->teacherA, 'web')
            ->getJson('/api/v1/system/status');

        $responseTeacher->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN_SYSTEM_ACCESS');
    }

    /**
     * Test tenant context is properly cleared by middleware terminate lifecycle between requests.
     */
    public function test_context_isolation_between_requests(): void
    {
        // Request 1 as Teacher A
        $this->actingAs($this->teacherA, 'web')
            ->getJson('/api/v1/tenant/current')
            ->assertStatus(200)
            ->assertJsonPath('data.school_id', $this->schoolA->id);

        // Reset session state (NO manual TenantContext::clear() call - testing real lifecycle)
        $this->flushSession();
        $this->cookies = [];
        auth()->forgetGuards();

        // Request 2 as Teacher B
        $this->actingAs($this->teacherB, 'web')
            ->getJson('/api/v1/tenant/current')
            ->assertStatus(200)
            ->assertJsonPath('data.school_id', $this->schoolB->id);
    }
}
