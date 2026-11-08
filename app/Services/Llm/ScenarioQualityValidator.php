<?php

declare(strict_types=1);

namespace App\Services\Llm;

use Illuminate\Validation\ValidationException;

class ScenarioQualityValidator
{
    public function validate(array $payload): void
    {
        $errors = [];
        if (str_contains(json_encode($payload, JSON_UNESCAPED_UNICODE), '__LLM_WRITE_')) {
            $errors['RULE_UNFILLED_TEMPLATE'] = 'Каркас полей не является сценарием: замените все __LLM_WRITE_*__ конкретным содержанием.';
        }
        $cards = $payload['materials']['printable_cards'];
        if (count($cards) < 2 || count($payload['materials']['presentation_slides']) < 3) {
            $errors['RULE_READY_MATERIALS_COUNT'] = 'Нужны минимум две содержательные карточки и три готовых слайда.';
        }
        foreach ($cards as $i => $card) {
            if (mb_strlen(trim($card['front_text'])) < 120 || mb_strlen(trim($card['back_text'])) < 40
                || preg_match('/^(?:Часть(?: информации)?\s*\d+|Ответ на (?:головоломку|часть информации)\s*\d+)[.!]?$/ui', trim($card['front_text']))
                || preg_match('/^(?:Ответ на|Решение)\s+(?:головоломк|часть информации|задани)[^.!]*[.!]?$/ui', trim($card['back_text']))) {
                $errors['RULE_READY_CARD_'.$i] = 'Карточка '.$i.': нужен готовый конкретный текст задания >=120 символов и проверяемый ответ/решение >=40 символов, а не заглушка или обещание подготовить задание.';
            }
        }
        foreach ($payload['materials']['handouts'] as $i => $handout) {
            if (mb_strlen($handout['template_content']) < 200 || ! $this->hasFilledExample($handout['template_content'])) {
                $errors['RULE_READY_HANDOUT_'.$i] = 'Раздаточный лист должен содержать готовый шаблон и заполненный вымышленный пример (>=200 символов).';
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function hasFilledExample(string $content): bool
    {
        if (preg_match('/пример|образец/ui', $content)) {
            return true;
        }
        // Accept a concrete Markdown table example even without a label. Blank
        // worksheets, separator rows and placeholders do not count as examples.
        $lines = preg_split('/\\\\n|\R/u', $content);
        $bodyRows = 0;
        $hasHeader = false;
        foreach ($lines as $line) {
            if (! str_contains($line, '|')) {
                continue;
            }
            $cells = array_map('trim', explode('|', trim($line, " |\t")));
            if (count($cells) < 2 || preg_match('/^[\s|:~-]+$/u', $line)) {
                continue;
            }
            if (! $hasHeader) {
                $hasHeader = true;

                continue;
            }
            $filled = array_filter($cells, fn ($cell) => preg_match('/[\p{L}\p{N}]/u', $cell)
                && ! preg_match('/^(?:_{2,}|\.{2,}|…|заполните|впишите|ФИО)$/ui', $cell));
            if (count($filled) === count($cells)) {
                $bodyRows++;
            }
        }

        return $bodyRows >= 2;
    }
}
