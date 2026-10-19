<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DatabaseIntegrityTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Test model creates valid UUIDv7 IDs.
     */
    public function test_uuid_v7_generation(): void
    {
        $school = School::factory()->create();
        $user = User::factory()->create();

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $school->id,
            'School ID must be a valid UUIDv7'
        );

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $user->id,
            'User ID must be a valid UUIDv7'
        );
    }

    /**
     * Test CASCADE DELETE on foreign keys.
     */
    public function test_foreign_key_cascade_deletion(): void
    {
        $school = School::factory()->create();
        $user = User::factory()->create();

        $membership = SchoolMembership::create([
            'school_id' => $school->id,
            'user_id' => $user->id,
            'role' => 'teacher',
            'is_default' => true,
        ]);

        $this->assertDatabaseHas('school_memberships', ['id' => $membership->id]);

        // Deleting school cascades to memberships
        $school->delete();
        $this->assertDatabaseMissing('school_memberships', ['id' => $membership->id]);
    }

    /**
     * Test invalid foreign key throws QueryException.
     */
    public function test_invalid_foreign_key_fails(): void
    {
        $user = User::factory()->create();
        $nonExistentSchoolId = '018d9b1c-0000-7000-a000-000000000000';

        $this->expectException(QueryException::class);

        SchoolMembership::create([
            'school_id' => $nonExistentSchoolId,
            'user_id' => $user->id,
            'role' => 'teacher',
            'is_default' => false,
        ]);
    }

    /**
     * Test UNIQUE (school_id, user_id) constraint.
     */
    public function test_unique_school_user_membership(): void
    {
        $school = School::factory()->create();
        $user = User::factory()->create();

        SchoolMembership::create([
            'school_id' => $school->id,
            'user_id' => $user->id,
            'role' => 'teacher',
            'is_default' => false,
        ]);

        $this->expectException(QueryException::class);

        // Attempt duplicate membership for same school and user
        SchoolMembership::create([
            'school_id' => $school->id,
            'user_id' => $user->id,
            'role' => 'school_admin',
            'is_default' => false,
        ]);
    }

    /**
     * Test PostgreSQL CHECK constraint on users.system_role.
     */
    public function test_users_system_role_check_constraint(): void
    {
        $this->expectException(QueryException::class);

        DB::table('users')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid7(),
            'name' => 'Invalid Role User',
            'email' => 'invalid_role@test.ru',
            'password' => 'secret',
            'system_role' => 'super_root', // Not in ('none', 'catalog_curator', 'system_admin')
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Test PostgreSQL CHECK constraint on school_memberships.role.
     */
    public function test_memberships_role_check_constraint(): void
    {
        $school = School::factory()->create();
        $user = User::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('school_memberships')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid7(),
            'school_id' => $school->id,
            'user_id' => $user->id,
            'role' => 'headmaster', // Not in ('teacher', 'school_admin')
            'is_default' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Test partial unique index allows multiple is_default = false memberships.
     */
    public function test_partial_unique_index_allows_multiple_non_default_memberships(): void
    {
        $school1 = School::factory()->create();
        $school2 = School::factory()->create();
        $school3 = School::factory()->create();
        $user = User::factory()->create();

        $m1 = SchoolMembership::create([
            'school_id' => $school1->id,
            'user_id' => $user->id,
            'role' => 'teacher',
            'is_default' => false,
        ]);

        $m2 = SchoolMembership::create([
            'school_id' => $school2->id,
            'user_id' => $user->id,
            'role' => 'teacher',
            'is_default' => false,
        ]);

        $m3 = SchoolMembership::create([
            'school_id' => $school3->id,
            'user_id' => $user->id,
            'role' => 'teacher',
            'is_default' => false,
        ]);

        $this->assertDatabaseHas('school_memberships', ['id' => $m1->id]);
        $this->assertDatabaseHas('school_memberships', ['id' => $m2->id]);
        $this->assertDatabaseHas('school_memberships', ['id' => $m3->id]);
    }

    /**
     * Test active PostgreSQL connection strictly uses school_events_test and never school_events.
     */
    public function test_database_connection_is_strictly_school_events_test(): void
    {
        $result = DB::selectOne('SELECT current_database() AS db');

        $this->assertNotNull($result);
        $this->assertEquals('school_events_test', $result->db);
        $this->assertNotEquals('school_events', $result->db);
    }
}
