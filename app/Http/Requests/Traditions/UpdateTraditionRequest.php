<?php

declare(strict_types=1);

namespace App\Http\Requests\Traditions;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTraditionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'min:3', 'max:255'],
            'description' => ['sometimes', 'required', 'string', 'min:10', 'max:5000'],
            'established_year' => ['nullable', 'integer', 'min:1800', 'max:'.(int) date('Y')],
        ];
    }
}
