<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\SchoolTradition;
use App\Models\TraditionVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class SchoolProfileAndTraditionsTest extends TestCase
{
    use DatabaseTransactions;

    protected User $teacher;
    protected User $schoolAdmin;
    protected User $outsiderTeacher;
    protected User $systemAdmin;
    protected School $school;
    protected School $otherSchool;
    protected SchoolTradition $tradition;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::create([
            'name' => 'Лицей 130',
            'short_name' => 'Лицей 130',
            'region_code' => '66',
            'city' => 'Екатеринбург',
            'inn' => '6670012345',
            'settings' => [
                'address' => 'ул. Студенческая, д. 26',
                'phone' => '+7 (343) 374-32-11',
            ],
        ]);

        $this->otherSchool = School::create([
            'name' => 'Гимназия 9',
            'region_code' => '66',
            'city' => 'Екатеринбург',
            'inn' => '6670054321',
            'settings' => [],
        ]);

        $this->teacher = User::factory()->create();
        SchoolMembership::create([
            'school_id' => $this->school->id,
            'user_id' => $this->teacher->id,
            'role' => 'teacher',
            'is_default' => true,
        ]);

        $this->schoolAdmin = User::factory()->create();
        SchoolMembership::create([
            'school_id' => $this->school->id,
            'user_id' => $this->schoolAdmin->id,
            'role' => 'school_admin',
            'is_default' => true,
        ]);

        $this->outsiderTeacher = User::factory()->create();
        SchoolMembership::create([
            'school_id' => $this->otherSchool->id,
            'user_id' => $this->outsiderTeacher->id,
            'role' => 'teacher',
            'is_default' => true,
        ]);

        $this->systemAdmin = User::factory()->systemAdmin()->create();

        $this->tradition = SchoolTradition::create([
            'school_id' => $this->school->id,
            'title' => 'Посвящение в лицеисты',
            'description' => 'Ежегодное торжественное посвящение первоклассников и пятиклассников.',
            'established_year' => 1995,
            'is_archived' => false,
            'created_by_user_id' => $this->teacher->id,
        ]);

        TraditionVersion::create([
            'tradition_id' => $this->tradition->id,
            'academic_year' => '2025-2026',
            'parameters_snapshot' => [
                'theme' => 'Космическая одиссея',
                'target_grades' => [1, 5],
            ],
            'notes' => 'Юбилейный 30-й выпуск традиции.',
            'created_by_user_id' => $this->teacher->id,
        ]);
    }

    public function test_get_school_profile_success(): void
    {
        $response = $this->actingAs($this->teacher)
            ->getJson("/api/v1/schools/{$this->school->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $this->school->id)
            ->assertJsonPath('data.name', 'Лицей 130')
            ->assertJsonPath('data.region_code', '66')
            ->assertJsonPath('data.city', 'Екатеринбург')
            ->assertJsonPath('data.traditions_count', 1)
            ->assertJsonPath('data.active_teachers_count', 1);
    }

    public function test_get_school_profile_forbidden_for_outsider(): void
    {
        $response = $this->actingAs($this->outsiderTeacher)
            ->getJson("/api/v1/schools/{$this->school->id}");

        $response->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN_TENANT_ACCESS');
    }

    public function test_patch_school_profile_forbidden_for_teacher(): void
    {
        $response = $this->actingAs($this->teacher)
            ->patchJson("/api/v1/schools/{$this->school->id}", [
                'name' => 'Новое имя',
            ]);

        $response->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN_SCHOOL_ADMIN_ACCESS');
    }

    public function test_patch_school_profile_success_for_school_admin(): void
    {
        $response = $this->actingAs($this->schoolAdmin)
            ->patchJson("/api/v1/schools/{$this->school->id}", [
                'short_name' => 'Лицей №130 УрФУ',
                'settings' => [
                    'website' => 'https://lyceum130.ru',
                ],
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.short_name', 'Лицей №130 УрФУ')
            ->assertJsonPath('data.settings.website', 'https://lyceum130.ru')
            ->assertJsonPath('data.settings.address', 'ул. Студенческая, д. 26');

        $this->assertDatabaseHas('schools', [
            'id' => $this->school->id,
            'short_name' => 'Лицей №130 УрФУ',
        ]);
    }

    public function test_get_tradition_details(): void
    {
        $response = $this->actingAs($this->teacher)
            ->withHeader('X-School-Id', $this->school->id)
            ->getJson("/api/v1/traditions/{$this->tradition->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $this->tradition->id)
            ->assertJsonPath('data.title', 'Посвящение в лицеисты')
            ->assertJsonPath('data.established_year', 1995)
            ->assertJsonPath('data.is_archived', false)
            ->assertJsonCount(1, 'data.versions');
    }

    public function test_patch_tradition_metadata(): void
    {
        $response = $this->actingAs($this->teacher)
            ->withHeader('X-School-Id', $this->school->id)
            ->patchJson("/api/v1/traditions/{$this->tradition->id}", [
                'title' => 'Большое посвящение в лицеисты',
                'description' => 'Обновленное описание традиционного праздника.',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.title', 'Большое посвящение в лицеисты');

        $this->assertDatabaseHas('school_traditions', [
            'id' => $this->tradition->id,
            'title' => 'Большое посвящение в лицеисты',
        ]);
    }

    public function test_archive_tradition(): void
    {
        $response = $this->actingAs($this->schoolAdmin)
            ->withHeader('X-School-Id', $this->school->id)
            ->postJson("/api/v1/traditions/{$this->tradition->id}/archive");

        $response->assertStatus(200)
            ->assertJsonPath('data.is_archived', true);

        $this->tradition->refresh();
        $this->assertTrue($this->tradition->is_archived);
    }
}
