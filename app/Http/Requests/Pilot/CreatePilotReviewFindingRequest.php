<?php

declare(strict_types=1);

namespace App\Http\Requests\Pilot;

use Illuminate\Foundation\Http\FormRequest;

class CreatePilotReviewFindingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'expert_audit_status' => ['required', 'string', 'in:pending_audit,audited,rejected'],
            'critical_cultural_errors' => ['required', 'integer', 'min:0'],
            'methodological_remarks' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
