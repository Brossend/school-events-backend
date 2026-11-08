<?php

declare(strict_types=1);

namespace App\Http\Requests\Scenarios;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CreateScenarioRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'calendar_event_id' => ['nullable', 'uuid', 'exists:calendar_events,id'],
            'tradition_version_id' => ['nullable', 'uuid', 'exists:tradition_versions,id'],
            'custom_event_title' => ['nullable', 'string', 'min:3', 'max:255'],
            'region_code' => ['nullable', 'string', 'max:10'],
            'scale' => ['required', 'string', Rule::in(['class_level', 'all_school', 'family_community'])],
            'format_type' => ['required', 'string', Rule::in(['class_hour', 'quest', 'quiz', 'workshop', 'festival', 'roundtable'])],
            'target_grades' => ['required', 'array', 'min:1'],
            'target_grades.*' => ['integer', 'min:1', 'max:11'],
            'participants_count' => ['required', 'integer', 'min:5', 'max:500'],
            'duration_minutes' => ['required', 'integer', 'min:20', 'max:240'],
            'value_direction' => ['required', 'string', Rule::in([
                'family',
                'mutual_aid',
                'historical_memory',
                'labor_and_creation',
                'cultural_diversity',
                'civic_responsibility',
            ])],
            'family_involvement' => ['required', 'boolean'],
            'available_resources' => ['nullable', 'array'],
            'available_resources.*' => ['string', 'max:255'],
            'pedagogical_constraints' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            // Strict prohibition of school_id in body
            if ($this->has('school_id')) {
                $v->errors()->add('school_id', 'Запрещено передавать school_id в теле запроса. Контекст школы определяется сервером.');
            }

            // Mutual exclusivity of occasions
            $occasions = [
                $this->input('calendar_event_id'),
                $this->input('tradition_version_id'),
                $this->input('custom_event_title'),
            ];

            $filledCount = count(array_filter($occasions, fn ($val) => ! empty($val)));

            if ($filledCount !== 1) {
                $v->errors()->add('occasion', 'Должен быть указан ровно один повод мероприятия: событие календаря, версия школьной традиции или собственное название.');
            }
        });
    }
}
