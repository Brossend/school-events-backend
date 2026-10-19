<?php

declare(strict_types=1);

namespace App\Http\Requests\Traditions;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

class CreateTraditionVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'academic_year' => [
                'required',
                'string',
                'regex:/^[0-9]{4}-[0-9]{4}$/',
                function (string $attribute, mixed $value, Closure $fail) {
                    if (preg_match('/^(\d{4})-(\d{4})$/', (string) $value, $matches)) {
                        $y1 = (int) $matches[1];
                        $y2 = (int) $matches[2];
                        if ($y2 !== $y1 + 1) {
                            $fail('Учебный год должен быть последовательным периодом из двух лет формата YYYY-YYYY (например, 2026-2027).');
                        }
                    }
                },
            ],
            'parameters_snapshot' => ['required', 'array'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
