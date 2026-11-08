<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Llm\GenerationOutputValidator;
use App\Services\Llm\LiteLlmProvider;
use App\Services\Llm\LlmProviderException;
use App\Services\Llm\PedagogicalQualityGate;
use App\Services\Llm\ScenarioQualityValidator;
use App\Services\Llm\ScenarioRepairPlanner;
use App\Services\Llm\ScenarioSemanticValidator;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class OfflineCardRepairTest extends TestCase
{
    private function fixture(): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/llm0012/cultural-repair.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    private function schema(): array
    {
        return json_decode(file_get_contents(base_path('docs/generation-output.schema.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    private function correction(): string
    {
        return 'Вы представляете решение команды. Попросите каждую роль объяснить свой вклад, сопоставьте предложенные планы и подготовьте общий рассказ о том, как команда учла потребности всех участников и проверила ограничения.';
    }

    private function cardPatch(string $front): array
    {
        return ['materials' => ['printable_cards' => ['card_4' => ['front_text' => $front]]]];
    }

    public function test_real_failure_selects_only_leaking_front(): void
    {
        $f = $this->fixture();
        $plan = app(ScenarioRepairPlanner::class)->cardPlan($f['payload'], $f['errors'], $this->schema());
        $this->assertSame(['card_4' => ['index' => 4, 'fields' => ['front_text']]], $plan);
        $this->assertSame(97, mb_strlen($f['failed_patch']['materials']['printable_cards'][4]['front_text']));
        $this->assertContains('DISTRIBUTED_ANSWER_LEAK', array_column(app(PedagogicalQualityGate::class)->assess($f['payload'])['findings'], 'code'));
    }

    public function test_substantive_repair_preserves_every_other_field_and_numeric_answer(): void
    {
        $f = $this->fixture();
        $p = app(ScenarioRepairPlanner::class);
        $plan = $p->cardPlan($f['payload'], $f['errors'], $this->schema());
        $patch = $this->cardPatch($this->correction());
        $p->validatePatch($patch, ['materials'], $this->schema(), LiteLlmProviderTest::requestFixture(), [], ['printable_cards'], $plan);
        $merged = $p->merge($f['payload'], $patch, ['materials'], [], $plan);
        $expected = $f['payload'];
        $expected['materials']['printable_cards'][4]['front_text'] = $this->correction();
        $this->assertSame($expected, $merged);
        $this->assertStringContainsString('3 комплекта, расход 15/9, остаток 3/3', $merged['materials']['printable_cards'][4]['back_text']);
        app(GenerationOutputValidator::class)->validate($merged);
        app(ScenarioSemanticValidator::class)->validate($merged, 45);
        app(ScenarioQualityValidator::class)->validate($merged);
        $report = app(PedagogicalQualityGate::class)->assess($merged);
        $this->assertSame('passed', $report['technical_checks']);
        $this->assertFalse($report['cultural_coverage']['whole_document_verified']);
    }

    public function test_real_failed_patch_and_short_addressed_front_are_rejected(): void
    {
        $f = $this->fixture();
        $p = app(ScenarioRepairPlanner::class);
        $plan = $p->cardPlan($f['payload'], $f['errors'], $this->schema());
        foreach ([$f['failed_patch'], $this->cardPatch($f['failed_patch']['materials']['printable_cards'][4]['front_text'])] as $patch) {
            try {
                $p->validatePatch($patch, ['materials'], $this->schema(), LiteLlmProviderTest::requestFixture(), [], ['printable_cards'], $plan);
                $this->fail('Destructive or short patch accepted');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('REPAIR_SCHEMA', $e->errors());
            }
        }
        $f['payload']['materials']['printable_cards'][4]['front_text'] = $f['failed_patch']['materials']['printable_cards'][4]['front_text'];
        $this->expectException(ValidationException::class);
        app(ScenarioQualityValidator::class)->validate($f['payload']);
    }

    public function test_merge_rejects_overwriting_valid_back(): void
    {
        $f = $this->fixture();
        $p = app(ScenarioRepairPlanner::class);
        $plan = $p->cardPlan($f['payload'], $f['errors'], $this->schema());
        $patch = $this->cardPatch($this->correction());
        $patch['materials']['printable_cards']['card_4']['back_text'] = 'Overwrite';
        $this->expectException(ValidationException::class);
        $p->merge($f['payload'], $patch, ['materials'], [], $plan);
    }

    public function test_missing_closed_key_preserves_all_fronts(): void
    {
        $payload = $this->fixture()['payload'];
        $p = app(ScenarioRepairPlanner::class);
        $plan = $p->cardPlan($payload, ['READY_CARD_RESOURCE_KEY_$.materials' => ['missing']], $this->schema());
        $this->assertSame(['card_4' => ['index' => 4, 'fields' => ['back_text']]], $plan);
        $patch = ['materials' => ['printable_cards' => ['card_4' => ['back_text' => $payload['materials']['printable_cards'][4]['back_text']]]]];
        $p->validatePatch($patch, ['materials'], $this->schema(), LiteLlmProviderTest::requestFixture(), [], ['printable_cards'], $plan);
        $this->assertSame($payload, $p->merge($payload, $patch, ['materials'], [], $plan));
    }

    public function test_empty_unknown_missing_and_extra_fields_fail_closed(): void
    {
        $f = $this->fixture();
        $p = app(ScenarioRepairPlanner::class);
        $plan = $p->cardPlan($f['payload'], $f['errors'], $this->schema());
        foreach ([[], ['card_4' => []], ['card_4' => ['front_text' => '']], ['card_0' => ['front_text' => $this->correction()]], ['card_4' => ['front_text' => $this->correction(), 'back_text' => 'Overwrite']]] as $cards) {
            try {
                $p->validatePatch(['materials' => ['printable_cards' => $cards]], ['materials'], $this->schema(), LiteLlmProviderTest::requestFixture(), [], ['printable_cards'], $plan);
                $this->fail('Invalid patch accepted');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('REPAIR_SCHEMA', $e->errors());
            }
        }
    }

    private function fakeProvider(array $payload, array $patches): void
    {
        Http::preventStrayRequests();
        config(['llm.provider' => 'litellm', 'llm.api_key' => 'unit-only-not-a-live-key', 'llm.model' => 'unit-model',
            'llm.base_url' => 'https://gateway.example.test/v1', 'llm.response_mode' => 'json_schema', 'llm.repair_passes' => count($patches)]);
        $sequence = Http::sequence()->push(LiteLlmProviderTest::completion($payload));
        foreach ($patches as $patch) {
            $sequence->push(LiteLlmProviderTest::completion($patch));
        }
        Http::fake(['https://gateway.example.test/v1/chat/completions' => $sequence]);
    }

    public function test_provider_rechecks_templates_and_provenance_after_both_repairs(): void
    {
        $request = LiteLlmProviderTest::requestFixture();
        $payload = LiteLlmProviderTest::readyPayload($request);
        $payload['materials']['printable_cards'][4]['front_text'] .= ' Числовой ключ: 3 комплекта, расход 15/9, остаток 3/3.';
        $this->fakeProvider($payload, [$this->cardPatch(str_repeat('__LLM_WRITE_FRONT__ ', 10)), $this->cardPatch($this->correction().' В 1612 году Минин и Пожарский освободили Москву.')]);
        try {
            app(LiteLlmProvider::class)->generate($request);
            $this->fail('Unsupported history certified');
        } catch (LlmProviderException $e) {
            $this->assertSame('VALIDATION_FAILED', $e->errorCode);
            $this->assertStringContainsString('UNSUPPORTED_CULTURAL_TEXT', $e->getMessage());
        }
        Http::assertSentCount(3);
    }

    public function test_provider_accepts_substantive_repair_and_preserves_document(): void
    {
        $request = LiteLlmProviderTest::requestFixture();
        $original = LiteLlmProviderTest::readyPayload($request);
        $bad = $original;
        $bad['materials']['printable_cards'][4]['front_text'] .= ' Числовой ключ: 3 комплекта, расход 15/9, остаток 3/3.';
        $this->fakeProvider($bad, [$this->cardPatch($original['materials']['printable_cards'][4]['front_text'])]);
        $result = app(LiteLlmProvider::class)->generate($request);
        $this->assertSame($original, $result['output_payload']);
        Http::assertSentCount(2);
    }
}
