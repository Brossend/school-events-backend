<?php

declare(strict_types=1);

namespace App\Services\Llm;

class LlmRuntime
{
    public static function metadata(): array
    {
        return match (config('llm.provider', 'mock')) {
            'mock' => ['provider_name' => MockLlmProvider::PROVIDER_NAME, 'model_identifier' => MockLlmProvider::MODEL_IDENTIFIER,
                'prompt_version' => MockLlmProvider::PROMPT_VERSION, 'schema_version' => MockLlmProvider::SCHEMA_VERSION],
            'litellm' => self::liteMetadata(),
            default => throw new LlmProviderException('LLM_CONFIGURATION_ERROR', 'Неизвестный провайдер генерации.'),
        };
    }

    private static function liteMetadata(): array
    {
        $url = parse_url((string) config('llm.base_url'));
        if (! config('llm.api_key') || ! config('llm.model') || ($url['scheme'] ?? '') !== 'https'
            || empty($url['host']) || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])
            || ! in_array(config('llm.response_mode'), ['json_schema', 'json_object'], true)
            || config('llm.timeout') < 1 || config('llm.timeout') > 240 || config('llm.connect_timeout') < 1
            || config('llm.total_timeout') < config('llm.timeout') || config('llm.total_timeout') > 360
            || config('llm.connect_timeout') > config('llm.timeout') || config('llm.max_tokens') < 1
            || config('llm.repair_passes') < 0 || config('llm.repair_passes') > 2
            || config('llm.temperature') < 0 || config('llm.temperature') > 2) {
            throw new LlmProviderException('LLM_CONFIGURATION_ERROR', 'Проверьте серверную конфигурацию LiteLLM: ключ, модель, HTTPS URL, режим JSON и лимиты.');
        }

        return ['provider_name' => 'litellm', 'model_identifier' => (string) config('llm.model'),
            'prompt_version' => ScenarioPromptBuilder::VERSION, 'schema_version' => '1.0.0'];
    }
}
