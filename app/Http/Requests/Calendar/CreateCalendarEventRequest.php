<?php

declare(strict_types=1);

namespace App\Http\Requests\Calendar;

use Illuminate\Foundation\Http\FormRequest;

class CreateCalendarEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'min:3', 'max:255'],
            'event_type' => ['required', 'string', 'in:federal,regional,cultural_traditional,school_custom'],
            'region_code' => ['required_if:event_type,regional', 'nullable', 'string', 'max:10'],
            'date_type' => ['required', 'string', 'in:fixed_annual,floating_rule,single_occurrence'],
            'fixed_month' => ['nullable', 'required_if:date_type,fixed_annual', 'integer', 'between:1,12'],
            'fixed_day' => ['nullable', 'required_if:date_type,fixed_annual', 'integer', 'between:1,31'],
            'description' => ['required', 'string', 'min:10'],
            'target_age_groups' => ['required', 'array', 'min:1'],
            'target_age_groups.*' => ['string', 'in:primary_1_4,basic_5_9,senior_10_11'],
            'recommended_value_directions' => ['required', 'array', 'min:1'],
            'recommended_value_directions.*' => ['string', 'in:family,mutual_aid,historical_memory,labor_and_creation,cultural_diversity,civic_responsibility'],
            'status' => ['nullable', 'string', 'in:draft,pending_review,approved,rejected'],
        ];
    }
}
