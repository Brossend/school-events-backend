<?php

declare(strict_types=1);

namespace App\Http\Requests\Scenarios;

use Illuminate\Foundation\Http\FormRequest;

class SelectScenarioComparisonVariantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'selected_variant' => ['required', 'string', 'in:A,B'],
            'selection_reason' => ['required', 'string', 'min:10'],
            'cultural_usefulness_rating' => ['nullable', 'integer', 'between:1,5'],
        ];
    }
}
