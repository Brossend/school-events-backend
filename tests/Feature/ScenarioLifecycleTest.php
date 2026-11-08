<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Scenario;
use App\Models\ScenarioRequest;
use App\Models\ScenarioRevision;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\TeacherSignoff;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ScenarioLifecycleTest extends TestCase
{
    use DatabaseTransactions;

    protected User $teacher;
    protected User $otherTeacher;
    protected User $schoolAdmin;
    protected User $systemAdmin;
    protected School $school;
    protected School $otherSchool;
    protected Scenario $scenario;
    protected ScenarioRevision $revision;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::create([
            'name' => 'Лицей 130',
            'region_code' => '66',
            'city' => 'Екатеринбург',
            'inn' => '6670012345',
            'settings' => [],
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

        $this->otherTeacher = User::factory()->create();
        SchoolMembership::create([
            'school_id' => $this->school->id,
            'user_id' => $this->otherTeacher->id,
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

        $this->systemAdmin = User::factory()->systemAdmin()->create();

        // Scenario request
        $scenarioReq = ScenarioRequest::create([
            'school_id' => $this->school->id,
            'user_id' => $this->teacher->id,
            'custom_event_title' => 'День первой березки',
            'scale' => 'class_level',
            'format_type' => 'class_hour',
            'target_grades' => [5],
            'participants_count' => 25,
            'duration_minutes' => 45,
            'value_direction' => 'cultural_diversity',
            'family_involvement' => false,
            'context_snapshot' => [
                'occasion' => ['title' => 'День первой березки'],
                'pedagogical_parameters' => ['scale' => 'class_level'],
            ],
        ]);

        $this->scenario = Scenario::create([
            'school_id' => $this->school->id,
            'user_id' => $this->teacher->id,
            'scenario_request_id' => $scenarioReq->id,
            'title' => 'День первой березки: традиции весеннего праздника',
            'is_archived' => false,
        ]);

        $this->revision = ScenarioRevision::create([
            'scenario_id' => $this->scenario->id,
            'version_number' => 1,
            'created_by_user_id' => $this->teacher->id,
            'concept' => 'Концепция весеннего воспитательного мероприятия для 5 класса.',
            'pedagogical_goal' => 'Формирование бережного отношения к природе и традициям.',
            'pedagogical_mechanism' => ['mechanism_type' => 'comparative_cultural_study'],
            'pedagogical_tasks' => ['personal' => ['Уважение к традициям']],
            'expected_outcomes' => ['knowledge' => ['Знание весенних обрядов']],
            'target_audience' => ['age_group' => 'basic_5_9'],
            'format' => ['scale' => 'class_level'],
            'total_duration_minutes' => 45,
            'teacher_instructions' => ['safety' => 'Инструктаж проведен'],
            'content_payload' => ['stages' => []],
        ]);

        $this->scenario->update(['current_revision_id' => $this->revision->id]);
    }

    public function test_archive_scenario(): void
    {
        $response = $this->actingAs($this->teacher)
            ->withHeader('X-School-Id', $this->school->id)
            ->postJson("/api/v1/scenarios/{$this->scenario->id}/archive");

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'archived')
            ->assertJsonPath('data.is_archived', true);

        $this->scenario->refresh();
        $this->assertTrue($this->scenario->is_archived);
    }

    public function test_archive_scenario_idempotent(): void
    {
        $this->scenario->update(['is_archived' => true]);

        $response = $this->actingAs($this->teacher)
            ->withHeader('X-School-Id', $this->school->id)
            ->postJson("/api/v1/scenarios/{$this->scenario->id}/archive");

        $response->assertStatus(200)
            ->assertJsonPath('data.is_archived', true);
    }

    public function test_other_teacher_cannot_archive_scenario(): void
    {
        $response = $this->actingAs($this->otherTeacher)
            ->withHeader('X-School-Id', $this->school->id)
            ->postJson("/api/v1/scenarios/{$this->scenario->id}/archive");

        $response->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN_AUTHOR_ACCESS');
    }

    public function test_school_admin_can_archive_scenario(): void
    {
        $response = $this->actingAs($this->schoolAdmin)
            ->withHeader('X-School-Id', $this->school->id)
            ->postJson("/api/v1/scenarios/{$this->scenario->id}/archive");

        $response->assertStatus(200)
            ->assertJsonPath('data.is_archived', true);
    }

    public function test_restore_scenario(): void
    {
        $this->scenario->update(['is_archived' => true]);

        $response = $this->actingAs($this->teacher)
            ->withHeader('X-School-Id', $this->school->id)
            ->postJson("/api/v1/scenarios/{$this->scenario->id}/restore");

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.is_archived', false);

        $this->scenario->refresh();
        $this->assertFalse($this->scenario->is_archived);
    }

    public function test_destroy_scenario_without_signoff_soft_deletes(): void
    {
        $response = $this->actingAs($this->teacher)
            ->withHeader('X-School-Id', $this->school->id)
            ->deleteJson("/api/v1/scenarios/{$this->scenario->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.message', 'Сценарий успешно удален.');

        // Verify soft-deleted
        $this->assertSoftDeleted('scenarios', ['id' => $this->scenario->id]);
    }

    public function test_destroy_scenario_with_teacher_signoff_returns_409_conflict(): void
    {
        // Add a teacher sign-off to revision
        TeacherSignoff::create([
            'revision_id' => $this->revision->id,
            'user_id' => $this->teacher->id,
            'is_confirmed' => true,
            'checklist_confirmed' => [
                'safety_checked' => true,
                'timing_fit' => true,
                'cultural_context_approved' => true,
            ],
            'teacher_notes' => 'Подписано к проведению.',
        ]);

        $response = $this->actingAs($this->teacher)
            ->withHeader('X-School-Id', $this->school->id)
            ->deleteJson("/api/v1/scenarios/{$this->scenario->id}");

        $response->assertStatus(409)
            ->assertJsonPath('error.code', 'CANNOT_DELETE_SIGNED_OFF_SCENARIO');

        // Verify scenario was NOT deleted
        $this->assertDatabaseHas('scenarios', [
            'id' => $this->scenario->id,
            'deleted_at' => null,
        ]);
    }

    public function test_scenarios_index_filters_and_search(): void
    {
        // 1. By default excludes archived
        $this->scenario->update(['is_archived' => true]);

        $res1 = $this->actingAs($this->teacher)
            ->withHeader('X-School-Id', $this->school->id)
            ->getJson('/api/v1/scenarios');

        $res1->assertStatus(200);
        $this->assertCount(0, $res1->json('data'));

        // 2. is_archived=true returns archived
        $res2 = $this->actingAs($this->teacher)
            ->withHeader('X-School-Id', $this->school->id)
            ->getJson('/api/v1/scenarios?is_archived=true');

        $res2->assertStatus(200);
        $this->assertCount(1, $res2->json('data'));

        // Restore scenario
        $this->scenario->update(['is_archived' => false]);

        // 3. Search q
        $resSearch = $this->actingAs($this->teacher)
            ->withHeader('X-School-Id', $this->school->id)
            ->getJson('/api/v1/scenarios?q=березки');

        $resSearch->assertStatus(200);
        $this->assertCount(1, $resSearch->json('data'));

        // 4. Search mismatch
        $resNoSearch = $this->actingAs($this->teacher)
            ->withHeader('X-School-Id', $this->school->id)
            ->getJson('/api/v1/scenarios?q=несуществующее_название');

        $resNoSearch->assertStatus(200);
        $this->assertCount(0, $resNoSearch->json('data'));
    }
}
