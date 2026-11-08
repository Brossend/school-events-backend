<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ExecuteGenerationRunJob;
use App\Models\GenerationRun;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Services\Llm\GenerationOutputValidator;
use App\Services\Llm\PedagogicalQualityGate;
use App\Services\Llm\ScenarioOutputTemplate;
use App\Services\Llm\ScenarioRepairPlanner;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ScenarioRepairPlannerTest extends TestCase
{
    public function test_combined_custom_event_patch_requires_both_damaged_children_and_preserves_cards(): void
    {
        $request = LiteLlmProviderTest::requestFixture();
        $request->calendar_event_id = null;
        $schema = json_decode(file_get_contents(base_path('docs/generation-output.schema.json')), true);
        $ready = LiteLlmProviderTest::readyPayload($request);
        $original = $ready;
        $original['materials']['handouts'][0]['template_content'] = 'Generic';
        $original['materials']['presentation_slides'][1]['speaker_notes'] = 'Педагог рассказывает.';
        $errors = ['READY_HANDOUT_0' => ['example'], 'READY_SLIDE_SPEECH_$.materials.presentation_slides.1.speaker_notes' => ['speech']];
        $planner = app(ScenarioRepairPlanner::class);
        $plan = $planner->slidePlan($original, $errors, $schema, $request);
        $fields = $planner->materialFields($original, $errors, $schema, $plan);
        $this->assertEqualsCanonicalizing(['handouts', 'presentation_slides'], $fields);
        $patch = ['materials' => ['handouts' => $ready['materials']['handouts'], 'presentation_slides' => ['slide_1' => ['speaker_notes' => $ready['materials']['presentation_slides'][1]['speaker_notes']]]]];
        foreach (['handouts', 'presentation_slides'] as $missing) {
            $invalid = $patch;
            unset($invalid['materials'][$missing]);
            try {
                $planner->validatePatch($invalid, ['materials'], $schema, $request, $plan, $fields);
                $this->fail('Missing damaged child accepted');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('REPAIR_SCHEMA', $e->errors());
            }
        }
        $planner->validatePatch($patch, ['materials'], $schema, $request, $plan, $fields);
        $merged = $planner->merge($original, $patch, ['materials'], $plan);
        $this->assertSame($ready, $merged);
        app(GenerationOutputValidator::class)->validate($merged);
        $this->assertSame('passed', app(PedagogicalQualityGate::class)->assess($merged)['technical_checks']);
        $patch['materials']['printable_cards'] = $ready['materials']['printable_cards'];
        $this->expectException(ValidationException::class);
        $planner->validatePatch($patch, ['materials'], $schema, $request, $plan, $fields);
    }

    public function test_actual_indexed_leak_requires_model_written_closed_display_instruction(): void
    {
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/llm0011/v151-indexed-leak.json')), true);
        $request = LiteLlmProviderTest::requestFixture();
        $request->calendar_event_id = 'synthetic-calendar-event';
        $schema = json_decode(file_get_contents(base_path('docs/generation-output.schema.json')), true);
        $planner = app(ScenarioRepairPlanner::class);
        $plan = $planner->slidePlan($fixture['payload'], $fixture['errors'], $schema, $request);
        $this->assertTrue($plan['slide_2']['defer_until_solution']);
        $note = LiteLlmProviderTest::readyPayload($request)['materials']['presentation_slides'][0]['speaker_notes'];
        $patch = ['materials' => ['presentation_slides' => ['slide_2' => ['speaker_notes' => $note]]]];
        try {
            $planner->validatePatch($patch, ['materials'], $schema, $request, $plan);
            $this->fail('A preserved numeric slide was accepted without a closed display instruction');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('REPAIR_SCHEMA', $e->errors());
        }
        $patch['materials']['presentation_slides']['slide_2']['speaker_notes'] = 'Показывать только после общего решения. '.$note;
        $planner->validatePatch($patch, ['materials'], $schema, $request, $plan);
        $merged = $planner->merge($fixture['payload'], $patch, ['materials'], $plan);
        $this->assertSame($fixture['payload']['materials']['presentation_slides'][2]['bullet_points'], $merged['materials']['presentation_slides'][2]['bullet_points']);
        $codes = array_column(app(PedagogicalQualityGate::class)->assess($merged)['findings'], 'code');
        $this->assertNotContains('DISTRIBUTED_SLIDE_LEAK', $codes);
        app(GenerationOutputValidator::class)->validate($merged);
    }

    public function test_actual_generic_handout_and_extended_json_tail_are_blocked(): void
    {
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/llm0011/v151-generic-handout.json')), true);
        $gate = app(PedagogicalQualityGate::class);
        $codes = array_column($gate->assess($fixture['payload'])['findings'], 'code');
        $this->assertContains('JSON_FRAGMENT_IN_TEXT', $codes);
        $this->assertContains('READY_HANDOUT_RESOURCE_CONTENT', $codes);
        $payload = LiteLlmProviderTest::readyPayload(LiteLlmProviderTest::requestFixture());
        $this->assertSame('passed', $gate->assess($payload)['technical_checks']);
        $payload['materials']['handouts'][0]['template_content'] .= ' |}]}}';
        $this->assertContains('JSON_FRAGMENT_IN_TEXT', array_column($gate->assess($payload)['findings'], 'code'));
    }

    public function test_answer_on_checker_front_is_blocked_without_requiring_all_task_conditions(): void
    {
        $payload = LiteLlmProviderTest::readyPayload(LiteLlmProviderTest::requestFixture());
        $payload['materials']['printable_cards'][3]['front_text'] .= ' Используйте числовой ключ: 3 комплекта, расход 15/9, остаток 3/3.';
        $review = app(PedagogicalQualityGate::class)->assess($payload);
        $this->assertSame('failed', $review['technical_checks']);
        $finding = array_values(array_filter($review['findings'], fn ($f) => $f['code'] === 'DISTRIBUTED_ANSWER_LEAK'));
        $this->assertCount(1, $finding);
        $this->assertSame('$.materials.printable_cards.3.front_text', $finding[0]['path']);
        $schema = json_decode(file_get_contents(base_path('docs/generation-output.schema.json')), true);
        $this->assertSame(['materials'], app(ScenarioRepairPlanner::class)->sections($payload, ['DISTRIBUTED_ANSWER_LEAK' => ['error']], $schema));
        $actual = json_decode(file_get_contents(base_path('tests/Fixtures/llm0011/v151-front-answer.json')), true);
        $findings = array_filter(app(PedagogicalQualityGate::class)->assess($actual['payload'])['findings'], fn ($f) => $f['code'] === 'DISTRIBUTED_ANSWER_LEAK');
        $this->assertCount(2, $findings);
    }

    public function test_indexed_slide_repair_preserves_all_other_material_and_original_contract(): void
    {
        $request = LiteLlmProviderTest::requestFixture();
        $request->calendar_event_id = 'synthetic-calendar-event';
        $schema = json_decode(file_get_contents(base_path('docs/generation-output.schema.json')), true);
        $original = LiteLlmProviderTest::readyPayload($request);
        $notes = $original['materials']['presentation_slides'][1]['speaker_notes'];
        $original['materials']['presentation_slides'][1]['speaker_notes'] = 'Педагог рассказывает.';
        $planner = app(ScenarioRepairPlanner::class);
        $plan = $planner->slidePlan($original, ['READY_SLIDE_SPEECH_$.materials.presentation_slides.1.speaker_notes' => ['speech']], $schema, $request);
        $this->assertSame(['slide_1' => ['index' => 1, 'full' => false]], $plan);
        $patch = ['materials' => ['presentation_slides' => ['slide_1' => ['speaker_notes' => $notes]]]];
        $planner->validatePatch($patch, ['materials'], $schema, $request, $plan);
        $merged = $planner->merge($original, $patch, ['materials'], $plan);
        $expected = $original;
        $expected['materials']['presentation_slides'][1]['speaker_notes'] = $notes;
        $this->assertSame($expected, $merged);
        app(GenerationOutputValidator::class)->validate($merged);
        $this->assertSame('passed', app(PedagogicalQualityGate::class)->assess($merged)['technical_checks']);
    }

    public function test_missing_third_slide_requires_full_model_supplied_slot(): void
    {
        $request = LiteLlmProviderTest::requestFixture();
        $request->calendar_event_id = 'synthetic-calendar-event';
        $schema = json_decode(file_get_contents(base_path('docs/generation-output.schema.json')), true);
        $original = LiteLlmProviderTest::readyPayload($request);
        $third = array_pop($original['materials']['presentation_slides']);
        $third['slide_number'] = 3;
        $planner = app(ScenarioRepairPlanner::class);
        $plan = $planner->slidePlan($original, [], $schema, $request);
        $this->assertSame(['slide_2' => ['index' => 2, 'full' => true]], $plan);
        $patch = ['materials' => ['presentation_slides' => ['slide_2' => $third]]];
        $planner->validatePatch($patch, ['materials'], $schema, $request, $plan);
        $merged = $planner->merge($original, $patch, ['materials'], $plan);
        $this->assertSame([...$original['materials']['presentation_slides'], $third], $merged['materials']['presentation_slides']);
        $this->assertSame($original['materials']['handouts'], $merged['materials']['handouts']);
        app(GenerationOutputValidator::class)->validate($merged);
    }

    public function test_indexed_slide_patch_rejects_unknown_missing_extra_fields_and_array_replacement(): void
    {
        $request = LiteLlmProviderTest::requestFixture();
        $request->calendar_event_id = 'synthetic-calendar-event';
        $schema = json_decode(file_get_contents(base_path('docs/generation-output.schema.json')), true);
        $payload = LiteLlmProviderTest::readyPayload($request);
        $note = $payload['materials']['presentation_slides'][0]['speaker_notes'];
        $plan = ['slide_0' => ['index' => 0, 'full' => false]];
        foreach ([['slide_9' => ['speaker_notes' => $note]], [], ['slide_0' => ['speaker_notes' => $note, 'title' => 'Overwrite']], $payload['materials']['presentation_slides']] as $invalid) {
            try {
                app(ScenarioRepairPlanner::class)->validatePatch(['materials' => ['presentation_slides' => $invalid]], ['materials'], $schema, $request, $plan);
                $this->fail('Invalid indexed slide patch accepted');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('REPAIR_SCHEMA', $e->errors());
            }
        }
        $request->calendar_event_id = null;
        $this->assertSame(['slide_0' => ['index' => 0, 'full' => false]], app(ScenarioRepairPlanner::class)->slidePlan($payload, ['presentation_slides.0' => []], $schema, $request));
    }

    public function test_missing_task_and_short_card_select_only_damaged_sections(): void
    {
        $schema = json_decode(file_get_contents(base_path('docs/generation-output.schema.json')), true);
        $payload = LiteLlmProviderTest::readyPayload(LiteLlmProviderTest::requestFixture());
        unset($payload['pedagogical_tasks']['personal']);
        $payload['materials']['printable_cards'][0]['back_text'] = 'Кратко';
        $keys = app(ScenarioRepairPlanner::class)->sections($payload, [], $schema);
        sort($keys);
        $this->assertSame(['materials', 'pedagogical_tasks'], $keys);
    }

    public function test_patch_preserves_other_sections_exactly(): void
    {
        $original = ['concept' => 'Original text', 'materials' => ['old'], 'stages' => [['id' => 's1']]];
        $merged = app(ScenarioRepairPlanner::class)->merge($original, ['materials' => ['new']], ['materials']);
        $this->assertSame($original['concept'], $merged['concept']);
        $this->assertSame($original['stages'], $merged['stages']);
        $this->assertSame(['new'], $merged['materials']);
    }

    public function test_nested_omission_preserves_handout_and_arrays_replace_atomically(): void
    {
        $before = ['materials' => ['handouts' => [['title' => 'Keep']], 'printable_cards' => [['old' => 1], ['old' => 2]]]];
        $result = app(ScenarioRepairPlanner::class)->merge($before, ['materials' => ['printable_cards' => [['new' => 3]]]], ['materials']);
        $this->assertSame($before['materials']['handouts'], $result['materials']['handouts']);
        $this->assertSame([['new' => 3]], $result['materials']['printable_cards']);
    }

    public function test_nested_extra_field_is_rejected_by_patch_schema(): void
    {
        $schema = json_decode(file_get_contents(base_path('docs/generation-output.schema.json')), true);
        $this->expectException(ValidationException::class);
        app(ScenarioRepairPlanner::class)->validatePatch(['materials' => ['evil' => true]], ['materials'], $schema, LiteLlmProviderTest::requestFixture());
    }

    public function test_actual_cultural_repair_with_short_extra_handouts_remains_rejected(): void
    {
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/llm0011/v142-cultural-repair.json')), true);
        $schema = json_decode(file_get_contents(base_path('docs/generation-output.schema.json')), true);
        $planner = app(ScenarioRepairPlanner::class);
        $this->expectException(ValidationException::class);
        $planner->validatePatch($fixture['patch'], $fixture['sections'], $schema, LiteLlmProviderTest::requestFixture());
    }

    public function test_extra_patch_section_cannot_overwrite_accepted_content(): void
    {
        $this->expectException(ValidationException::class);
        app(ScenarioRepairPlanner::class)->merge(['concept' => 'Safe'], ['materials' => [], 'concept' => 'Injected'], ['materials']);
    }

    public function test_missing_patch_section_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        app(ScenarioRepairPlanner::class)->merge([], [], ['materials']);
    }

    public function test_scale_frames_have_linked_practical_parts_and_exact_duration(): void
    {
        $schema = json_decode(file_get_contents(base_path('docs/generation-output.schema.json')), true);
        foreach (['all_school' => [8, 5, 60], 'family_community' => [6, 3, 90]] as $scale => [$stages, $activities, $minutes]) {
            $request = LiteLlmProviderTest::requestFixture($scale);
            if ($scale === 'all_school') {
                $request->participants_count = 150;
            }
            $frame = app(ScenarioOutputTemplate::class)->build($schema, $request, 'personalized');
            $this->assertCount($stages, $frame['stages']);
            $this->assertCount($activities, $frame['activities']);
            $this->assertSame($minutes, array_sum(array_column($frame['stages'], 'duration_minutes')));
            foreach ($frame['activities'] as $activity) {
                $this->assertContains($activity['target_stage_id'], array_column($frame['stages'], 'id'));
            }
            if ($scale === 'all_school') {
                $this->assertSame(15, array_sum(array_column(array_filter($frame['roles'], fn ($r) => $r['target_participants'] === 'students'), 'group_size')));
            }
        }
    }

    public function test_actual_previous_family_is_blocked_for_incomplete_workshop(): void
    {
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/llm0011/v131-family.json')), true);
        $review = app(PedagogicalQualityGate::class)->assess($fixture['payload']);
        $codes = array_column(array_filter($review['findings'], fn ($f) => $f['severity'] === 'error'), 'code');
        $this->assertContains('FAMILY_WORKSHOP', $codes);
        $this->assertContains('FAMILY_INCLUSION_EXPLICIT', $codes);
    }

    public function test_actual_discovery_class_cannot_leak_all_facts_or_omit_solution_and_plans(): void
    {
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/llm0011/v140-class.json')), true);
        $review = app(PedagogicalQualityGate::class)->assess($fixture['payload']);
        $codes = array_column($review['findings'], 'code');
        $this->assertContains('DISTRIBUTED_SLIDE_LEAK', $codes);
        $this->assertContains('READY_CARD_RESOURCE_KEY', $codes);
        $this->assertContains('MECHANISM_TWO_PLANS', $codes);
    }

    public function test_actual_discovery_cannot_release_solution_early_or_promise_unwritten_slide_speech(): void
    {
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/llm0011/v150-class.json')), true);
        $review = app(PedagogicalQualityGate::class)->assess($fixture['payload']);
        $codes = array_column($review['findings'], 'code');
        $this->assertContains('MATERIALS_RELEASE_ORDER', $codes);
        $this->assertContains('READY_SLIDE_SPEECH', $codes);
    }

    public function test_actual_school_needs_concrete_capacity_and_route_not_just_keywords(): void
    {
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/llm0011/v151-school.json')), true);
        $codes = array_column(app(PedagogicalQualityGate::class)->assess($fixture['payload'])['findings'], 'code');
        $this->assertContains('SCHOOL_LOGISTICS_PLAN', $codes);
        $request = LiteLlmProviderTest::requestFixture('all_school');
        $request->participants_count = 150;
        $payload = LiteLlmProviderTest::readyPayload($request);
        $this->assertSame('passed', app(PedagogicalQualityGate::class)->assess($payload)['technical_checks']);
    }

    public function test_running_job_is_not_requeued_inside_its_budget_and_stale_job_recovers_once(): void
    {
        Queue::fake();
        $school = School::factory()->create(['name' => 'LLM0011 budget regression synthetic school']);
        $teacher = User::factory()->create(['name' => 'LLM0011 budget regression synthetic teacher']);
        SchoolMembership::create(['school_id' => $school->id, 'user_id' => $teacher->id, 'role' => 'teacher', 'is_default' => true]);
        $request = LiteLlmProviderTest::requestFixture();
        $request->school_id = $school->id;
        $request->user_id = $teacher->id;
        $request->save();
        $url = '/api/v1/scenario-requests/'.$request->id.'/generate';
        $headers = ['X-School-Id' => $school->id, 'Idempotency-Key' => (string) Str::uuid()];
        $first = $this->actingAs($teacher, 'web')->postJson($url, [], $headers)->assertStatus(202);
        $run = GenerationRun::findOrFail($first->json('data.run_id'));
        $run->update(['status' => 'running', 'created_at' => now()->subSeconds(400), 'sanitized_prompt' => json_encode(['execution_started_at' => now()->subSeconds(400)->toIso8601String()])]);
        $headers['Idempotency-Key'] = (string) Str::uuid();
        $this->postJson($url, [], $headers)->assertStatus(409)->assertJsonPath('error.code', 'GENERATION_ALREADY_IN_PROGRESS');
        $this->assertSame('running', $run->fresh()->status);
        $this->assertSame(1, GenerationRun::where('scenario_request_id', $request->id)->count());
        $run->update(['sanitized_prompt' => json_encode(['execution_started_at' => now()->subSeconds(500)->toIso8601String()])]);
        $headers['Idempotency-Key'] = (string) Str::uuid();
        $this->postJson($url, [], $headers)->assertStatus(202);
        $this->assertSame('GENERATION_TIMED_OUT', $run->fresh()->error_code);
        $this->assertSame(2, GenerationRun::where('scenario_request_id', $request->id)->count());
        Queue::assertPushed(ExecuteGenerationRunJob::class, 2);
    }

    public function test_school_repair_requires_the_methodology_field_instead_of_echoing_adaptation(): void
    {
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/llm0011/v151-school-echo.json')), true);
        $schema = json_decode(file_get_contents(base_path('docs/generation-output.schema.json')), true);
        $request = LiteLlmProviderTest::requestFixture('all_school');
        $request->participants_count = 150;
        $planner = app(ScenarioRepairPlanner::class);
        try {
            $planner->validatePatch($fixture['patch'], ['teacher_instructions'], $schema, $request);
            $this->fail('Unchanged adaptation was accepted as a route repair');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('REPAIR_SCHEMA', $e->errors());
        }
        $ready = LiteLlmProviderTest::readyPayload($request);
        $planner->validatePatch(['teacher_instructions' => ['methodological_recommendations' => $ready['teacher_instructions']['methodological_recommendations']]], ['teacher_instructions'], $schema, $request);
        $planner->validatePatch($fixture['patch'], ['teacher_instructions'], $schema, LiteLlmProviderTest::requestFixture());
        $this->assertTrue(true);
    }

    public function test_actual_repair_cannot_fix_a_slide_leak_by_deleting_the_third_slide(): void
    {
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/llm0011/v151-deleted-slide.json')), true);
        $this->assertCount(1, $fixture['patch']['materials']['presentation_slides']);
        $schema = json_decode(file_get_contents(base_path('docs/generation-output.schema.json')), true);
        try {
            app(ScenarioRepairPlanner::class)->validatePatch($fixture['patch'], $fixture['sections'], $schema, LiteLlmProviderTest::requestFixture());
            $this->fail('A single restored slide was accepted');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('presentation_slides', json_encode($e->errors()));
        }
    }
}
