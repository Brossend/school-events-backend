<?php

declare(strict_types=1);

namespace App\Services\Llm;

use App\Models\ScenarioRequest;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class LiteLlmProvider implements VariantLlmProviderInterface
{
    public function generate(ScenarioRequest $request): array
    {
        return $this->generateVariant($request, 'personalized');
    }

    public function models(): array
    {
        LlmRuntime::metadata();
        $response = $this->send('GET', '/models');
        $data = $response['body']['data'] ?? null;
        if (! is_array($data)) {
            throw new LlmProviderException('LLM_INVALID_RESPONSE', 'Шлюз не вернул список моделей.');
        }

        return array_values(array_filter(array_column($data, 'id'), 'is_string'));
    }

    public function generateVariant(ScenarioRequest $request, string $mode): array
    {
        $started = hrtime(true);
        $qualityTrusted = app(TrustedGenerationContext::class)->forRequest($request)['cultural_provenance']['claims'] ?? [];
        $first = $this->complete($request, $mode, null, min((float) config('llm.timeout'), (float) config('llm.total_timeout')));
        try {
            app(GenerationOutputValidator::class)->validate($first['output_payload']);
            app(ScenarioSemanticValidator::class)->validate($first['output_payload'], $request->context_snapshot);
            app(RequestedScenarioValidator::class)->validate($first['output_payload'], $request, $mode);
            app(ScenarioQualityValidator::class)->validate($first['output_payload']);
            $first = $this->withQualityReport($first, $qualityTrusted);
        } catch (ValidationException $e) {
            if ((int) config('llm.repair_passes') < 1) {
                throw new LlmProviderException('VALIDATION_FAILED', json_encode($e->errors(), JSON_UNESCAPED_UNICODE), $first);
            }
            $schema = json_decode(file_get_contents(base_path('docs/generation-output.schema.json')), true);
            $repairErrors = app(ScenarioRepairPlanner::class)->errors($first['output_payload'], $e->errors(), $schema, $request);
            $repairSections = app(ScenarioRepairPlanner::class)->sections($first['output_payload'], $repairErrors, $schema);
            $slidePlan = app(ScenarioRepairPlanner::class)->slidePlan($first['output_payload'], $repairErrors, $schema, $request);
            $cardPlan = app(ScenarioRepairPlanner::class)->cardPlan($first['output_payload'], $repairErrors, $schema);
            $materialFields = app(ScenarioRepairPlanner::class)->materialFields($first['output_payload'], $repairErrors, $schema, $slidePlan);
            $remaining = min((float) config('llm.timeout'), (float) config('llm.total_timeout') - (hrtime(true) - $started) / 1e9);
            if ($remaining <= 0) {
                $first['latency_ms'] = (int) round((hrtime(true) - $started) / 1e6);
                throw new LlmProviderException('LLM_TIMEOUT', 'Общий бюджет исчерпан до начала repair; новый HTTP-запрос не отправлен.', $first);
            }
            try {
                $second = $this->complete($request, $mode, ['output' => $first['output_payload'], 'errors' => $repairErrors, 'sections' => $repairSections, 'slide_plan' => $slidePlan, 'card_plan' => $cardPlan, 'material_fields' => $materialFields], $remaining);
            } catch (LlmProviderException $failure) {
                throw new LlmProviderException($failure->errorCode, $failure->getMessage(), [...$failure->metadata,
                    'prompt_tokens' => null, 'completion_tokens' => null,
                    'sanitized_prompt' => json_encode(['first_prompt' => $first['sanitized_prompt'], 'repair_prompt' => $failure->metadata['sanitized_prompt'] ?? null,
                        'original_output' => $first['output_payload'], 'repair_sections' => $repairSections, 'repair_errors' => $repairErrors,
                        'known_first_usage' => ['prompt_tokens' => $first['prompt_tokens'], 'completion_tokens' => $first['completion_tokens']],
                        'repair_usage' => ['prompt_tokens' => $failure->metadata['prompt_tokens'] ?? null, 'completion_tokens' => $failure->metadata['completion_tokens'] ?? null]], JSON_UNESCAPED_UNICODE),
                    'latency_ms' => (int) round((hrtime(true) - $started) / 1e6)]);
            }
            foreach (['prompt_tokens', 'completion_tokens'] as $field) {
                $second[$field] = $first[$field] !== null && $second[$field] !== null ? $first[$field] + $second[$field] : null;
            }
            $second['sanitized_prompt'] = json_encode(['first_prompt' => $first['sanitized_prompt'], 'original_output' => $first['output_payload'], 'repair_prompt' => $second['sanitized_prompt'], 'repair_sections' => $repairSections, 'slide_plan' => $slidePlan, 'raw_repair_payload' => $second['output_payload']], JSON_UNESCAPED_UNICODE);
            $second['latency_ms'] = (int) round((hrtime(true) - $started) / 1e6);
            try {
                $patch = $second['output_payload'];
                $second['output_payload'] = $first['output_payload'];
                app(ScenarioRepairPlanner::class)->validatePatch($patch, $repairSections, $schema, $request, $slidePlan, $materialFields, $cardPlan);
                $second['output_payload'] = app(ScenarioRepairPlanner::class)->merge($first['output_payload'], $patch, $repairSections, $slidePlan, $cardPlan);
                app(GenerationOutputValidator::class)->validate($second['output_payload']);
                app(ScenarioSemanticValidator::class)->validate($second['output_payload'], $request->context_snapshot);
                app(RequestedScenarioValidator::class)->validate($second['output_payload'], $request, $mode);
                app(ScenarioQualityValidator::class)->validate($second['output_payload']);
                $second = $this->withQualityReport($second, $qualityTrusted);
            } catch (ValidationException $invalid) {
                if ((int) config('llm.repair_passes') === 2) {
                    return $this->finalRepair($request, $mode, $second, $invalid, $schema, $qualityTrusted, $started);
                }
                throw new LlmProviderException('VALIDATION_FAILED', json_encode($invalid->errors(), JSON_UNESCAPED_UNICODE), $second);
            }

            return $second;
        }

        return $first;
    }

    private function finalRepair(ScenarioRequest $request, string $mode, array $previous, ValidationException $error, array $schema, array $trusted, int $started): array
    {
        $planner = app(ScenarioRepairPlanner::class);
        $errors = $planner->errors($previous['output_payload'], $error->errors(), $schema, $request);
        $sections = $planner->sections($previous['output_payload'], $errors, $schema);
        $slidePlan = $planner->slidePlan($previous['output_payload'], $errors, $schema, $request);
        $cardPlan = $planner->cardPlan($previous['output_payload'], $errors, $schema);
        $materialFields = $planner->materialFields($previous['output_payload'], $errors, $schema, $slidePlan);
        $remaining = min((float) config('llm.timeout'), (float) config('llm.total_timeout') - (hrtime(true) - $started) / 1e9);
        if ($remaining <= 0) {
            $previous['latency_ms'] = (int) round((hrtime(true) - $started) / 1e6);
            $previous['sanitized_prompt'] = json_encode(['previous_attempts' => json_decode($previous['sanitized_prompt'], true),
                'budget_exhausted_before_final_repair' => true,
                'known_previous_usage' => ['prompt_tokens' => $previous['prompt_tokens'], 'completion_tokens' => $previous['completion_tokens']]], JSON_UNESCAPED_UNICODE);
            throw new LlmProviderException('LLM_TIMEOUT', 'Общий бюджет исчерпан до второго repair; новый HTTP-запрос не отправлен.', $previous);
        }
        try {
            $last = $this->complete($request, $mode, ['output' => $previous['output_payload'], 'errors' => $errors, 'sections' => $sections, 'slide_plan' => $slidePlan, 'card_plan' => $cardPlan, 'material_fields' => $materialFields], $remaining);
        } catch (LlmProviderException $failure) {
            throw new LlmProviderException($failure->errorCode, $failure->getMessage(), [...$failure->metadata,
                'prompt_tokens' => null, 'completion_tokens' => null,
                'sanitized_prompt' => json_encode(['previous_attempts' => json_decode($previous['sanitized_prompt'], true),
                    'previous_output' => $previous['output_payload'], 'final_repair_prompt' => $failure->metadata['sanitized_prompt'] ?? null,
                    'known_previous_usage' => ['prompt_tokens' => $previous['prompt_tokens'], 'completion_tokens' => $previous['completion_tokens']],
                    'repair_sections' => $sections, 'repair_errors' => $errors], JSON_UNESCAPED_UNICODE),
                'latency_ms' => (int) round((hrtime(true) - $started) / 1e6)]);
        }
        $patch = $last['output_payload'];
        foreach (['prompt_tokens', 'completion_tokens'] as $field) {
            $last[$field] = $previous[$field] !== null && $last[$field] !== null ? $previous[$field] + $last[$field] : null;
        }
        $last['sanitized_prompt'] = json_encode(['previous_attempts' => json_decode($previous['sanitized_prompt'], true),
            'previous_output' => $previous['output_payload'], 'final_repair_prompt' => $last['sanitized_prompt'],
            'repair_sections' => $sections, 'slide_plan' => $slidePlan, 'repair_errors' => $errors, 'raw_repair_payload' => $patch], JSON_UNESCAPED_UNICODE);
        $last['latency_ms'] = (int) round((hrtime(true) - $started) / 1e6);
        $last['output_payload'] = $previous['output_payload'];
        try {
            $planner->validatePatch($patch, $sections, $schema, $request, $slidePlan, $materialFields, $cardPlan);
            $last['output_payload'] = $planner->merge($previous['output_payload'], $patch, $sections, $slidePlan, $cardPlan);
            app(GenerationOutputValidator::class)->validate($last['output_payload']);
            app(ScenarioSemanticValidator::class)->validate($last['output_payload'], $request->context_snapshot);
            app(RequestedScenarioValidator::class)->validate($last['output_payload'], $request, $mode);
            app(ScenarioQualityValidator::class)->validate($last['output_payload']);

            return $this->withQualityReport($last, $trusted);
        } catch (ValidationException $invalid) {
            throw new LlmProviderException('VALIDATION_FAILED', json_encode($invalid->errors(), JSON_UNESCAPED_UNICODE), $last);
        }
    }

    private function withQualityReport(array $result, array $trusted): array
    {
        $report = app(PedagogicalQualityGate::class)->validate($result['output_payload'], $trusted);
        $result['sanitized_prompt'] = json_encode(['generation_prompt' => json_decode($result['sanitized_prompt'], true), 'quality_gate' => $report], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $result;
    }

    private function complete(ScenarioRequest $request, string $mode, ?array $repair, float $budget): array
    {
        $metadata = LlmRuntime::metadata();
        $prompt = app(ScenarioPromptBuilder::class)->build($request, $mode);
        if ($repair !== null) {
            $prompt['schema'] = app(ScenarioRepairPlanner::class)->schema($repair['sections'], $prompt['schema'], $request, $repair['slide_plan'] ?? [], $repair['material_fields'] ?? [], $repair['card_plan'] ?? []);
            // Do not ask for a full schema/frame and a patch simultaneously.
            $prefix = strstr($prompt['messages'][0]['content'], "\nДействующая JSON Schema", true);
            $prompt['messages'][0]['content'] = $prefix."\nОдин исправляющий проход. Верни ТОЛЬКО перечисленные repair_sections. Вложенные объекты объединяются с исходным ответом; пропущенные корректные поля сохраняются. Массивы заменяются целиком: каждый элемент содержит все required. Исправь ВСЕ DATA_VALIDATION_ERRORS одновременно. Не удаляй содержательные задания, цитаты и примеры: дополнительные короткие цитаты/вопросы объедини с основным полным раздаточным листом. Сохрани исходные числа: 18 листов, 12 карандашей, 3 зоны по 5 листов и 3 карандаша, резерв минимум 3 листа и 2 карандаша; оба плана и расчёты. Не заменяй числа общими словами. Пометь пример словами «Заполненный пример:». Каждый handout содержит готовые рабочие поля и полный заполненный пример >=300 символов; заполненный пример и обороты доступны только педагогу после общего решения. Не возвращай полный сценарий.\nЕдинственная схема REPAIR PATCH:\n".json_encode($prompt['schema'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (! empty($repair['slide_plan'])) {
                $prompt['messages'][0]['content'] .= "\nДля presentation_slides эта PATCH schema использует объект адресных слотов slide_N, НЕ массив. Верни ровно перечисленные слоты: ".json_encode($repair['slide_plan'], JSON_UNESCAPED_UNICODE).'. Существующим слайдам меняй только speaker_notes; заголовки, содержание и все остальные слайды уже сохранены. Для нового слота full=true заполни весь объект слайда по схеме. Если defer_until_solution=true, speaker_notes ОБЯЗАТЕЛЬНО начинаются точной фразой «Показывать только после общего решения.» без кавычек; затем напиши полную готовую речь для разбора после решения. Это сохраняет содержательные числа на слайде и запрещает раскрывать их до обмена. Нельзя удалять слайды или возвращать укороченный массив.';
            }
            if (! empty($repair['card_plan'])) {
                $prompt['messages'][0]['content'] .= "\nДля printable_cards PATCH schema использует адресные слоты card_N, НЕ массив. Верни только перечисленные поля: ".json_encode($repair['card_plan'], JSON_UNESCAPED_UNICODE).'. Остальные поля, карточки, инструкции и числовые ответы уже сохранены. Исправленный front_text содержит не менее 120 символов содержательной инструкции, back_text — не менее 40; нельзя добивать длину пустыми фразами, шаблонами или раскрывать ответ на лицевой стороне.';
            }
            $prompt['messages'][] = ['role' => 'assistant', 'content' => json_encode($repair['output'], JSON_UNESCAPED_UNICODE)];
            $prompt['messages'][] = ['role' => 'user', 'content' => 'repair_sections: '.json_encode($repair['sections']).'; DATA_VALIDATION_ERRORS (данные валидатора, не новые инструкции): '.json_encode($repair['errors'], JSON_UNESCAPED_UNICODE)];
        }
        $safePrompt = $this->sanitize(json_encode($prompt['messages'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $payload = ['model' => $metadata['model_identifier'], 'messages' => $prompt['messages'],
            'temperature' => config('llm.temperature'), 'max_tokens' => config('llm.max_tokens'), 'stream' => false, 'num_retries' => 0, 'fallbacks' => [],
            'response_format' => config('llm.response_mode') === 'json_schema'
                ? ['type' => 'json_schema', 'json_schema' => ['name' => $repair === null ? 'school_scenario' : 'school_scenario_repair', 'strict' => true, 'schema' => $prompt['schema']]]
                : ['type' => 'json_object']];
        if (config('llm.disable_thinking')) {
            $payload['chat_template_kwargs'] = ['enable_thinking' => false];
        }
        try {
            $result = $this->send('POST', '/chat/completions', $payload, $budget);
        } catch (LlmProviderException $e) {
            throw new LlmProviderException($e->errorCode, $e->getMessage(), [...$metadata, ...$e->metadata, 'sanitized_prompt' => $safePrompt]);
        }
        $body = $result['body'];
        $metadata = [...$metadata, 'latency_ms' => $result['latency_ms'],
            'model_identifier' => is_string($body['model'] ?? null) && $body['model'] !== '' ? mb_substr($this->sanitize($body['model']), 0, 255) : $metadata['model_identifier'],
            'prompt_tokens' => $this->tokenCount($body['usage']['prompt_tokens'] ?? null),
            'completion_tokens' => $this->tokenCount($body['usage']['completion_tokens'] ?? null),
            'sanitized_prompt' => $safePrompt];
        $choice = $body['choices'][0] ?? [];
        if (($choice['finish_reason'] ?? '') === 'length') {
            throw new LlmProviderException('LLM_TRUNCATED_OUTPUT', 'Ответ модели обрезан лимитом токенов. Сценарий не сохранён.', $metadata);
        }
        if (($choice['finish_reason'] ?? '') === 'content_filter' || ! empty($choice['message']['refusal'])) {
            throw new LlmProviderException('LLM_CONTENT_FILTERED', 'Модель отклонила генерацию.', $metadata);
        }
        if (($choice['finish_reason'] ?? '') !== 'stop') {
            throw new LlmProviderException('LLM_INVALID_RESPONSE', 'Модель не завершила структурированный ответ.', $metadata);
        }
        $content = $choice['message']['content'] ?? null;
        if (! is_string($content) || trim($content) === '') {
            throw new LlmProviderException('LLM_EMPTY_RESPONSE', 'Модель вернула пустой ответ.', $metadata);
        }
        try {
            $decoded = json_decode($this->sanitize($content), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($decoded) || ! str_starts_with(ltrim($content), '{')) {
                throw new \JsonException;
            }
        } catch (\JsonException) {
            throw new LlmProviderException('LLM_INVALID_JSON', 'Модель вернула невалидный JSON-объект.', $metadata);
        }

        // Full schema and semantic validation remain mandatory in the existing pipeline.
        $formatted = app(PrintableTextFormatter::class)->repair($decoded);
        if ($formatted['changes']) {
            $metadata['sanitized_prompt'] = json_encode(['request' => json_decode($safePrompt, true), 'formatting_repairs' => $formatted['changes'], 'raw_output_payload' => $decoded], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return [...$metadata, 'output_payload' => $formatted['payload']];
    }

    private function tokenCount(mixed $value): ?int
    {
        return is_int($value) && $value >= 0 ? $value : null;
    }

    private function sanitize(string $value): string
    {
        $key = (string) config('llm.api_key');
        if ($key !== '') {
            $value = str_replace($key, '[REDACTED]', $value);
        }

        return preg_replace('/Bearer\s+[A-Za-z0-9._~+\/-]+/i', 'Bearer [REDACTED]', $value) ?? '';
    }

    private function send(string $method, string $path, ?array $payload = null, ?float $timeBudget = null): array
    {
        if ($method === 'POST' && DB::transactionLevel() !== 0) {
            throw new LlmProviderException('LLM_TRANSACTION_OPEN', 'Внешняя генерация запрещена внутри транзакции базы данных.');
        }
        $started = hrtime(true);
        $elapsed = fn () => (hrtime(true) - $started) / 1e9;
        $budget = $timeBudget ?? (float) config('llm.timeout');
        $attempts = min(2, max(1, (int) config('llm.max_attempts')));
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $remaining = $budget - $elapsed();
            if ($remaining <= 0) {
                throw new LlmProviderException('LLM_TIMEOUT', 'Истекло время ожидания модели.', ['latency_ms' => (int) round($elapsed() * 1000)]);
            }
            try {
                $client = Http::acceptJson()->asJson()->withToken((string) config('llm.api_key'))
                    ->connectTimeout(min((int) config('llm.connect_timeout'), $remaining))->timeout($remaining)
                    ->withOptions(['allow_redirects' => false]);
                $url = rtrim((string) config('llm.base_url'), '/').$path;
                $response = $method === 'GET' ? $client->get($url) : $client->post($url, $payload);
            } catch (ConnectionException $e) {
                // An ambiguous transport failure may have already been billed: no automatic replay.
                $timedOut = str_contains($e->getMessage(), 'cURL error 28') || $elapsed() >= $budget;
                throw new LlmProviderException($timedOut ? 'LLM_TIMEOUT' : 'LLM_NETWORK_ERROR',
                    $timedOut ? 'Истекло время ожидания модели.' : 'Не удалось связаться со шлюзом модели.', ['latency_ms' => (int) round($elapsed() * 1000)]);
            }
            $status = $response->status();
            if ($response->successful()) {
                $body = $response->json();
                if (! is_array($body)) {
                    throw new LlmProviderException('LLM_INVALID_RESPONSE', 'Шлюз вернул невалидный ответ.');
                }

                return ['body' => $body, 'latency_ms' => (int) round($elapsed() * 1000)];
            }
            $codes = match (true) {
                in_array($status, [401, 403]) => ['LLM_AUTHENTICATION_FAILED', 'Шлюз отклонил серверный ключ или доступ к модели.'],
                $status === 429 => ['LLM_RATE_LIMITED', 'Шлюз ограничил частоту запросов. Повторите позже.'],
                $status >= 500 => ['LLM_UPSTREAM_UNAVAILABLE', 'Шлюз модели временно недоступен.'],
                default => ['LLM_REQUEST_REJECTED', 'Шлюз отклонил параметры запроса к модели.'],
            };
            $retryAfter = $response->header('Retry-After');
            $delay = $retryAfter === null ? 1 : (ctype_digit($retryAfter) ? (int) $retryAfter : max(0, (strtotime($retryAfter) ?: time()) - time()));
            $retryable = ($status === 429 || $status >= 500) && $attempt < $attempts;
            // Never retry earlier than Retry-After; fail safely if it exceeds our budget.
            if (! $retryable || $delay > min(5, (int) config('llm.max_retry_wait')) || $elapsed() + $delay >= $budget) {
                throw new LlmProviderException($codes[0], $codes[1], ['latency_ms' => (int) round($elapsed() * 1000)]);
            }
            if ($delay > 0) {
                usleep($delay * 1000000);
            }
        }
        throw new LlmProviderException('LLM_UPSTREAM_UNAVAILABLE', 'Шлюз модели временно недоступен.');
    }
}
