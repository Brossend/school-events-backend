<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CalendarEvent;
use App\Models\CulturalClaim;
use App\Models\EventSource;
use App\Models\ScenarioRequest;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\SourceReview;
use App\Models\User;
use App\Services\ProvenanceVerificationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ScenarioComparisonTest extends TestCase
{
    use DatabaseTransactions;

    protected User $teacher;

    protected User $otherTeacher;

    protected User $curator;

    protected School $school;

    protected School $otherSchool;

    protected CalendarEvent $event;

    protected ScenarioRequest $scenarioRequest;

    protected function setUp(): void
    {
        parent::setUp();

        $this->curator = User::factory()->curator('global')->create();

        $this->school = School::create([
            'name' => 'Лицей 130',
            'short_name' => 'Лицей 130',
            'region_code' => '66',
            'city' => 'Екатеринбург',
            'inn' => '6670012345',
            'settings' => [],
        ]);

        $this->otherSchool = School::create([
            'name' => 'Гимназия 9',
            'short_name' => 'Гимназия 9',
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
            'school_id' => $this->otherSchool->id,
            'user_id' => $this->otherTeacher->id,
            'role' => 'teacher',
            'is_default' => true,
        ]);

        $this->event = CalendarEvent::create([
            'title' => 'День народного единства',
            'event_type' => 'federal',
            'date_type' => 'fixed_annual',
            'fixed_month' => 11,
            'fixed_day' => 4,
            'description' => 'Государственный праздник Российской Федерации',
            'target_age_groups' => ['basic_5_9'],
            'recommended_value_directions' => ['historical_memory', 'cultural_diversity'],
            'status' => 'approved',
        ]);

        $source = EventSource::create([
            'calendar_event_id' => $this->event->id,
            'source_type' => 'normative_act',
            'title' => 'ФЗ № 201-ФЗ',
            'verified_by_user_id' => $this->curator->id,
            'verification_date' => now(),
        ]);

        SourceReview::create([
            'event_source_id' => $source->id,
            'reviewer_user_id' => $this->curator->id,
            'decision' => 'approved',
            'comments' => 'Источник подлинный',
        ]);

        $claim = CulturalClaim::create([
            'calendar_event_id' => $this->event->id,
            'claim_text' => '4 ноября — официальный праздничный день',
            'culture_name' => 'Общероссийская культура',
            'provenance_notes' => 'Первичная фиксация',
        ]);

        app(ProvenanceVerificationService::class)->verifyClaim(
            claim: $claim,
            source: $source,
            citationLocator: 'Статья 1',
            verifier: $this->curator,
            evidenceExcerpt: '4 ноября - День народного единства',
            academicYear: '2026-2027',
            notes: 'Верифицировано'
        );

        $contextSnapshot = [
            'occasion' => [
                'type' => 'calendar_event',
                'id' => $this->event->id,
                'title' => $this->event->title,
            ],
            'cultural_provenance' => [
                'status' => 'has_verified_claims',
                'claims' => [
                    [
                        'id' => $claim->id,
                        'claim_text' => $claim->claim_text,
                        'culture_name' => $claim->culture_name,
                        'effective_status' => 'verified_authentic',
                        'evidence_records' => [
                            [
                                'event_source_id' => $source->id,
                                'source_title' => $source->title,
                                'citation_locator' => 'Статья 1',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->scenarioRequest = ScenarioRequest::create([
            'school_id' => $this->school->id,
            'user_id' => $this->teacher->id,
            'calendar_event_id' => $this->event->id,
            'scale' => 'class_level',
            'format_type' => 'class_hour',
            'target_grades' => [6],
            'participants_count' => 25,
            'duration_minutes' => 45,
            'value_direction' => 'cultural_diversity',
            'family_involvement' => false,
            'available_resources' => ['Проектор', 'Краски', 'Ватманы'],
            'context_snapshot' => $contextSnapshot,
            'created_at' => now(),
        ]);
    }

    public function test_can_create_universal_vs_personalized_comparison_and_retrieve_variants(): void
    {
        $response = $this->actingAs($this->teacher)
            ->withHeader('X-School-Id', $this->school->id)
            ->postJson('/api/v1/scenario-comparisons', [
                'scenario_request_id' => $this->scenarioRequest->id,
                'comparison_type' => 'universal_vs_personalized',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.comparison_type', 'universal_vs_personalized')
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.is_cultural_available', true);

        $comparisonId = $response->json('data.id');
        $this->assertNotNull($comparisonId);

        // Retrieve comparison details
        $showRes = $this->actingAs($this->teacher)
            ->withHeader('X-School-Id', $this->school->id)
            ->getJson("/api/v1/scenario-comparisons/{$comparisonId}");

        $showRes->assertStatus(200)
            ->assertJsonPath('data.id', $comparisonId);

        // Retrieve variants
        $variantsRes = $this->actingAs($this->teacher)
            ->withHeader('X-School-Id', $this->school->id)
            ->getJson("/api/v1/scenario-comparisons/{$comparisonId}/variants");

        $variantsRes->assertStatus(200)
            ->assertJsonPath('data.comparison_id', $comparisonId)
            ->assertJsonCount(2, 'data.variants');

        $variantA = $variantsRes->json('data.variants.0');
        $variantB = $variantsRes->json('data.variants.1');

        $this->assertEquals('A', $variantA['variant_key']);
        $this->assertStringContainsString('Универсальный инвариантный сценарий', $variantA['scenario_payload']['concept']);
        $this->assertEquals('B', $variantB['variant_key']);
        $this->assertStringContainsString('Классный воспитательный сценарий', $variantB['scenario_payload']['concept']);

        // Check list endpoint
        $listRes = $this->actingAs($this->teacher)
            ->withHeader('X-School-Id', $this->school->id)
            ->getJson('/api/v1/scenario-comparisons');

        $listRes->assertStatus(200)
            ->assertJsonFragment(['id' => $comparisonId]);
    }

    public function test_can_create_cultural_context_comparison_with_verified_provenance(): void
    {
        $response = $this->actingAs($this->teacher)
            ->withHeader('X-School-Id', $this->school->id)
            ->postJson('/api/v1/scenario-comparisons', [
                'scenario_request_id' => $this->scenarioRequest->id,
                'comparison_type' => 'cultural_context',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.comparison_type', 'cultural_context')
            ->assertJsonPath('data.is_cultural_available', true);

        $comparisonId = $response->json('data.id');

        $variantsRes = $this->actingAs($this->teacher)
            ->withHeader('X-School-Id', $this->school->id)
            ->getJson("/api/v1/scenario-comparisons/{$comparisonId}/variants");

        $variantA = $variantsRes->json('data.variants.0');
        $variantB = $variantsRes->json('data.variants.1');

        // Variant A must have empty cultural claims
        $this->assertEmpty($variantA['scenario_payload']['cultural_claims'] ?? []);
        // Variant B must have verified cultural claims
        $this->assertNotEmpty($variantB['scenario_payload']['cultural_claims'] ?? []);
    }

    public function test_cultural_context_comparison_marks_unavailable_when_no_verified_claims_exist(): void
    {
        // Create request with NO verified cultural claims
        $emptyContext = $this->scenarioRequest->context_snapshot;
        $emptyContext['cultural_provenance']['claims'] = [];

        $requestWithoutClaims = ScenarioRequest::create([
            'school_id' => $this->school->id,
            'user_id' => $this->teacher->id,
            'calendar_event_id' => $this->event->id,
            'scale' => 'class_level',
            'format_type' => 'class_hour',
            'target_grades' => [5],
            'participants_count' => 20,
            'duration_minutes' => 45,
            'value_direction' => 'historical_memory',
            'family_involvement' => false,
            'available_resources' => ['Доска'],
            'context_snapshot' => $emptyContext,
            'created_at' => now(),
        ]);

        $response = $this->actingAs($this->teacher)
            ->withHeader('X-School-Id', $this->school->id)
            ->postJson('/api/v1/scenario-comparisons', [
                'scenario_request_id' => $requestWithoutClaims->id,
                'comparison_type' => 'cultural_context',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.is_cultural_available', false)
            ->assertJsonPath('data.variants.1.is_cultural_available', false);

        $comparisonId = $response->json('data.id');

        // Attempting to select Variant B must fail with 409 Conflict
        $selectB = $this->actingAs($this->teacher)
            ->withHeader('X-School-Id', $this->school->id)
            ->postJson("/api/v1/scenario-comparisons/{$comparisonId}/selection", [
                'selected_variant' => 'B',
                'selection_reason' => 'Я хочу выбрать культурный вариант',
            ]);

        $selectB->assertStatus(409);
    }

    public function test_can_select_preferred_variant_and_cannot_double_record_selection(): void
    {
        $compRes = $this->actingAs($this->teacher)
            ->withHeader('X-School-Id', $this->school->id)
            ->postJson('/api/v1/scenario-comparisons', [
                'scenario_request_id' => $this->scenarioRequest->id,
                'comparison_type' => 'universal_vs_personalized',
            ]);

        $comparisonId = $compRes->json('data.id');

        // Select Variant B
        $selectRes = $this->actingAs($this->teacher)
            ->withHeader('X-School-Id', $this->school->id)
            ->postJson("/api/v1/scenario-comparisons/{$comparisonId}/selection", [
                'selected_variant' => 'B',
                'selection_reason' => 'Персонализированный вариант содержит точную ролевую модель для 6 класса',
                'cultural_usefulness_rating' => 5,
            ]);

        $selectRes->assertStatus(200)
            ->assertJsonPath('data.selected_variant', 'B')
            ->assertJsonPath('data.selection_reason', 'Персонализированный вариант содержит точную ролевую модель для 6 класса')
            ->assertJsonPath('data.cultural_usefulness_rating', 5);

        // Attempting to change or re-record selection returns 409 Conflict
        $secondSelect = $this->actingAs($this->teacher)
            ->withHeader('X-School-Id', $this->school->id)
            ->postJson("/api/v1/scenario-comparisons/{$comparisonId}/selection", [
                'selected_variant' => 'A',
                'selection_reason' => 'Изменил свое мнение в пользу универсального',
            ]);

        $secondSelect->assertStatus(409);
    }

    public function test_multi_tenancy_isolation_for_scenario_comparisons(): void
    {
        // Teacher A creates comparison in School 1
        $compRes = $this->actingAs($this->teacher)
            ->withHeader('X-School-Id', $this->school->id)
            ->postJson('/api/v1/scenario-comparisons', [
                'scenario_request_id' => $this->scenarioRequest->id,
                'comparison_type' => 'universal_vs_personalized',
            ]);

        $comparisonId = $compRes->json('data.id');

        // Teacher B in School 2 cannot view comparison of School 1
        $this->actingAs($this->otherTeacher)
            ->withHeader('X-School-Id', $this->otherSchool->id)
            ->getJson("/api/v1/scenario-comparisons/{$comparisonId}")
            ->assertStatus(403);

        $this->actingAs($this->otherTeacher)
            ->withHeader('X-School-Id', $this->otherSchool->id)
            ->getJson("/api/v1/scenario-comparisons/{$comparisonId}/variants")
            ->assertStatus(403);

        $this->actingAs($this->otherTeacher)
            ->withHeader('X-School-Id', $this->otherSchool->id)
            ->postJson("/api/v1/scenario-comparisons/{$comparisonId}/selection", [
                'selected_variant' => 'A',
                'selection_reason' => 'Попытка межшкольного вмешательства',
            ])
            ->assertStatus(403);

        // Teacher B cannot create comparison using School 1's scenario request
        $this->actingAs($this->otherTeacher)
            ->withHeader('X-School-Id', $this->otherSchool->id)
            ->postJson('/api/v1/scenario-comparisons', [
                'scenario_request_id' => $this->scenarioRequest->id,
                'comparison_type' => 'universal_vs_personalized',
            ])
            ->assertStatus(403);
    }
}
