<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ScenarioRequest;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Services\Llm\MockLlmProvider;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ScaleSpecificGenerationTest extends TestCase
{
    use DatabaseTransactions;

    protected User $teacher;
    protected School $school;
    protected MockLlmProvider $llmProvider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->llmProvider = new MockLlmProvider();

        $this->school = School::create([
            'name' => 'Лицей 130',
            'region_code' => '66',
            'city' => 'Екатеринбург',
            'inn' => '6670012345',
            'settings' => [],
        ]);

        $this->teacher = User::factory()->create();
        SchoolMembership::create([
            'school_id' => $this->school->id,
            'user_id' => $this->teacher->id,
            'role' => 'teacher',
            'is_default' => true,
        ]);
    }

    public function test_class_level_generation_structure(): void
    {
        $request = ScenarioRequest::create([
            'school_id' => $this->school->id,
            'user_id' => $this->teacher->id,
            'custom_event_title' => 'Классный час дружбы',
            'scale' => 'class_level',
            'format_type' => 'class_hour',
            'target_grades' => [5],
            'participants_count' => 25,
            'duration_minutes' => 45,
            'value_direction' => 'mutual_aid',
            'family_involvement' => false,
            'context_snapshot' => [
                'occasion' => ['title' => 'Классный час дружбы'],
                'pedagogical_parameters' => ['scale' => 'class_level'],
            ],
        ]);

        $result = $this->llmProvider->generate($request);
        $payload = $result['output_payload'];

        $this->assertEquals('class_level', $payload['format']['scale']);
        $this->assertEquals(45, $payload['format']['total_duration_minutes']);
        $this->assertCount(3, $payload['stages']);

        $totalStageDuration = collect($payload['stages'])->sum('duration_minutes');
        $this->assertEquals(45, $totalStageDuration);

        $roleNames = collect($payload['roles'])->pluck('role_name')->all();
        $this->assertContains('Хранитель традиций', $roleNames);
        $this->assertContains('Презентатор идей', $roleNames);
    }

    public function test_all_school_generation_structure(): void
    {
        $request = ScenarioRequest::create([
            'school_id' => $this->school->id,
            'user_id' => $this->teacher->id,
            'custom_event_title' => 'Осенний общешкольный марафон',
            'scale' => 'all_school',
            'format_type' => 'festival',
            'target_grades' => [5, 6, 7, 8, 9, 10, 11],
            'participants_count' => 200,
            'duration_minutes' => 90,
            'value_direction' => 'civic_responsibility',
            'family_involvement' => false,
            'context_snapshot' => [
                'occasion' => ['title' => 'Осенний общешкольный марафон'],
                'pedagogical_parameters' => ['scale' => 'all_school'],
            ],
        ]);

        $result = $this->llmProvider->generate($request);
        $payload = $result['output_payload'];

        $this->assertEquals('all_school', $payload['format']['scale']);
        $this->assertEquals(90, $payload['format']['total_duration_minutes']);
        $this->assertCount(4, $payload['stages']);

        $totalStageDuration = collect($payload['stages'])->sum('duration_minutes');
        $this->assertEquals(90, $totalStageDuration);

        $roleNames = collect($payload['roles'])->pluck('role_name')->all();
        $this->assertContains('Координатор тематической станции', $roleNames);
        $this->assertContains('Капитан общешкольного отряда', $roleNames);
        $this->assertContains('Ведущий общешкольного форума', $roleNames);
        $this->assertContains('Медиа-летописец школы', $roleNames);
    }

    public function test_family_community_generation_structure(): void
    {
        $request = ScenarioRequest::create([
            'school_id' => $this->school->id,
            'user_id' => $this->teacher->id,
            'custom_event_title' => 'Семейный очаг поколений',
            'scale' => 'family_community',
            'format_type' => 'workshop',
            'target_grades' => [3, 4],
            'participants_count' => 40,
            'duration_minutes' => 60,
            'value_direction' => 'family',
            'family_involvement' => true,
            'context_snapshot' => [
                'occasion' => ['title' => 'Семейный очаг поколений'],
                'pedagogical_parameters' => ['scale' => 'family_community'],
            ],
        ]);

        $result = $this->llmProvider->generate($request);
        $payload = $result['output_payload'];

        $this->assertEquals('family_community', $payload['format']['scale']);
        $this->assertEquals(60, $payload['format']['total_duration_minutes']);
        $this->assertTrue($payload['target_audience']['family_involvement']);
        $this->assertCount(4, $payload['stages']);

        $totalStageDuration = collect($payload['stages'])->sum('duration_minutes');
        $this->assertEquals(60, $totalStageDuration);

        $roleNames = collect($payload['roles'])->pluck('role_name')->all();
        $this->assertContains('Капитан семейного экипажа', $roleNames);
        $this->assertContains('Хранитель семейной летописи', $roleNames);
        $this->assertContains('Семейный фасилитатор', $roleNames);
        $this->assertContains('Почетный наставник поколений', $roleNames);
    }

    public function test_scenario_request_creation_with_custom_region(): void
    {
        $response = $this->actingAs($this->teacher)
            ->withHeader('X-School-Id', $this->school->id)
            ->postJson('/api/v1/scenario-requests', [
                'custom_event_title' => 'День ремесел народов Урала',
                'region_code' => '66',
                'scale' => 'all_school',
                'format_type' => 'festival',
                'target_grades' => [5, 6],
                'participants_count' => 80,
                'duration_minutes' => 90,
                'value_direction' => 'cultural_diversity',
                'family_involvement' => false,
            ]);

        $response->assertStatus(201);
        $requestId = $response->json('data.id');

        $req = ScenarioRequest::find($requestId);
        $this->assertNotNull($req);
        $this->assertEquals('66', $req->context_snapshot['occasion']['custom_region_code']);
    }
}
