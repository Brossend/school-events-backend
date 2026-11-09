<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEventSourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'min:3', 'max:500'],
            'author' => ['nullable', 'string', 'max:255'],
            'source_type' => ['sometimes', 'required', 'string', Rule::in(['normative_act', 'scientific_publication', 'ethnographic_archive', 'encyclopedia', 'official_publication'])],
            'url' => ['nullable', 'string', 'url', 'max:1000'],
            'doi_or_isbn' => ['nullable', 'string', 'max:64'],
        ];
    }
}
