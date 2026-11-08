<?php

declare(strict_types=1);

namespace App\Services\Llm;

use App\Models\ScenarioRequest;
use Illuminate\Validation\ValidationException;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Helper;
use Opis\JsonSchema\Validator;

/** A bounded LLM section repair. Only model-produced text is merged. */
class ScenarioRepairPlanner
{
    public function errors(array $payload, array $errors, array $schema, ScenarioRequest $request): array
    {
        // Planning projection only: inspect schema-valid sections for latent errors
        // even when an earlier validator stopped at a different section.
        $inspect = [];
        $validator = new Validator;
        foreach ($schema['properties'] as $key => $property) {
            if (isset($payload[$key]) && $validator->validate(Helper::toJSON($payload[$key]), Helper::toJSON($property))->isValid()) {
                $inspect[$key] = $payload[$key];
            }
        }
        $inspect['format']['scale'] = $request->scale;
        $inspect['target_audience']['participants_count_max'] = $request->participants_count;
        foreach (app(PedagogicalQualityGate::class)->assess($inspect)['findings'] as $finding) {
            if ($finding['severity'] === 'error') {
                $errors[$finding['code'].'_'.$finding['path']] = [$finding['message']];
            }
        }
        if (isset($inspect['materials'])) {
            try {
                app(ScenarioQualityValidator::class)->validate(['materials' => $inspect['materials']]);
            } catch (ValidationException $e) {
                $errors = [...$errors, ...$e->errors()];
            }
        }

        return $errors;
    }

    public function sections(array $payload, array $errors, array $schema): array
    {
        $keys = [];
        $validator = new Validator;
        foreach ($schema['properties'] as $key => $property) {
            if (! array_key_exists($key, $payload) || ! $validator->validate(Helper::toJSON($payload[$key]), Helper::toJSON($property))->isValid()) {
                $keys[] = $key;
            }
            if (str_contains(json_encode($payload[$key] ?? null, JSON_UNESCAPED_UNICODE), '__LLM_WRITE_')) {
                $keys[] = $key;
            }
        }
        // A schema error may be reported before the stricter ready-material rules.
        // Include visibly short cards in the same bounded pass, not a later retry.
        foreach ($payload['materials']['printable_cards'] ?? [] as $card) {
            if (mb_strlen(trim($card['front_text'] ?? '')) < 120 || mb_strlen(trim($card['back_text'] ?? '')) < 40) {
                $keys[] = 'materials';
            }
        }
        $text = json_encode($errors, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        foreach (array_keys($schema['properties']) as $key) {
            if (str_contains($text, '$.'.$key) || str_contains($text, '/'.$key)) {
                $keys[] = $key;
            }
        }
        $rules = [
            'MECHANISM_TWO_PLANS' => ['activities'],
            'SCHOOL_LOGISTICS_ROUNDS' => ['stages', 'activities'],
            'SCHOOL_LOGISTICS_PLAN' => ['teacher_instructions'],
            'SCHOOL_LOGISTICS_TRANSITIONS' => ['teacher_instructions'],
            'FAMILY_INCLUSION' => ['teacher_instructions'],
            'TIMING' => ['stages', 'format'], 'MECHANISM' => ['pedagogical_mechanism', 'activities', 'roles'],
            'READY_CARD' => ['materials'], 'READY_HANDOUT' => ['materials'], 'READY_MATERIALS' => ['materials'],
            'READY_SLIDE' => ['materials'], 'MATERIALS_RELEASE_ORDER' => ['teacher_instructions'],
            'SCHOOL_LOGISTICS' => ['stages', 'preparation_checklist', 'teacher_instructions'],
            'FAMILY_' => ['stages', 'roles', 'activities', 'teacher_instructions'],
            'DISTRIBUTED_' => ['materials'],
        ];
        foreach (array_keys($errors) as $errorCode) {
            foreach ($rules as $rule => $fields) {
                if (str_contains((string) $errorCode, $rule)) {
                    $keys = [...$keys, ...$fields];
                    break;
                }
            }
        }
        if (! $keys) {
            $keys = array_keys($schema['properties']);
        }

        return array_values(array_unique($keys));
    }

    public function slidePlan(array $payload, array $errors, array $schema, ScenarioRequest $request): array
    {
        $slides = $payload['materials']['presentation_slides'] ?? null;
        if (! is_array($slides) || ! array_is_list($slides)) {
            return [];
        }
        $indices = [];
        $deferred = [];
        foreach (array_keys($errors) as $code) {
            if (preg_match('/presentation_slides\.(\d+)/', (string) $code, $match)) {
                $indices[] = (int) $match[1];
                if (str_contains((string) $code, 'DISTRIBUTED_SLIDE_LEAK')) {
                    $deferred[(int) $match[1]] = true;
                }
            }
        }
        for ($i = count($slides); $i < 3; $i++) {
            $indices[] = $i;
        }
        $plan = [];
        $validator = new Validator;
        $item = $schema['properties']['materials']['properties']['presentation_slides']['items'];
        sort($indices);
        foreach (array_unique($indices) as $index) {
            if ($index < 0 || $index >= max(3, count($slides))) {
                continue;
            }
            $full = ! isset($slides[$index]) || ! $validator->validate(Helper::toJSON($slides[$index]), Helper::toJSON($item))->isValid();
            $plan['slide_'.$index] = ['index' => $index, 'full' => $full];
            if (isset($deferred[$index])) {
                $plan['slide_'.$index]['defer_until_solution'] = true;
            }
        }

        return $plan;
    }

    public function materialFields(array $payload, array $errors, array $schema, array $slidePlan): array
    {
        $fields = [];
        $validator = new Validator;
        foreach ($schema['properties']['materials']['properties'] as $key => $property) {
            if (! isset($payload['materials'][$key]) || ! $validator->validate(Helper::toJSON($payload['materials'][$key]), Helper::toJSON($property))->isValid()) {
                $fields[] = $key;
            }
            foreach (array_keys($errors) as $code) {
                if (str_contains((string) $code, $key)) {
                    $fields[] = $key;
                }
            }
        }
        foreach (array_keys($errors) as $code) {
            if (preg_match('/HANDOUT/', (string) $code)) {
                $fields[] = 'handouts';
            }
            if (preg_match('/CARD|DISTRIBUTED_INFORMATION|DISTRIBUTED_ANSWER/', (string) $code)) {
                $fields[] = 'printable_cards';
            }
            if (str_contains((string) $code, 'SLIDE')) {
                $fields[] = 'presentation_slides';
            }
        }
        if ($slidePlan) {
            $fields[] = 'presentation_slides';
        }

        // Unknown material findings require a complete material patch, never {}.
        return array_values(array_unique($fields ?: array_keys($schema['properties']['materials']['properties'])));
    }

    public function cardPlan(array $payload, array $errors, array $schema): array
    {
        $cards = $payload['materials']['printable_cards'] ?? null;
        if (! is_array($cards) || ! array_is_list($cards) || count($cards) < 5) {
            return [];
        }
        $fields = [];
        $validator = new Validator;
        $item = $schema['properties']['materials']['properties']['printable_cards']['items'];
        foreach ($cards as $index => $card) {
            if (! is_array($card)) {
                return [];
            }
            foreach ($item['properties'] as $field => $property) {
                if (in_array($field, ['front_text', 'back_text'], true)) {
                    $property['minLength'] = max($property['minLength'] ?? 0, $field === 'front_text' ? 120 : 40);
                }
                if (! array_key_exists($field, $card) || ! $validator->validate(Helper::toJSON($card[$field]), Helper::toJSON($property))->isValid()
                    || (is_string($card[$field]) && str_contains($card[$field], '__LLM_WRITE_'))) {
                    $fields[$index][] = $field;
                }
            }
        }
        foreach (array_keys($errors) as $code) {
            if (preg_match('/printable_cards[.\/](\d+)[.\/](\w+)/', (string) $code, $match)
                && isset($cards[(int) $match[1]]) && isset($item['properties'][$match[2]])) {
                $fields[(int) $match[1]][] = $match[2];
            } elseif (str_contains((string) $code, 'READY_CARD_RESOURCE_KEY')) {
                $fields[count($cards) - 1][] = 'back_text';
            }
        }
        ksort($fields);
        $plan = [];
        foreach ($fields as $index => $names) {
            $plan['card_'.$index] = ['index' => $index, 'fields' => array_values(array_unique($names))];
        }

        return $plan;
    }

    public function schema(array $keys, array $original, ScenarioRequest $request, array $slidePlan = [], array $materialFields = [], array $cardPlan = []): array
    {
        $properties = array_intersect_key($original['properties'], array_flip($keys));
        if (isset($properties['materials'])) {
            $cards = &$properties['materials']['properties']['printable_cards'];
            $cards['items']['properties']['front_text']['minLength'] = max(120, $cards['items']['properties']['front_text']['minLength'] ?? 0);
            $cards['items']['properties']['back_text']['minLength'] = max(40, $cards['items']['properties']['back_text']['minLength'] ?? 0);
            $cards['minItems'] = 5;
            $handouts = &$properties['materials']['properties']['handouts'];
            $handouts['maxItems'] = 1;
            $handouts['items']['properties']['template_content']['minLength'] = 300;
            $properties['materials']['properties']['presentation_slides']['items']['properties']['speaker_notes']['minLength'] = 100;
            $properties['materials']['properties']['presentation_slides']['minItems'] = 3;
        }
        if ($request->scale === 'all_school' && $request->participants_count >= 100) {
            if (isset($properties['stages'])) {
                $properties['stages']['minItems'] = 8;
            }
            if (isset($properties['activities'])) {
                $properties['activities']['minItems'] = 5;
            }
        }
        if ($request->scale === 'family_community') {
            if (isset($properties['stages'])) {
                $properties['stages']['minItems'] = 6;
            }
            if (isset($properties['activities'])) {
                $properties['activities']['minItems'] = 3;
            }
        }

        foreach ($properties as &$property) {
            $property = $this->partialObject($property);
        }
        if ($request->scale === 'all_school' && $request->participants_count >= 100 && isset($properties['teacher_instructions'])) {
            // The route belongs in methodology, not in an unchanged accessibility
            // paragraph. Require this child so the repair cannot echo another field.
            $properties['teacher_instructions']['required'] = ['methodological_recommendations'];
        }
        if ($slidePlan && isset($properties['materials'])) {
            $item = $original['properties']['materials']['properties']['presentation_slides']['items'];
            $item['properties']['speaker_notes']['minLength'] = 100;
            $slots = [];
            foreach ($slidePlan as $name => $target) {
                if ($target['full']) {
                    $slots[$name] = $item;
                    $slots[$name]['properties']['slide_number']['const'] = $target['index'] + 1;
                } else {
                    $slots[$name] = ['type' => 'object', 'additionalProperties' => false, 'required' => ['speaker_notes'],
                        'properties' => ['speaker_notes' => $item['properties']['speaker_notes']]];
                }
                if (! empty($target['defer_until_solution'])) {
                    $slots[$name]['properties']['speaker_notes']['pattern'] = '^Показывать только после общего решения\\.';
                }
            }
            $properties['materials']['required'] = ['presentation_slides'];
            $properties['materials']['properties']['presentation_slides'] = ['type' => 'object', 'additionalProperties' => false,
                'required' => array_keys($slots), 'properties' => $slots];
        }

        if ($cardPlan && isset($properties['materials'])) {
            $item = $properties['materials']['properties']['printable_cards']['items'];
            $slots = [];
            foreach ($cardPlan as $name => $target) {
                $slots[$name] = ['type' => 'object', 'additionalProperties' => false,
                    'required' => $target['fields'],
                    'properties' => array_intersect_key($item['properties'], array_flip($target['fields']))];
            }
            $properties['materials']['properties']['printable_cards'] = ['type' => 'object', 'additionalProperties' => false,
                'required' => array_keys($slots), 'properties' => $slots];
        }

        if ($materialFields && isset($properties['materials'])) {
            $properties['materials']['properties'] = array_intersect_key($properties['materials']['properties'], array_flip($materialFields));
            $properties['materials']['required'] = $materialFields;
        }

        return ['type' => 'object', 'additionalProperties' => false, 'required' => $keys, 'properties' => $properties];
    }

    private function partialObject(array $schema): array
    {
        if (($schema['type'] ?? '') === 'object') {
            unset($schema['required']);
            foreach ($schema['properties'] ?? [] as $key => $property) {
                $schema['properties'][$key] = $this->partialObject($property);
            }
        }

        // Array items must be complete. Arrays replace atomically, never by index.
        return $schema;
    }

    public function validatePatch(array $patch, array $keys, array $schema, ScenarioRequest $request, array $slidePlan = [], array $materialFields = [], array $cardPlan = []): void
    {
        $result = (new Validator)->validate(Helper::toJSON($patch), Helper::toJSON($this->schema($keys, $schema, $request, $slidePlan, $materialFields, $cardPlan)));
        if (! $result->isValid()) {
            $details = (new ErrorFormatter)->format($result->error());
            throw ValidationException::withMessages(['REPAIR_SCHEMA' => 'Repair patch: '.json_encode($details, JSON_UNESCAPED_UNICODE)]);
        }
    }

    public function merge(array $original, array $patch, array $allowed, array $slidePlan = [], array $cardPlan = []): array
    {
        if (array_diff(array_keys($patch), $allowed) || array_diff($allowed, array_keys($patch))) {
            throw ValidationException::withMessages(['REPAIR_SCOPE' => 'Repair должен вернуть ровно разрешённые разделы; посторонние поля и пропуски запрещены.']);
        }
        if ($slidePlan) {
            $updates = $patch['materials']['presentation_slides'] ?? [];
            if (array_diff(array_keys($updates), array_keys($slidePlan)) || array_diff(array_keys($slidePlan), array_keys($updates))) {
                throw ValidationException::withMessages(['REPAIR_SCOPE' => 'Разрешены только адресные слоты слайдов из slide_plan.']);
            }
            $slides = $original['materials']['presentation_slides'];
            foreach ($slidePlan as $name => $target) {
                $index = $target['index'];
                if ($target['full']) {
                    $slides[$index] = $updates[$name];
                } else {
                    if (array_keys($updates[$name]) !== ['speaker_notes']) {
                        throw ValidationException::withMessages(['REPAIR_SCOPE' => 'Существующему слайду разрешено менять только speaker_notes.']);
                    }
                    $slides[$index]['speaker_notes'] = $updates[$name]['speaker_notes'];
                }
            }
            $patch['materials']['presentation_slides'] = array_values($slides);
        }

        if ($cardPlan) {
            $updates = $patch['materials']['printable_cards'] ?? [];
            if (array_diff(array_keys($updates), array_keys($cardPlan)) || array_diff(array_keys($cardPlan), array_keys($updates))) {
                throw ValidationException::withMessages(['REPAIR_SCOPE' => 'Разрешены только адресные слоты карточек из card_plan.']);
            }
            $cards = $original['materials']['printable_cards'];
            foreach ($cardPlan as $name => $target) {
                if (array_diff(array_keys($updates[$name]), $target['fields']) || array_diff($target['fields'], array_keys($updates[$name]))) {
                    throw ValidationException::withMessages(['REPAIR_SCOPE' => 'Разрешены только повреждённые поля карточки из card_plan.']);
                }
                $cards[$target['index']] = array_replace($cards[$target['index']], $updates[$name]);
            }
            $patch['materials']['printable_cards'] = $cards;
        }

        foreach ($patch as $key => $value) {
            $original[$key] = is_array($value) && ! array_is_list($value) && isset($original[$key]) && is_array($original[$key]) && ! array_is_list($original[$key])
                ? $this->mergeObjects($original[$key], $value) : $value;
        }

        return $original;
    }

    private function mergeObjects(array $original, array $patch): array
    {
        foreach ($patch as $key => $value) {
            $original[$key] = is_array($value) && ! array_is_list($value) && isset($original[$key]) && is_array($original[$key]) && ! array_is_list($original[$key])
                ? $this->mergeObjects($original[$key], $value) : $value;
        }

        return $original;
    }
}
