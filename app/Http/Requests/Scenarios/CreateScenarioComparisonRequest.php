<?php

declare(strict_types=1);

namespace App\Http\Requests\Scenarios;

use Illuminate\Foundation\Http\FormRequest;

class CreateScenarioComparisonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'scenario_request_id' => ['required', 'uuid'],
            'comparison_type' => ['required', 'string', 'in:universal_vs_personalized,cultural_context'],
            'is_synthetic' => ['nullable', 'boolean'],
        ];
    }
}
