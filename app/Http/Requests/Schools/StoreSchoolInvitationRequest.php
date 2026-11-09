<?php

namespace App\Http\Requests\Schools;

use App\Models\School;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StoreSchoolInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $school = School::find($this->route('schoolId'));

        return $school && Gate::allows('manage-school-invitations', $school);
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }

    public function rules(): array
    {
        return ['email' => ['nullable', 'email', 'max:255'], 'role' => ['prohibited'],
            'expires_at' => ['prohibited'], 'created_by' => ['prohibited']];
    }
}
