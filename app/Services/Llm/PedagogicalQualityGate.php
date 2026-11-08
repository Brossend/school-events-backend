<?php

declare(strict_types=1);

namespace App\Services\Llm;

use App\Models\GenerationRun;
use App\Models\ScenarioRevision;
use Illuminate\Validation\ValidationException;

/** An additional gate, never a replacement for schema, moderation or provenance. */
class PedagogicalQualityGate
{
    public const VERSION = 'quality-2.3.6';

    public function strings(array $payload, string $path = '$'): array
    {
        $result = [];
        foreach ($payload as $key => $value) {
            $at = $path.'.'.$key;
            if (is_string($value)) {
                $result[$at] = $value;
            } elseif (is_array($value)) {
                $result += $this->strings($value, $at);
            }
        }

        return $result;
    }

    public function assess(array $payload, array $trusted = []): array
    {
        $findings = [];
        $add = function (string $code, string $path, string $text, string $message, string $severity = 'error') use (&$findings): void {
            $findings[] = compact('code', 'path', 'text', 'message', 'severity');
        };
        $strings = $this->strings($payload);
        foreach ($strings as $path => $text) {
            $redistributedFifteen = preg_match('/6\s*,\s*3\s*;\s*5\s*,\s*3\s*;\s*4\s*,\s*3/u', $text)
                || preg_match('/Зона\s*1\s*:\s*6\s*лист[^\n]*\n[^\n]*Зона\s*2\s*:\s*5\s*лист[^\n]*\n[^\n]*Зона\s*3\s*:\s*4\s*лист/ui', $text);
            if ($redistributedFifteen && preg_match('/(?:оставляет|в\s+резерве|резерв[^.\n]{0,25})[^.\n]{0,20}(?:только\s*)?2\s*лист/ui', $text)) {
                $add('RESOURCE_PLAN_RESERVE_CONTRADICTION', $path, $text, 'План6+5+4 расходует15 листов из18, оставляя3, а не2; кроме того, третья зона недополучает лист. Для альтернативы с нарушением резерва используйте6+5+5=16, остаток2. Согласуйте все описания, расчёты и ответы, сохранив оба плана и рабочие поля.');
            }
            if (str_contains($text, '__LLM_WRITE_') || preg_match('/\{\{[^}]+\}\}|<заполнить>/ui', $text)) {
                $add('UNFILLED_MARKER', $path, $text, 'Замените маркер готовым содержанием.');
            }
            if (str_contains($text, '\n') || str_contains($text, '\r')) {
                $add('LITERAL_NEWLINE', $path, $text, 'Используйте настоящие переносы строк JSON, не буквальные слеш-n.');
            }
            if (preg_match('/\}\s*\]\s*\}+\s*,?\s*$/u', $text)) {
                $add('JSON_FRAGMENT_IN_TEXT', $path, $text, 'Удалите остатки JSON-разметки из печатного текста.');
            }
            if (preg_match_all('/(?<![\p{L}\d])([0-9]+)\s*([+*×÷\/−-])\s*([0-9]+)\s*=\s*([0-9]+(?:[.,][0-9]+)?)/u', $text, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $m) {
                    $a = (float) $m[1];
                    $b = (float) $m[3];
                    $actual = match ($m[2]) {
                        '+' => $a + $b, '-', '−' => $a - $b, '*', '×' => $a * $b, default => $b == 0 ? null : $a / $b
                    };
                    if ($actual === null || abs($actual - (float) str_replace(',', '.', $m[4])) > .00001) {
                        $add('ARITHMETIC_EQUALITY', $path, $m[0], 'Арифметическое равенство неверно; проверьте условия и ответ.');
                    }
                }
            }
        }
        $sum = array_sum(array_column($payload['stages'] ?? [], 'duration_minutes'));
        if ($sum !== ($payload['format']['total_duration_minutes'] ?? null)) {
            $add('TIMING_TOTAL', '$.stages', (string) $sum, 'Сумма минут этапов не совпадает с общей длительностью.');
        }
        $activities = $payload['activities'] ?? [];
        if (! $activities || ! in_array($payload['pedagogical_mechanism']['target_activity_title'] ?? '', array_column($activities, 'title'), true)) {
            $add('MECHANISM_ACTIVITY', '$.activities', '', 'Механизм должен ссылаться на существующую активность.');
        }
        $ids = array_column($payload['stages'] ?? [], 'id');
        foreach ($activities as $i => $activity) {
            if (! in_array($activity['target_stage_id'] ?? '', $ids, true)) {
                $add('ACTIVITY_STAGE', '$.activities.'.$i, $activity['title'] ?? '', 'Активность должна иметь реальный этап.');
            }
        }
        $roles = $payload['roles'] ?? [];
        if (count($roles) < 2) {
            $add('ROLES_REVIEW', '$.roles', '', 'Проверьте распределение и взаимозависимость ролей.', 'review');
        }
        foreach ($payload['materials']['printable_cards'] ?? [] as $i => $card) {
            $front = $card['front_text'] ?? '';
            $back = $card['back_text'] ?? '';
            $path = '$.materials.printable_cards.'.$i;
            if (mb_strlen(trim($front)) < 120 || mb_strlen(trim($back)) < 40) {
                $add('EMPTY_CARD', $path, $front, 'Нужны содержательные условия и проверяемый ответ.');
            }
            // Bounded integer optimization: two prices, a budget, >=1 of each.
            if (preg_match_all('/по\s+(\d+)\s*руб/ui', $front, $prices) && count($prices[1]) === 2
                && preg_match('/(?:есть|бюджет(?:ом)?|бюджет\s*[:—-])\s*(\d+)\s*руб/ui', $front, $budget)
                && preg_match('/хотя бы|не менее одн/ui', $front)) {
                [$p, $q] = array_map('intval', $prices[1]);
                $limit = (int) $budget[1];
                $best = null;
                if ($p > 0 && $q > 0 && $limit <= 100000) {
                    for ($n = 1; $n * $p + $q <= $limit; $n++) {
                        $best = max($best ?? 0, $n + intdiv($limit - $n * $p, $q));
                    }
                    if (preg_match('/(?:всего|максимум|максимальное количество(?:\s+\p{L}+){0,3}\s*[:—-]?)\s*(\d+)/ui', $back, $answer) && (int) $answer[1] !== $best) {
                        $add('BUDGET_MAXIMUM', $path.'.back_text', $back, 'Перебор целых покупок даёт максимум '.($best ?? 'невозможен').'; исправьте ответ и пример.');
                    } else {
                        $add('NUMERIC_WORD_PROBLEM_REVIEW', $path, $front, 'Проверьте полноту условий и согласованность ответа вручную.', 'review');
                    }
                }
            }
            if (preg_match('/(?:есть|за|в течение)\s*(\d+)\s*час/ui', $front, $hours)
                && preg_match_all('/[(:]\s*(\d+)\s*мин/ui', $front, $tasks)
                && array_sum(array_map('intval', $tasks[1])) > (int) $hours[1] * 60
                && ! preg_match('/невозможн|не успе|не хват|не помест|не улож|дефицит/ui', $back)) {
                $add('TIME_CONTRADICTION', $path, $front.' Ответ: '.$back, 'Задания превышают доступное время; ответ должен явно объяснить невозможность.');
            }
            if (preg_match('/яблок/ui', $front) && preg_match('/корзин/ui', $front) && preg_match('/неч[её]тн/ui', $front)
                && preg_match('/по\s*[2468]\s*яблок/ui', $back)) {
                $add('BASKET_CONTRADICTION', $path, $back, 'Чётное число яблок противоречит требованию нечётного количества; не скрывайте это вложением корзин.');
            }
            if (preg_match('/(?:5|6)/', implode(',', $payload['target_audience']['target_grades'] ?? []))
                && preg_match('/12\s*конфет|3\s*яблок|2x\s*\+\s*3\s*=\s*7/ui', $front)) {
                $add('AGE_MECHANISM_REVIEW', $path, $front, 'Задание может быть слишком простым для 5–6 классов; проверьте реальную взаимозависимость участников.', 'review');
            }
        }
        if (count($payload['materials']['printable_cards'] ?? []) < 2 || count($payload['materials']['presentation_slides'] ?? []) < 3 || empty($payload['materials']['handouts'])) {
            $add('MATERIALS_MISSING', '$.materials', '', 'Нужны две карточки, три слайда и готовый раздаточный лист.');
        }
        foreach ($payload['materials']['presentation_slides'] ?? [] as $i => $slide) {
            $notes = trim($slide['speaker_notes'] ?? '');
            $quotedSpeech = preg_match('/«[^»]{100,}»|"[^"]{100,}"/u', $notes);
            if (mb_strlen($notes) < 100 || (! $quotedSpeech && preg_match('/^(?:(?:Учитель|Педагог)\s+(?:объясняет|рассказывает)|Объяснить|Рассказать)/ui', $notes))) {
                $add('READY_SLIDE_SPEECH', '$.materials.presentation_slides.'.$i.'.speaker_notes', $notes, 'Нужна готовая прямая речь ведущего >=100 символов, а не обещание объяснить тему. Сохраните все содержательные слайды.');
            }
        }
        foreach ($payload['materials']['handouts'] ?? [] as $i => $handout) {
            $text = $handout['template_content'] ?? '';
            if (mb_strlen(trim($text)) < 200 || (preg_match('/пример|образец/ui', $text, $m, PREG_OFFSET_CAPTURE)
                && mb_strlen(preg_replace('/[_\s.]+/u', '', substr($text, $m[0][1] + strlen($m[0][0])))) < 80)) {
                $add('EMPTY_HANDOUT', '$.materials.handouts.'.$i.'.template_content', $text, 'Раздаточный материал и заполненный пример должны быть содержательными.');
            }
        }
        if (($payload['pedagogical_mechanism']['mechanism_type'] ?? '') === 'team_mutual_support_task') {
            foreach ($payload['stages'] ?? [] as $i => $stage) {
                if (($stage['stage_type'] ?? '') === 'main_interactive_part') {
                    break;
                }
                $text = implode(' ', $this->strings($stage));
                if (preg_match('/показывает\s+(?:заполненный\s+)?пример\s+решения\s+(?:основной\s+)?задачи/ui', $text)
                    && ! preg_match('/не\s+показывает|без\s+(?:чисел|решения)|после\s+(?:общего\s+)?решения/ui', $text)) {
                    $add('MATERIALS_RELEASE_EARLY_EXAMPLE', '$.stages.'.$i, $text, 'До практики допустима репетиция процедуры на нейтральном примере без чисел и решения основной задачи. Заполненный пример остаётся закрытым до общего решения.');
                }
            }
            $release = implode(' ', $this->strings($payload['teacher_instructions'] ?? []));
            if (! preg_match('/оборот/ui', $release) || ! preg_match('/пример|образец|раздат/ui', $release)
                || ! preg_match('/после\s+(?:общего\s+)?решени|после\s+обмен/ui', $release) || ! preg_match('/педагог/ui', $release)) {
                $add('MATERIALS_RELEASE_ORDER', '$.teacher_instructions', '', 'До общего решения участники получают только лицевые ролевые карточки и чистую бумагу. Обороты и handout с заполненным примером остаются у педагога и открываются только после общего решения. Явно включите этот порядок в teacher_instructions, чтобы не раскрыть ответ до обмена данными.');
            }
            if (count($payload['materials']['printable_cards'] ?? []) < 5) {
                $add('DISTRIBUTED_CARDS', '$.materials', '', 'Пяти информационным ролям нужны пять отдельных содержательных карточек, а не две карточки на все роли.');
            }
            foreach ($payload['materials']['printable_cards'] ?? [] as $i => $card) {
                $front = $card['front_text'] ?? '';
                if (preg_match('/числов[а-яё]*\s+ключ|расход\s*15\s*[^\d\s]*\s*9|остаток\s*3\s*[^\d\s]*\s*3/ui', $front)) {
                    $add('DISTRIBUTED_ANSWER_LEAK', '$.materials.printable_cards.'.$i.'.front_text', $front, 'Готовый числовой ключ нельзя печатать на лицевой карточке до обмена сведениями. Перенесите ключ на закрытый оборот, сохранив самостоятельную информационную роль, пять полных карточек и остальные материалы.');
                }
                if (preg_match('/\b18\b/u', $front) && preg_match('/\b12\b/u', $front) && preg_match('/\b5\b/u', $front) && preg_match('/резерв/ui', $front) && preg_match('/\b3\b/u', $front)) {
                    $add('DISTRIBUTED_INFORMATION', '$.materials.printable_cards.'.$i.'.front_text', $front, 'Лицевая сторона раскрывает все данные сразу; разделите запас, потребность и резерв между ролями. Полное решение допускается только на закрытом обороте для педагога.');
                }
            }
            $fronts = implode(' ', array_column($payload['materials']['printable_cards'] ?? [], 'front_text'));
            if (preg_match('/\b18\b/u', $fronts) && preg_match('/\b12\b/u', $fronts)) {
                foreach ($payload['materials']['handouts'] ?? [] as $i => $handout) {
                    $text = $handout['template_content'] ?? '';
                    if (! preg_match('/(?<!\d)18(?!\d)|восемнадцат/ui', $text) || ! preg_match('/(?<!\d)12(?!\d)|двенадцат/ui', $text)) {
                        $add('READY_HANDOUT_RESOURCE_CONTENT', '$.materials.handouts.'.$i.'.template_content', $text, 'Раздаточный лист выбранной ресурсной задачи должен содержать конкретные исходные18 листов и12 карандашей, потребности5/3, резерв3/2 и заполненный проверяемый пример (расход15/9, остаток3/3), а не общие слова «определённое количество». Сохраните рабочие поля и все остальные материалы; лист с примером выдаётся после общего решения.');
                    }
                }
                $key = implode(' ', array_column($payload['materials']['printable_cards'] ?? [], 'back_text'));
                if (! preg_match('/15|пятнадцат/ui', $key) || ! preg_match('/9|девят/ui', $key)) {
                    $add('READY_CARD_RESOURCE_KEY', '$.materials', '', 'На закрытом обороте для педагога нужен числовой ключ: 3 комплекта, расход15/9, остаток3/3, а не только просьба проверить.');
                }
                foreach ($payload['materials']['presentation_slides'] ?? [] as $i => $slide) {
                    $text = implode(' ', $this->strings($slide));
                    if (preg_match('/\b18\b/u', $text) && preg_match('/\b12\b/u', $text) && preg_match('/\b5\b/u', $text)
                        && ! preg_match('/после\s+(?:общего\s+)?решени|после\s+обмен|только\s+при\s+разбор/ui', $text)) {
                        $add('DISTRIBUTED_SLIDE_LEAK', '$.materials.presentation_slides.'.$i, $text, 'Слайд раскрывает все условия до обмена. Уберите полные числа со слайда либо явно разрешите показывать его только после общего решения.');
                    }
                }
                $activityText = implode(' ', $this->strings($payload['activities'] ?? []));
                if (! preg_match('/дополнительн[а-яё]*\s+лист|ещ[её]\s+(?:один\s+)?лист/ui', $activityText)) {
                    $add('MECHANISM_TWO_PLANS', '$.activities', '', 'Напечатайте оба сравниваемых плана в активности: три одинаковых комплекта или дополнительный лист одной зоне; второй оставляет2 вместо требуемых3. Не оставляйте «сравните два плана» без самих планов.');
                }
            }
        }
        $all = implode("\n", $strings);
        if (($payload['format']['scale'] ?? '') === 'all_school' && ($payload['target_audience']['participants_count_max'] ?? 0) >= 100) {
            if (($payload['target_audience']['participants_count_max'] ?? 0) === 150) {
                $pairs = true;
                foreach ([[1, 2], [3, 4], [5, 6], [7, 8], [9, 10]] as [$left, $right]) {
                    $pairs = $pairs && preg_match('/\b'.$left.'\s*[-–—]\s*'.$right.'\b/u', $all);
                }
                if (! preg_match('/(?:10|десять)\s*групп/ui', $all) || ! preg_match('/по\s*(?:15|пятнадцат)/ui', $all)
                    || ! preg_match('/(?:5|пять)\s*(?:зон|кабинет|станци)/ui', $all)
                    || ! preg_match('/(?:5|пять)\s*(?:взросл|координатор)/ui', $all) || ! $pairs
                    || ! preg_match('/по\s+круг|кольц|следующ[а-яё]*\s+зон/ui', $all)
                    || ! preg_match('/без\s+переход|за\s+стол/ui', $all)) {
                    $add('SCHOOL_LOGISTICS_PLAN', '$.teacher_instructions.methodological_recommendations', '', 'В поле teacher_instructions.methodological_recommendations напечатайте конкретный план: 10 групп по15, 5 зон и 5 взрослых координаторов. Старт: группы1–2 — зона1; 3–4 — зона2; 5–6 — зона3; 7–8 — зона4; 9–10 — зона5. Раунды2–5: каждая пара переходит в следующую зону по кольцу5→1. В каждом раунде4 минуты работы и2 минуты перехода; последний переход — возврат. Если5 взрослых нет, те же раунды за столами без переходов. Не возвращайте вместо плана прежнюю строку adaptation_for_disabilities_or_small_groups.');
                }
            }
            foreach (['групп' => 'группы и количество в них', 'помещен|кабинет|зал|станци' => 'помещения/станции', 'переход' => 'переходы с длительностью', 'координатор|ответственн' => 'ответственные', 'очеред|маршрут|поток' => 'очерёдность/потоки'] as $pattern => $label) {
                if (! preg_match('/'.$pattern.'/ui', $all)) {
                    $add('SCHOOL_LOGISTICS', '$.stages', '', 'Для 100+ участников явно опишите: '.$label.'.');
                }
            }
            $mainStages = array_values(array_filter($payload['stages'] ?? [], fn ($stage) => ($stage['stage_type'] ?? '') === 'main_interactive_part'));
            if (count($mainStages) < 5 || count($activities) < 5) {
                $add('SCHOOL_LOGISTICS_ROUNDS', '$.stages', '', 'Нужны пять этапов stage_type=main_interactive_part и пять активностей; найдено этапов: '.count($mainStages).', активностей: '.count($activities).'. Напечатайте в действиях каждого раунда «4 минуты работы, 2 минуты перехода» цифрами, сохранив итоговые60 минут и ссылки target_stage_id.');
            }
            if (! preg_match('/(?:4\s*мин[^.]{0,80}2\s*мин|2\s*мин[^.]{0,80}4\s*мин)/ui', $all)) {
                $add('SCHOOL_LOGISTICS_TRANSITIONS', '$.teacher_instructions', '', 'В общей инструкции явно напечатайте для каждого из пяти раундов: 4 минуты работы, 2 минуты перехода. Все раунды по6 минут, всего30, включая последний возврат. Если число этапов/активностей верное, не переписывайте их ради этой локальной инструкции.');
            }
            $add('LOGISTICS_FEASIBILITY_REVIEW', '$.stages', '', 'Ответственный педагог проверяет вместимость, число взрослых и реализуемость маршрутов.', 'review');
        }
        if (($payload['format']['scale'] ?? '') === 'family_community') {
            if (! preg_match('/без\s+(?:родственник|родител|семь)|(?:наставник|добровольн|без объяснения)/ui', $all)) {
                $add('FAMILY_INCLUSION', '$.teacher_instructions', '', 'Нужна добровольная альтернатива без родственника и без объяснения семейных обстоятельств.');
            }
            $familyStages = array_values(array_filter($payload['stages'] ?? [], fn ($stage) => ($stage['stage_type'] ?? '') === 'main_interactive_part'));
            $adult = collect($roles)->contains(fn ($role) => in_array($role['target_participants'] ?? '', ['parents', 'guests'], true));
            if (count($familyStages) < 3 || count($activities) < 3 || ! $adult || ! preg_match('/меня[а-яё]*\s+рол|смен[а-яё]*\s+рол/ui', $all)) {
                $add('FAMILY_WORKSHOP', '$.activities', '', 'Нужны три разные практические части, действующая добровольная взрослая роль и явная смена ролей; family_involvement=true недостаточно.');
            }
            if (! preg_match('/без\s+(?:доступн[а-яё]*\s+)?(?:родственник|родител)/ui', $all) || ! preg_match('/без\s+объяснен|не\s+объясня|не\s+нужно\s+объясня/ui', $all)) {
                $add('FAMILY_INCLUSION_EXPLICIT', '$.teacher_instructions', '', 'Явно опишите ребёнка без родственника и участие без объяснения причин/семейных обстоятельств.');
            }
            $add('FAMILY_CONTENT_REVIEW', '$.activities', '', 'Проверьте содержательность всех 90 минут, взаимодействие поколений и роли ребёнка без родственников.', 'review');
        }
        $coverage = app(CulturalTextCoverage::class)->assess($strings, $trusted);
        foreach ($coverage['findings'] as $f) {
            $findings[] = $f;
        }
        $errors = count(array_filter($findings, fn ($f) => $f['severity'] === 'error'));

        return ['gate_version' => self::VERSION, 'status' => $errors ? 'blocked' : 'requires_review',
            'technical_checks' => $errors ? 'failed' : 'passed', 'expert_assessment' => 'not_performed',
            'teacher_signoff_required' => true, 'payload_sha256' => hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'cultural_coverage' => $coverage, 'findings' => $findings,
            'limitations' => ['Детерминированные правила не доказывают полноту извлечения фактов или педагогическую пригодность. Требуется ручная оценка всего текста.']];
    }

    public function validate(array $payload, array $trusted = []): array
    {
        $report = $this->assess($payload, $trusted);
        $errors = [];
        foreach ($report['findings'] as $f) {
            if ($f['severity'] === 'error') {
                $errors['QUALITY_'.$f['code'].'_'.$f['path']][] = $f['message'].' Текст: '.$f['text'];
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $report;
    }

    public function blocksUse(ScenarioRevision $revision): bool
    {
        // Existing mock fixtures remain test fixtures; every actual LiteLLM
        // descendant (including teacher edits) is evaluated under the new gate.
        $real = GenerationRun::where('scenario_request_id', $revision->scenario?->scenario_request_id)
            ->where('provider_name', 'litellm')->exists();

        return $real && $this->forRevision($revision)['status'] === 'blocked';
    }

    public function forRevision(ScenarioRevision $revision): array
    {
        $request = $revision->scenario?->scenarioRequest;
        $trusted = $request ? app(TrustedGenerationContext::class)->forRequest($request)['cultural_provenance']['claims'] ?? [] : [];

        return $this->assess($revision->content_payload ?? [], $trusted);
    }
}
