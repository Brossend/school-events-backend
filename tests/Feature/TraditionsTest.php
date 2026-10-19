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

class TraditionsTest extends TestCase
{
    use DatabaseTransactions;

    protected School $schoolA;
    protected School $schoolB;
    protected User $teacherA;
    protected User $teacherB;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Schools
        $this->schoolA = School::factory()->create(['name' => 'Лицей № 130']);
        $this->schoolB = School::factory()->create(['name' => 'Гимназия № 9']);

        // 2. Teachers
        $this->teacherA = User::factory()->create(['email' => 'teacherA@traditions.ru']);
        SchoolMembership::factory()->create([
            'school_id' => $this->schoolA->id,
            'user_id' => $this->teacherA->id,
            'role' => 'teacher',
            'is_default' => true,
        ]);

        $this->teacherB = User::factory()->create(['email' => 'teacherB@traditions.ru']);
        SchoolMembership::factory()->create([
            'school_id' => $this->schoolB->id,
            'user_id' => $this->teacherB->id,
            'role' => 'teacher',
            'is_default' => true,
        ]);
    }

    /**
     * Test creating a school tradition.
     */
    public function test_teacher_can_create_tradition(): void
    {
        $payload = [
            'title' => 'Посвящение в лицеисты',
            'description' => 'Торжественная церемония посвящения пятиклассников в лицейское братство.',
            'established_year' => 1991,
        ];

        $response = $this->actingAs($this->teacherA, 'web')
            ->postJson("/api/v1/schools/{$this->schoolA->id}/traditions", $payload);

        $response->assertStatus(201)
            ->assertJsonPath('data.title', 'Посвящение в лицеисты')
            ->assertJsonPath('data.established_year', 1991);

        $this->assertDatabaseHas('school_traditions', [
            'school_id' => $this->schoolA->id,
            'title' => 'Посвящение в лицеисты',
            'created_by_user_id' => $this->teacherA->id,
        ]);
    }

    /**
     * Test listing traditions of a school.
     */
    public function test_teacher_can_list_school_traditions(): void
    {
        $tradition = SchoolTradition::create([
            'school_id' => $this->schoolA->id,
            'title' => 'Лицейский бал',
            'description' => 'Ежегодный осенний бал старшеклассников.',
            'established_year' => 2005,
            'created_by_user_id' => $this->teacherA->id,
        ]);

        $response = $this->actingAs($this->teacherA, 'web')
            ->getJson("/api/v1/schools/{$this->schoolA->id}/traditions");

        $response->assertStatus(200)
            ->assertJsonFragment(['id' => $tradition->id, 'title' => 'Лицейский бал']);
    }

    /**
     * Test creating immutable annual versions of a tradition.
     */
    public function test_tradition_annual_versions_immutability(): void
    {
        $tradition = SchoolTradition::create([
            'school_id' => $this->schoolA->id,
            'title' => 'Фестиваль культур',
            'description' => 'Праздник народных традиций.',
            'created_by_user_id' => $this->teacherA->id,
        ]);

        // Version for 2025-2026
        $res1 = $this->actingAs($this->teacherA, 'web')
            ->postJson("/api/v1/traditions/{$tradition->id}/versions", [
                'academic_year' => '2025-2026',
                'parameters_snapshot' => [
                    'theme' => 'Народы Урала',
                    'participants_count' => 150,
                ],
                'notes' => 'Юбилейный фестиваль',
            ]);

        $res1->assertStatus(201)
            ->assertJsonPath('data.academic_year', '2025-2026')
            ->assertJsonPath('data.parameters_snapshot.theme', 'Народы Урала');

        // Version for 2026-2027
        $res2 = $this->actingAs($this->teacherA, 'web')
            ->postJson("/api/v1/traditions/{$tradition->id}/versions", [
                'academic_year' => '2026-2027',
                'parameters_snapshot' => [
                    'theme' => 'Русские промыслы',
                    'participants_count' => 180,
                ],
                'notes' => 'Расширенная программа',
            ]);

        $res2->assertStatus(201)
            ->assertJsonPath('data.academic_year', '2026-2027');

        // Both versions must exist in the database (append-only)
        $this->assertEquals(2, TraditionVersion::where('tradition_id', $tradition->id)->count());
    }

    /**
     * Test duplicate version creation for the same academic year is blocked.
     */
    public function test_duplicate_version_for_same_academic_year_is_rejected(): void
    {
        $tradition = SchoolTradition::create([
            'school_id' => $this->schoolA->id,
            'title' => 'Зарница',
            'description' => 'Военно-патриотическая игра.',
            'created_by_user_id' => $this->teacherA->id,
        ]);

        // First creation succeeds
        TraditionVersion::create([
            'tradition_id' => $tradition->id,
            'academic_year' => '2026-2027',
            'parameters_snapshot' => ['stages' => 5],
            'created_by_user_id' => $this->teacherA->id,
        ]);

        // Second creation for the exact same academic year must fail
        $response = $this->actingAs($this->teacherA, 'web')
            ->postJson("/api/v1/traditions/{$tradition->id}/versions", [
                'academic_year' => '2026-2027',
                'parameters_snapshot' => ['stages' => 6],
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'TRADITION_VERSION_ALREADY_EXISTS');
    }

    /**
     * Requirement 7.14: создание учебного года 2026-2029 -> 422 (не последовательные годы)
     */
    public function test_non_consecutive_academic_year_fails_validation(): void
    {
        $tradition = SchoolTradition::create([
            'school_id' => $this->schoolA->id,
            'title' => 'Осенний фестиваль',
            'description' => 'Школьный праздник.',
            'created_by_user_id' => $this->teacherA->id,
        ]);

        // 2026-2029 is not consecutive!
        $response = $this->actingAs($this->teacherA, 'web')
            ->postJson("/api/v1/traditions/{$tradition->id}/versions", [
                'academic_year' => '2026-2029',
                'parameters_snapshot' => ['theme' => 'Наука'],
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    /**
     * Requirement 7.15: конкурирующие запросы создания версии -> одна версия, без 500
     */
    public function test_concurrent_duplicate_version_creation_handled_without_500(): void
    {
        $tradition = SchoolTradition::create([
            'school_id' => $this->schoolA->id,
            'title' => 'Лицейская олимпиада',
            'description' => 'Ежегодное соревнование.',
            'created_by_user_id' => $this->teacherA->id,
        ]);

        // First creation succeeds
        $res1 = $this->actingAs($this->teacherA, 'web')
            ->postJson("/api/v1/traditions/{$tradition->id}/versions", [
                'academic_year' => '2026-2027',
                'parameters_snapshot' => ['theme' => 'Первый запрос'],
            ]);
        $res1->assertStatus(201);

        // Second creation for the exact same academic year returns 422, never 500
        $res2 = $this->actingAs($this->teacherA, 'web')
            ->postJson("/api/v1/traditions/{$tradition->id}/versions", [
                'academic_year' => '2026-2027',
                'parameters_snapshot' => ['theme' => 'Параллельный запрос'],
            ]);

        $res2->assertStatus(422)
            ->assertJsonPath('error.code', 'TRADITION_VERSION_ALREADY_EXISTS');

        // Exactly one version exists in database
        $this->assertEquals(1, TraditionVersion::where('tradition_id', $tradition->id)->count());
    }

    /**
     * Test academic year format validation.
     */
    public function test_invalid_academic_year_format_fails_validation(): void
    {
        $tradition = SchoolTradition::create([
            'school_id' => $this->schoolA->id,
            'title' => 'День знаний',
            'description' => 'Линейка первого сентября.',
            'created_by_user_id' => $this->teacherA->id,
        ]);

        $response = $this->actingAs($this->teacherA, 'web')
            ->postJson("/api/v1/traditions/{$tradition->id}/versions", [
                'academic_year' => '2026', // Invalid format! Must be YYYY-YYYY
                'parameters_snapshot' => ['duration' => 60],
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    /**
     * Test cross-tenant access rejection for traditions.
     */
    public function test_cross_tenant_tradition_access_forbidden(): void
    {
        $traditionB = SchoolTradition::create([
            'school_id' => $this->schoolB->id,
            'title' => 'Гимназический марафон',
            'description' => 'Спортивная традиция гимназии 9.',
            'created_by_user_id' => $this->teacherB->id,
        ]);

        // 1. Teacher of School A tries to read School B's traditions -> 403 Forbidden
        $resList = $this->actingAs($this->teacherA, 'web')
            ->getJson("/api/v1/schools/{$this->schoolB->id}/traditions");

        $resList->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN_TENANT_ACCESS');

        // 2. Teacher of School A tries to create a tradition for School B -> 403 Forbidden
        $resCreate = $this->actingAs($this->teacherA, 'web')
            ->postJson("/api/v1/schools/{$this->schoolB->id}/traditions", [
                'title' => 'Попытка внедрения традиции',
                'description' => 'Несанкционированное создание традиции в чужой школе.',
            ]);

        $resCreate->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN_TENANT_ACCESS');

        // 3. Teacher of School A tries to create a version for School B's tradition -> 403 Forbidden
        $resVersion = $this->actingAs($this->teacherA, 'web')
            ->postJson("/api/v1/traditions/{$traditionB->id}/versions", [
                'academic_year' => '2026-2027',
                'parameters_snapshot' => ['hack' => true],
            ]);

        $resVersion->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN_TENANT_ACCESS');
    }

    /**
     * Test tenant injection prevention in JSON body.
     */
    public function test_tenant_injection_in_body_is_rejected(): void
    {
        $payloadWithSchoolId = [
            'title' => 'Традиция с инъекцией',
            'description' => 'Попытка передать school_id в теле запроса.',
            'school_id' => $this->schoolB->id,
        ];

        $response = $this->actingAs($this->teacherA, 'web')
            ->postJson("/api/v1/schools/{$this->schoolA->id}/traditions", $payloadWithSchoolId);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'TENANT_INJECTION_FORBIDDEN');
    }

    /**
     * Requirement BE-002.2 #5: Запрет перезаписи и удаления TraditionVersion на уровне доменной модели.
     */
    public function test_tradition_version_domain_immutability(): void
    {
        $tradition = SchoolTradition::create([
            'school_id' => $this->schoolA->id,
            'title' => 'День книги',
            'description' => 'Школьный праздник.',
            'created_by_user_id' => $this->teacherA->id,
        ]);

        $version = TraditionVersion::create([
            'tradition_id' => $tradition->id,
            'academic_year' => '2026-2027',
            'parameters_snapshot' => ['theme' => 'Книжный марафон'],
            'created_by_user_id' => $this->teacherA->id,
        ]);

        // 1. Updating throws LogicException
        try {
            $version->update(['notes' => 'Попытка изменения версии']);
            $this->fail('Updating an existing TraditionVersion should throw LogicException.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }

        // 2. Deleting throws LogicException
        try {
            $version->delete();
            $this->fail('Deleting an existing TraditionVersion should throw LogicException.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }
    }

    /**
     * Requirement BE-002.3: Тест уникальности на двух независимых соединениях PostgreSQL.
     * Проверяет точный код SQLSTATE 23505 (unique_violation) по ограничению uq_tradition_academic_year.
     */
    public function test_two_connections_uniqueness_constraint_23505(): void
    {
        $config = config('database.connections.pgsql');
        $dsn = "pgsql:host={$config['host']};port={$config['port']};dbname=school_events_test";

        $pdo1 = new \PDO($dsn, $config['username'], $config['password'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $pdo2 = new \PDO($dsn, $config['username'], $config['password'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);

        $schoolId = (string) \Illuminate\Support\Str::uuid7();
        $userId = (string) \Illuminate\Support\Str::uuid7();
        $traditionId = (string) \Illuminate\Support\Str::uuid7();
        $id1 = (string) \Illuminate\Support\Str::uuid7();
        $id2 = (string) \Illuminate\Support\Str::uuid7();
        $academicYear = '2026-2027';
        $paramsJson = json_encode(['theme' => 'Параллельный тест']);

        try {
            // Setup committed test entities on pdo1 so pdo2 can see them
            $pdo1->exec("INSERT INTO users (id, name, email, password, system_role, is_active, created_at, updated_at) VALUES ('{$userId}', 'Concurrency User', 'concurrency@test.ru', 'hash', 'none', true, NOW(), NOW())");
            $pdo1->exec("INSERT INTO schools (id, name, region_code, city, settings, created_at, updated_at) VALUES ('{$schoolId}', 'Concurrency School', '66', 'Екатеринбург', '{}'::jsonb, NOW(), NOW())");
            $pdo1->exec("INSERT INTO school_traditions (id, school_id, title, description, established_year, created_by_user_id, created_at, updated_at) VALUES ('{$traditionId}', '{$schoolId}', 'Параллельная традиция', 'Описание', 2020, '{$userId}', NOW(), NOW())");

            // Connection 1 inserts in transaction
            $pdo1->beginTransaction();
            $stmt1 = $pdo1->prepare('INSERT INTO tradition_versions (id, tradition_id, academic_year, parameters_snapshot, created_by_user_id, created_at) VALUES (?, ?, ?, ?::jsonb, ?, NOW())');
            $stmt1->execute([$id1, $traditionId, $academicYear, $paramsJson, $userId]);
            $pdo1->commit();

            // Connection 2 attempts to insert duplicate in separate connection
            try {
                $stmt2 = $pdo2->prepare('INSERT INTO tradition_versions (id, tradition_id, academic_year, parameters_snapshot, created_by_user_id, created_at) VALUES (?, ?, ?, ?::jsonb, ?, NOW())');
                $stmt2->execute([$id2, $traditionId, $academicYear, $paramsJson, $userId]);
                $this->fail('Connection 2 should have failed with PostgreSQL unique violation 23505');
            } catch (\PDOException $e) {
                $this->assertEquals('23505', $e->getCode(), 'Expected PostgreSQL unique_violation code 23505');
                $this->assertStringContainsString('uq_tradition_academic_year', $e->getMessage());
            }
        } finally {
            $pdo1->exec("DELETE FROM school_traditions WHERE id = '{$traditionId}'");
            $pdo1->exec("DELETE FROM schools WHERE id = '{$schoolId}'");
            $pdo1->exec("DELETE FROM users WHERE id = '{$userId}'");
        }
    }
}
