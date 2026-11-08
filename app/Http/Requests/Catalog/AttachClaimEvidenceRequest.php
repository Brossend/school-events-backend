<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;

class AttachClaimEvidenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'event_source_id' => ['required', 'uuid', 'exists:event_sources,id'],
            'citation_locator' => ['required', 'string', 'min:3', 'max:255'],
            'citation_excerpt' => ['required', 'string', 'min:10', 'max:2000'],
            'applicable_academic_year' => ['nullable', 'string', 'regex:/^\d{4}-\d{4}$/'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'verify' => ['nullable', 'boolean'],
        ];
    }
}
