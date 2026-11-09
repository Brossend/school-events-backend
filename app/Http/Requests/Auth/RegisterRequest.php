<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
        foreach (['email', 'invitation_code'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => mb_strtolower(trim($this->input($field)))]);
            }
        }
    }

    public function rules(): array
    {
        return ['invitation_code' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'name' => ['required', 'string', 'min:2', 'max:255'], 'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'confirmed', Password::min(12)->mixedCase()->numbers()],
            'role' => ['prohibited'], 'system_role' => ['prohibited'], 'memberships' => ['prohibited']];
    }

    public function messages(): array
    {
        return ['invitation_code.required' => 'Введите код, который передал администратор школы.',
            'invitation_code.regex' => 'Проверьте код приглашения: скопируйте его полностью.',
            'name.required' => 'Укажите, как к вам обращаться.', 'email.required' => 'Укажите рабочую почту.',
            'password.confirmed' => 'Пароли не совпадают. Повторите пароль.'];
    }
}
