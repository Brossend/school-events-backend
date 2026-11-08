<?php

declare(strict_types=1);

namespace App\Services\Llm;

use App\Models\ScenarioRequest;

interface LlmProviderInterface
{
    /**
     * Generate structured scenario payload from request and context snapshot.
     *
     * @return array{
     *     provider_name: string,
     *     model_identifier: string,
     *     prompt_version: string,
     *     schema_version: string,
     *     latency_ms: int,
     *     prompt_tokens: int|null,
     *     completion_tokens: int|null,
     *     sanitized_prompt: string,
     *     output_payload: array
     * }
     */
    public function generate(ScenarioRequest $request): array;
}
