<?php

declare(strict_types=1);

namespace App\Services\Llm;

use App\Models\ScenarioRequest;

/** A field-completeness prompt aid derived from the ORIGINAL schema, never a validator or generated result. */
class ScenarioOutputTemplate
{
    private function node(array $schema, string $path): mixed
    {
        if (isset($schema['const'])) {
            return $schema['const'];
        }
        if (isset($schema['enum'])) {
            return $schema['enum'][0];
        }

        return match ($schema['type'] ?? 'string') {
            'object' => array_combine($schema['required'] ?? [], array_map(fn ($key) => $this->node($schema['properties'][$key], $path.'_'.$key), $schema['required'] ?? [])) ?: new \stdClass,
            'array' => array_map(fn ($i) => $this->node($schema['items'], $path.'_'.$i), range(1, max(1, $schema['minItems'] ?? 1))),
            'integer' => $schema['minimum'] ?? 1,
            'boolean' => false,
            default => '__LLM_WRITE_'.$path.'__',
        };
    }

    public function build(array $schema, ScenarioRequest $request, string $mode): array
    {
        $template = $this->node($schema, 'scenario');
        $template['value_direction'] = $request->value_direction;
        $template['format'] = ['scale' => $request->scale, 'format_type' => $request->format_type, 'total_duration_minutes' => $request->duration_minutes];
        $grades = $request->target_grades;
        $age = max($grades) <= 4 ? 'primary_1_4' : (min($grades) >= 5 && max($grades) <= 9 ? 'basic_5_9' : (min($grades) >= 10 ? 'senior_10_11' : 'mixed'));
        $template['target_audience'] = ['age_group' => $age, 'target_grades' => $grades, 'participants_count_min' => $request->participants_count, 'participants_count_max' => $request->participants_count, 'family_involvement' => (bool) $request->family_involvement];
        $stage = $template['stages'][0];
        $total = (int) $request->duration_minutes;
        $school = $request->scale === 'all_school' && $request->participants_count >= 100;
        $family = $request->scale === 'family_community';
        $intro = max(2, (int) floor($total * .1));
        $brief = max(2, (int) floor($total * .2));
        $reflection = max(2, (int) floor($total * .2));
        $main = $total - $intro - $brief - $reflection;
        $rounds = $school ? 5 : ($family ? 3 : 2);
        $parts = array_fill(0, $rounds, intdiv($main, $rounds));
        $parts[$rounds - 1] += $main - array_sum($parts);
        $times = [$intro, $brief, ...$parts, $reflection];
        $template['stages'] = [];
        foreach ($times as $i => $minutes) {
            $kind = $i === 0 ? 'organizational_moment' : ($i === 1 ? 'motivation_introduction' : ($i === count($times) - 1 ? 'reflection_summary' : 'main_interactive_part'));
            $template['stages'][] = [...$stage, 'id' => 'stage_'.($i + 1), 'order_index' => $i + 1, 'stage_type' => $kind, 'duration_minutes' => $minutes];
        }
        foreach ($schema['allOf'] as $rule) {
            if (($rule['if']['properties']['value_direction']['const'] ?? null) === $request->value_direction) {
                $template['pedagogical_mechanism']['mechanism_type'] = $rule['then']['properties']['pedagogical_mechanism']['properties']['mechanism_type']['const'];
            }
        }
        $anchor = $mode === 'universal' ? 'Общая учебная задача' : 'Совместный деятельностный практикум';
        $template['pedagogical_mechanism']['target_activity_title'] = $anchor;
        $activity = $template['activities'][0];
        $template['activities'] = [];
        for ($i = 0; $i < $rounds; $i++) {
            $title = $i === 0 ? $anchor : ($school ? 'Раунд '.($i + 1).' командного маршрута' : ($family ? ['', 'Совместное создание плана', 'Взаимная проверка и исправление плана'][$i] : 'Сравнение двух решений и проверка резерва'));
            $template['activities'][] = [...$activity, 'title' => $title, 'target_stage_id' => 'stage_'.($i + 3)];
        }
        $slide = $template['materials']['presentation_slides'][0];
        $template['materials']['presentation_slides'] = array_map(fn ($n) => [...$slide, 'slide_number' => $n], [1, 2, 3]);
        $card = $template['materials']['printable_cards'][0];
        $role = $template['roles'][0];
        $template['roles'] = [];
        $names = ['Хранитель запасов', 'Представитель получателя', 'Хранитель резерва', 'Проверяющий', 'Докладчик'];
        $size = $school ? 3 : 1;
        $template['materials']['printable_cards'] = [];
        foreach ($names as $i => $name) {
            $template['roles'][] = [...$role, 'role_name' => $name, 'target_participants' => 'students', 'group_size' => $size];
            $template['materials']['printable_cards'][] = [...$card, 'title' => 'Карточка: '.$name,
                'front_text' => '__LLM_WRITE_only_this_role_information_and_real_task_min_140_chars_'.$i.'__',
                'back_text' => '__LLM_WRITE_teacher_check_explanation_min_60_chars_'.$i.'__'];
        }
        $template['roles'][] = [...$role, 'role_name' => $family ? 'Добровольный взрослый или школьный наставник' : 'Педагог-координатор', 'target_participants' => $family ? 'parents' : 'teachers', 'group_size' => $school ? 5 : 1];
        // Cultural literals remain exclusively in user JSON data, never interpolated into a system message.
        $template['cultural_claims'] = $mode === 'with_cultural_context' ? [$this->node($schema['properties']['cultural_claims']['items'], 'copy_trusted_claim')] : [];

        return $template;
    }
}
