<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\GenerationRun;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\TeacherSignoff;
use App\Models\User;
use App\Services\Llm\CulturalTextCoverage;
use App\Services\Llm\LiteLlmProvider;
use App\Services\Llm\PedagogicalQualityGate;
use App\Services\Llm\PedagogicalTaskBrief;
use App\Services\Llm\PrintableTextFormatter;
use App\Services\Llm\ServerNormalizationService;
use App\Services\ScenarioPersistenceService;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PedagogicalQualityGateTest extends TestCase
{
    private function payload(string $name = 'class'): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/llm0011/'.$name.'.json')), true)['payload'];
    }

    private function codes(array $report): array
    {
        return array_column($report['findings'], 'code');
    }

    private function trusted(): array
    {
        return [['id' => 'fixture-live-claim', 'claim_text' => '4 ноября — День народного единства в Российской Федерации.',
            'evidence_records' => [['event_source_id' => 'fixture-source', 'source_title' => 'Нормативный источник', 'citation_locator' => 'Статья 1', 'verification_status' => 'verified_authentic']]]];
    }

    public function test_original_live_budget_time_and_baskets_are_rejected(): void
    {
        $gate = app(PedagogicalQualityGate::class);
        $class = $gate->assess($this->payload());
        $this->assertSame('blocked', $class['status']);
        $this->assertContains('BUDGET_MAXIMUM', $this->codes($class));
        $this->assertContains('TIME_CONTRADICTION', $this->codes($class));
        $this->assertContains('LITERAL_NEWLINE', $this->codes($class));
        $this->assertContains('BASKET_CONTRADICTION', $this->codes($gate->assess($this->payload('school'))));
        $this->assertContains('AGE_MECHANISM_REVIEW', $this->codes($gate->assess($this->payload('family'))));
    }

    public function test_correct_budget_and_explicit_time_impossibility_pass_these_rules(): void
    {
        $p = $this->payload();
        $p['materials']['printable_cards'][0]['back_text'] = 'Максимум 7 предметов: 6 открыток по 15 и один сувенир за 30. Проверка: 6*15=90, 90+30=120. Купить восемь нельзя при хотя бы одном сувенире.';
        $p['materials']['printable_cards'][1]['back_text'] = 'Выполнить последовательно невозможно: нужно 135 минут, доступно 120. Не хватает 15 минут; договоритесь о переносе задания, не обещайте успеть.';
        $codes = $this->codes(app(PedagogicalQualityGate::class)->assess($p));
        $this->assertNotContains('BUDGET_MAXIMUM', $codes);
        $this->assertNotContains('TIME_CONTRADICTION', $codes);
    }

    public function test_budget_solver_uses_conditions_not_hardcoded_seven(): void
    {
        $p = LiteLlmProviderTest::readyPayload(LiteLlmProviderTest::requestFixture());
        $p['materials']['printable_cards'][0]['front_text'] = 'В магазине открытки по 10 рублей и сувениры по 20 рублей. У вас есть 100 рублей. Купите хотя бы один предмет каждого вида. Какой максимум?';
        $p['materials']['printable_cards'][0]['back_text'] = 'Всего 7 предметов — это максимальное количество товаров.';
        $this->assertContains('BUDGET_MAXIMUM', $this->codes(app(PedagogicalQualityGate::class)->assess($p)));
        $p['materials']['printable_cards'][0]['back_text'] = 'Всего 9 предметов: восемь открыток и один сувенир. Это максимальное количество.';
        $this->assertNotContains('BUDGET_MAXIMUM', $this->codes(app(PedagogicalQualityGate::class)->assess($p)));
    }

    public static function textPaths(): array
    {
        return array_map(fn ($p) => [$p], ['concept', 'stages.0.teacher_actions', 'stages.0.dialogue_prompts.0', 'activities.0.rules_and_instructions', 'materials.printable_cards.0.back_text', 'materials.handouts.0.template_content', 'materials.presentation_slides.0.speaker_notes', 'materials.presentation_slides.0.bullet_points.0', 'teacher_instructions.methodological_recommendations.0', 'roles.0.description']);
    }

    public function test_redistribution_of_fifteen_cannot_claim_only_two_sheets_remain(): void
    {
        $payload = LiteLlmProviderTest::readyPayload(LiteLlmProviderTest::requestFixture());
        $payload['activities'][0]['rules_and_instructions'] = 'Дополнительный лист одной зоне (6, 3; 5, 3; 4, 3). Второй план оставляет только 2 листа в резерве вместо требуемых 3.';
        $payload['materials']['handouts'][0]['template_content'] = "Зона 1: 6 листов, 3 карандаша\nЗона 2: 5 листов, 3 карандаша\nЗона 3: 4 листа, 3 карандаша\nРезерв соблюден: нет (только 2 листа в резерве)";

        $report = app(PedagogicalQualityGate::class)->assess($payload);
        $findings = array_values(array_filter($report['findings'], fn ($finding) => $finding['code'] === 'RESOURCE_PLAN_RESERVE_CONTRADICTION'));
        $paths = array_column($findings, 'path');
        sort($paths);

        $this->assertSame('blocked', $report['status']);
        $this->assertSame(['$.activities.0.rules_and_instructions', '$.materials.handouts.0.template_content'], $paths);
    }

    public function test_extra_sheet_and_redistribution_with_correct_explanations_are_not_rejected(): void
    {
        $payload = LiteLlmProviderTest::readyPayload(LiteLlmProviderTest::requestFixture());
        $payload['activities'][0]['rules_and_instructions'] = 'Дополнительный лист одной зоне (6, 3; 5, 3; 5, 3). Второй план оставляет только 2 листа в резерве вместо требуемых 3.';
        $payload['materials']['handouts'][0]['template_content'] = "Зона 1: 6 листов, 3 карандаша\nЗона 2: 5 листов, 3 карандаша\nЗона 3: 4 листа, 3 карандаша\nОстаток: 3 листа. Резерв соблюден, но третья зона недополучает лист.";

        $report = app(PedagogicalQualityGate::class)->assess($payload);

        $this->assertNotContains('RESOURCE_PLAN_RESERVE_CONTRADICTION', $this->codes($report));
    }

    #[DataProvider('textPaths')]
    public function test_history_is_detected_in_every_text_section_even_with_empty_claim_array(string $path): void
    {
        $p = LiteLlmProviderTest::readyPayload(LiteLlmProviderTest::requestFixture());
        data_set($p, $path, 'В 1612 году Минин и Пожарский освободили Москву.');
        $p['cultural_claims'] = [];
        $r = app(PedagogicalQualityGate::class)->assess($p, $this->trusted());
        $this->assertSame('blocked', $r['status']);
        $finding = collect($r['findings'])->first(fn ($f) => $f['code'] === 'UNSUPPORTED_CULTURAL_TEXT' && $f['path'] === '$.'.$path);
        $this->assertSame('$.'.$path, $finding['path']);
        $this->assertStringContainsString('1612', $finding['text']);
        $this->assertFalse($r['cultural_coverage']['whole_document_verified']);
    }

    public function test_exact_claim_does_not_certify_adjacent_history_or_other_text(): void
    {
        $coverage = app(CulturalTextCoverage::class)->assess(['$.date' => $this->trusted()[0]['claim_text'], '$.notes' => 'Минин и Пожарский возглавили ополчение.'], $this->trusted());
        $this->assertCount(1, $coverage['supported_assertions']);
        $this->assertCount(1, $coverage['findings']);
        $this->assertSame('requires_review', $coverage['status']);
        $this->assertFalse($coverage['complete']);
    }

    public function test_revoked_or_missing_evidence_no_longer_supports_exact_claim(): void
    {
        $trusted = $this->trusted();
        $trusted[0]['evidence_records'][0]['verification_status'] = 'unverified';
        $r = app(CulturalTextCoverage::class)->assess(['$.notes' => $trusted[0]['claim_text']], $trusted);
        $this->assertCount(0, $r['supported_assertions']);
        $this->assertSame('requires_review', $r['status']);
        $r = app(CulturalTextCoverage::class)->assess(['$.notes' => 'Общеизвестное утверждение без имени и года.'], []);
        $this->assertSame('requires_review', $r['status']);
        $this->assertFalse($r['whole_document_verified']);
    }

    public function test_empty_cards_markers_handouts_and_numeric_equality_cannot_pass(): void
    {
        $p = LiteLlmProviderTest::readyPayload(LiteLlmProviderTest::requestFixture());
        $p['materials']['printable_cards'][0]['front_text'] = '';
        $p['materials']['handouts'][0]['template_content'] = str_repeat('_', 220).' Пример: _______';
        $p['concept'] = '__LLM_WRITE_CONCEPT__';
        $p['activities'][0]['rules_and_instructions'] = 'Проверка: 12 / 4 = 5.';
        $codes = $this->codes(app(PedagogicalQualityGate::class)->assess($p));
        foreach (['EMPTY_CARD', 'EMPTY_HANDOUT', 'UNFILLED_MARKER', 'ARITHMETIC_EQUALITY'] as $code) {
            $this->assertContains($code, $codes);
        }
        $this->expectException(ValidationException::class);
        app(PedagogicalQualityGate::class)->validate($p);
    }

    public function test_good_structure_never_becomes_automatic_expert_or_cultural_pass(): void
    {
        $p = LiteLlmProviderTest::readyPayload(LiteLlmProviderTest::requestFixture());
        $r = app(PedagogicalQualityGate::class)->validate($p);
        $this->assertSame('passed', $r['technical_checks']);
        $this->assertSame('requires_review', $r['status']);
        $this->assertSame('not_performed', $r['expert_assessment']);
        $this->assertTrue($r['teacher_signoff_required']);
    }

    public function test_school_logistics_missing_is_an_error_not_a_pass(): void
    {
        $p = LiteLlmProviderTest::readyPayload(LiteLlmProviderTest::requestFixture());
        $p['format']['scale'] = 'all_school';
        $p['target_audience']['participants_count_max'] = 150;
        $this->assertContains('SCHOOL_LOGISTICS_ROUNDS', $this->codes(app(PedagogicalQualityGate::class)->assess($p)));
    }

    public function test_provider_stores_quality_warnings_with_real_prompt_metadata(): void
    {
        config(['llm.provider' => 'litellm', 'llm.api_key' => 'unit-only-key', 'llm.model' => 'unit-model', 'llm.base_url' => 'https://example.test/v1', 'llm.repair_passes' => 0]);
        Http::preventStrayRequests();
        $request = LiteLlmProviderTest::requestFixture();
        Http::fake(['*' => Http::response(LiteLlmProviderTest::completion(LiteLlmProviderTest::readyPayload($request)))]);
        $r = app(LiteLlmProvider::class)->generate($request);
        $audit = json_decode($r['sanitized_prompt'], true);
        $this->assertSame('requires_review', $audit['quality_gate']['status']);
        $this->assertSame('school-methodist-1.5.2', $r['prompt_version']);
        Http::assertSentCount(1);
    }

    public function test_legacy_litellm_revision_cannot_bypass_quality_with_old_moderation_pass(): void
    {
        $user = User::factory()->create();
        $school = School::factory()->create();
        SchoolMembership::create(['school_id' => $school->id, 'user_id' => $user->id, 'role' => 'teacher', 'is_default' => true]);
        $request = LiteLlmProviderTest::requestFixture();
        $request->school_id = $school->id;
        $request->user_id = $user->id;
        $request->save();
        $run = GenerationRun::create(['scenario_request_id' => $request->id, 'provider_name' => 'litellm', 'model_identifier' => 'GigaChat-2-Max', 'prompt_version' => 'school-methodist-1.2.0', 'schema_version' => '1.0.0', 'status' => 'completed', 'created_at' => now()]);
        $raw = $this->payload();
        $normalized = app(ServerNormalizationService::class)->normalize($raw, $request->context_snapshot);
        $scenario = app(ScenarioPersistenceService::class)->persist($request, $run, $normalized);
        $revision = $scenario->currentRevision;
        $revision->moderationState()->update(['status' => 'verification_passed', 'p0_violations_count' => 0]);
        $this->assertTrue(app(PedagogicalQualityGate::class)->blocksUse($revision));
        $this->actingAs($user, 'web')->postJson('/api/v1/scenarios/'.$scenario->id.'/revisions/1/signoff', ['checklist_confirmed' => ['safety_checked' => true, 'timing_fit' => true, 'cultural_context_approved' => true]], ['X-School-Id' => $school->id])->assertStatus(422)->assertJsonPath('error.code', 'QUALITY_GATE_BLOCKED');
        $this->assertSame(0, TeacherSignoff::where('revision_id', $revision->id)->count());
    }

    public function test_formatting_repair_preserves_content_and_records_original_text(): void
    {
        $text = 'Условие: первая строка'.'\\n'.'Решение: вторая строка';
        $p = ['concept' => $text, 'materials' => ['handouts' => [['template_content' => $text]]]];
        $result = app(PrintableTextFormatter::class)->repair($p);
        $this->assertSame($text, $result['payload']['concept']);
        $this->assertSame("Условие: первая строка\nРешение: вторая строка", $result['payload']['materials']['handouts'][0]['template_content']);
        $this->assertSame($text, $result['changes'][0]['before']);
        $this->assertSame('$.materials.handouts.0.template_content', $result['changes'][0]['path']);
    }

    public function test_numeric_print_escape_preserves_values_and_nonmaterial_strings(): void
    {
        $text = 'Расход 15\/9; остаток 3\/3; https:\/\/example.org';
        $result = app(PrintableTextFormatter::class)->repair(['concept' => $text, 'materials' => ['handouts' => [['template_content' => $text]]]]);
        $this->assertSame($text, $result['payload']['concept']);
        $this->assertSame('Расход 15/9; остаток 3/3; https:\/\/example.org', $result['payload']['materials']['handouts'][0]['template_content']);
        $this->assertSame($text, $result['changes'][0]['before']);
    }

    public function test_introductory_stage_cannot_show_target_solution_before_exchange(): void
    {
        $payload = LiteLlmProviderTest::readyPayload(LiteLlmProviderTest::requestFixture());
        $payload['stages'][0]['teacher_actions'] = 'Педагог показывает пример решения задачи.';
        $gate = app(PedagogicalQualityGate::class);
        $this->assertContains('MATERIALS_RELEASE_EARLY_EXAMPLE', array_column($gate->assess($payload)['findings'], 'code'));
        $payload['stages'][0]['teacher_actions'] = 'Педагог показывает процедуру обмена на нейтральном примере без чисел и решения основной задачи.';
        $this->assertNotContains('MATERIALS_RELEASE_EARLY_EXAMPLE', array_column($gate->assess($payload)['findings'], 'code'));
    }

    public function test_task_brief_conserves_resources_and_school_transition_budget(): void
    {
        $r = LiteLlmProviderTest::requestFixture('all_school');
        $r->participants_count = 150;
        $brief = app(PedagogicalTaskBrief::class)->forRequest($r);
        $f = $brief['facts'];
        $a = $brief['verified_solution'];
        $this->assertSame($f['paper_available'], $a['paper_used'] + $a['paper_remaining']);
        $this->assertSame($f['pencils_available'], $a['pencils_used'] + $a['pencils_remaining']);
        $this->assertSame(10, $brief['logistics']['groups']);
        $this->assertSame(30, $brief['logistics']['main_stage_minutes']);
    }

    public function test_typed_server_timestamp_is_metadata_but_prose_is_not_exempt(): void
    {
        $path = '$.cultural_claims.0.evidence_records.0.verified_at';
        $coverage = app(CulturalTextCoverage::class)->assess([$path => '2026-10-30T16:56:09+00:00'], []);
        $this->assertSame([], $coverage['findings']);
        $this->assertSame(1, $coverage['scanned_string_count']);
        $this->assertSame('requires_review', $coverage['status']);
        $coverage = app(CulturalTextCoverage::class)->assess([$path => 'В 1612 году Минин и Пожарский возглавили ополчение.'], []);
        $this->assertSame('UNSUPPORTED_CULTURAL_TEXT', $coverage['findings'][0]['code']);
    }

    public function test_stage_direction_with_complete_quoted_speech_is_ready_but_direction_alone_is_not(): void
    {
        $p = LiteLlmProviderTest::readyPayload(LiteLlmProviderTest::requestFixture());
        $p['materials']['presentation_slides'][0]['speaker_notes'] = 'Педагог объясняет цель мастерской. Прямая речь: «Добро пожаловать! Сегодня мы будем работать в командах, распределять роли и обмениваться сведениями, чтобы согласовать справедливый план. Выслушаем каждого участника и вместе проверим ограничения.»';
        $this->assertNotContains('READY_SLIDE_SPEECH', $this->codes(app(PedagogicalQualityGate::class)->assess($p)));
        $p['materials']['presentation_slides'][0]['speaker_notes'] = 'Педагог объясняет цель мастерской, рассказывает о распределении ролей и необходимости сотрудничества, затем подробно поясняет все правила и отвечает на вопросы участников.';
        $this->assertContains('READY_SLIDE_SPEECH', $this->codes(app(PedagogicalQualityGate::class)->assess($p)));
    }
}
