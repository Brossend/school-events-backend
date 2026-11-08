<?php

declare(strict_types=1);

namespace App\Http\Requests\Pilot;

use Illuminate\Foundation\Http\FormRequest;

class CreatePilotParticipantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'subject_area' => ['required', 'string', 'max:100'],
            'teaching_experience_years' => ['required', 'integer', 'min:0', 'max:60'],
            'anonymous_code' => ['nullable', 'string', 'max:64'],
            'valid_days' => ['nullable', 'integer', 'min:1', 'max:90'],
            'expires_days' => ['nullable', 'integer', 'min:1', 'max:90'],
            'is_synthetic' => ['nullable', 'boolean'],
        ];
    }
}
