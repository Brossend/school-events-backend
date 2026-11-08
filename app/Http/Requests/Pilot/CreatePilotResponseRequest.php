<?php

declare(strict_types=1);

namespace App\Http\Requests\Pilot;

use Illuminate\Foundation\Http\FormRequest;

class CreatePilotResponseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'invite_token' => ['required', 'string'],
            'scenario_id' => ['required', 'uuid'],
            'prep_time_baseline_minutes' => ['required', 'integer', 'min:1'],
            'prep_time_ai_minutes' => ['required', 'integer', 'min:0'],
            'personalization_selected' => ['required', 'boolean'],
            'manual_edit_percentage' => ['required', 'numeric', 'between:0,100'],
            'cultural_context_used' => ['required', 'boolean'],
            'cultural_usefulness_score' => ['nullable', 'integer', 'between:1,5'],
            'comparison_id' => ['nullable', 'uuid'],
            'feedback_text' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
