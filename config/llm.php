<?php

return [
    'provider' => env('LLM_PROVIDER', 'mock'),
    'base_url' => env('LITELLM_BASE_URL', 'https://litellmru.ds-hub-cf.ru/v1'),
    'api_key' => env('LITELLM_API_KEY'),
    'model' => env('LITELLM_MODEL'),
    'response_mode' => env('LITELLM_RESPONSE_MODE', 'json_schema'),
    'temperature' => (float) env('LITELLM_TEMPERATURE', 0.3),
    'max_tokens' => (int) env('LITELLM_MAX_TOKENS', 12000),
    'disable_thinking' => (bool) env('LITELLM_DISABLE_THINKING', false),
    'repair_passes' => (int) env('LITELLM_REPAIR_PASSES', 1),
    'connect_timeout' => (int) env('LITELLM_CONNECT_TIMEOUT', 10),
    // A shared wall-clock budget for all attempts, including Retry-After.
    'timeout' => (int) env('LITELLM_TIMEOUT', 120),
    'total_timeout' => (int) env('LITELLM_TOTAL_TIMEOUT', 240),
    'max_attempts' => (int) env('LITELLM_MAX_ATTEMPTS', 2),
    'max_retry_wait' => (int) env('LITELLM_MAX_RETRY_WAIT', 5),
];
