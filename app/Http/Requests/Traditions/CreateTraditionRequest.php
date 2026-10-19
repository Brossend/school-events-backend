<?php

declare(strict_types=1);

namespace App\Http\Requests\Traditions;

use Illuminate\Foundation\Http\FormRequest;

class CreateTraditionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'min:3', 'max:255'],
            'description' => ['required', 'string', 'min:10'],
            'established_year' => ['nullable', 'integer', 'between:1800,2100'],
        ];
    }
}
