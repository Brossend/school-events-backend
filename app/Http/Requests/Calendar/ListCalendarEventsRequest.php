<?php

declare(strict_types=1);

namespace App\Http\Requests\Calendar;

use Illuminate\Foundation\Http\FormRequest;

class ListCalendarEventsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'region_code' => ['nullable', 'string', 'max:10'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'academic_year' => ['nullable', 'string', 'regex:/^[0-9]{4}-[0-9]{4}$/'],
            'type' => ['nullable', 'string', 'in:federal,regional,cultural_traditional,school_custom'],
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
