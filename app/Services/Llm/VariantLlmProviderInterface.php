<?php

declare(strict_types=1);

namespace App\Services\Llm;

use App\Models\ScenarioRequest;

interface VariantLlmProviderInterface extends LlmProviderInterface
{
    public function generateVariant(ScenarioRequest $request, string $mode): array;
}
