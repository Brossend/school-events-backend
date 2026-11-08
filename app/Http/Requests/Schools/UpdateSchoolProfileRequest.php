<?php

declare(strict_types=1);

namespace App\Http\Requests\Schools;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSchoolProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'min:3', 'max:255'],
            'short_name' => ['nullable', 'string', 'max:100'],
            'city' => ['sometimes', 'required', 'string', 'min:2', 'max:100'],
            'inn' => ['nullable', 'string', 'regex:/^\d{10}(\d{2})?$/'],
            'settings' => ['nullable', 'array'],
            'settings.address' => ['nullable', 'string', 'max:255'],
            'settings.phone' => ['nullable', 'string', 'max:50'],
            'settings.email' => ['nullable', 'email', 'max:255'],
            'settings.website' => ['nullable', 'string', 'max:255'],
            'settings.description' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
