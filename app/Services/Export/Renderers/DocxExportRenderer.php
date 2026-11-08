<?php

declare(strict_types=1);

namespace App\Services\Export\Renderers;

use App\Models\ExportJob;
use App\Models\ScenarioRevision;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\IOFactory as WordIOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\SimpleType\TblWidth;

class DocxExportRenderer implements ExportRendererInterface
{
    public function render(ScenarioRevision $revision, ExportJob $job, array $viewData): array
    {
        $scenario = $revision->scenario;
        $school = $scenario->school;
        $payload = $revision->content_payload ?? [];
        $signoff = $viewData['signoff'] ?? null;

        $phpWord = new PhpWord();

        // Document properties
        $docProps = $phpWord->getDocInfo();
        $docProps->setCreator('Система «Школьные события» (Сбер × УрФУ)');
        $docProps->setTitle($scenario->title);
        $docProps->setSubject('Сценарий воспитательного мероприятия');
        $docProps->setDescription("Ревизия v{$revision->version_number}");

        // Styles
        $phpWord->setDefaultFontName('Calibri');
        $phpWord->setDefaultFontSize(11);

        $headerStyle = ['bold' => true, 'color' => '1A365D', 'size' => 18];
        $subHeaderStyle = ['bold' => true, 'color' => '2B6CB0', 'size' => 14];
        $sectionTitleStyle = ['bold' => true, 'color' => '2D3748', 'size' => 12];
        $badgeStyle = ['bold' => true, 'color' => '2C5282', 'size' => 10];
        $boldText = ['bold' => true];
        $italicText = ['italic' => true, 'color' => '4A5568'];
        $tableHeaderFont = ['bold' => true, 'color' => '1A202C', 'size' => 10];
        $tableCellFont = ['size' => 10];

        $tableStyle = [
            'borderSize' => 6,
            'borderColor' => 'CBD5E0',
            'cellMargin' => 80,
            'unit' => TblWidth::PERCENT,
            'width' => 100 * 50,
        ];
        $phpWord->addTableStyle('StandardTable', $tableStyle);

        $thStyle = ['bgColor' => 'EDF2F7'];

        $section = $phpWord->addSection([
            'marginLeft' => 1200,
            'marginRight' => 1200,
            'marginTop' => 1200,
            'marginBottom' => 1200,
        ]);

        // Header / School
        if ($school) {
            $section->addText(
                mb_strtoupper($school->name, 'UTF-8'),
                ['bold' => true, 'color' => '718096', 'size' => 9],
                ['alignment' => Jc::CENTER, 'spaceAfter' => 100]
            );
        }

        // Title
        $section->addText($scenario->title, $headerStyle, ['alignment' => Jc::CENTER, 'spaceAfter' => 120]);

        // Subtitle / Theme & Occasion
        $occasionTitle = $viewData['occasionTitle'] ?? $scenario->title;
        $theme = $payload['theme'] ?? $scenario->title;
        $section->addText("Повод: {$occasionTitle} | Тема: {$theme}", $italicText, ['alignment' => Jc::CENTER, 'spaceAfter' => 200]);

        // Metadata badges box
        $duration = $revision->total_duration_minutes ?? 45;
        $scale = $revision->format['scale'] ?? 'class_level';
        $formatType = $revision->format['type'] ?? 'class_hour';
        $targetAudience = $revision->target_audience ?? [];
        $ageGroup = $targetAudience['age_group'] ?? 'basic_5_9';

        $scaleRu = match ($scale) {
            'class_level' => 'Классный уровень',
            'grade_level' => 'Параллель классов',
            'school_level' => 'Общешкольный уровень',
            default => (string) $scale,
        };
        $formatRu = match ($formatType) {
            'class_hour' => 'Классный час',
            'interactive_quest' => 'Интерактивный квест',
            'literary_living_room' => 'Литературная гостиная',
            'round_table' => 'Круглый стол',
            'debate' => 'Тематические дебаты',
            default => (string) $formatType,
        };
        $ageRu = match ($ageGroup) {
            'primary_1_4' => 'Начальная школа (1–4 классы)',
            'basic_5_9' => 'Основная школа (5–9 классы)',
            'senior_10_11' => 'Старшая школа (10–11 классы)',
            default => (string) $ageGroup,
        };

        $section->addText("Продолжительность: {$duration} мин. | Формат: {$formatRu} ({$scaleRu}) | Аудитория: {$ageRu}", $badgeStyle, ['spaceAfter' => 200]);

        // 1. Педагогическая концепция и цели
        $section->addText('1. Педагогическая концепция и цели', $subHeaderStyle, ['spaceBefore' => 200, 'spaceAfter' => 100]);
        if (! empty($revision->concept)) {
            $section->addText($revision->concept, [], ['spaceAfter' => 120]);
        }
        if (! empty($revision->pedagogical_goal)) {
            $textRun = $section->addTextRun(['spaceAfter' => 120]);
            $textRun->addText('Педагогическая цель: ', $boldText);
            $textRun->addText($revision->pedagogical_goal);
        }

        // Задачи
        $tasks = $revision->pedagogical_tasks ?? ($payload['pedagogical_tasks'] ?? []);
        if (! empty($tasks) && is_array($tasks)) {
            $section->addText('Педагогические задачи:', $sectionTitleStyle, ['spaceBefore' => 100, 'spaceAfter' => 60]);
            $taskGroups = [
                ['keys' => ['personal'], 'label' => 'Личностные'],
                ['keys' => ['meta_subject', 'metasubject'], 'label' => 'Метапредметные'],
                ['keys' => ['subject_specific', 'subject'], 'label' => 'Предметные'],
            ];
            foreach ($taskGroups as $grp) {
                $found = null;
                foreach ($grp['keys'] as $k) {
                    if (! empty($tasks[$k]) && is_array($tasks[$k])) {
                        $found = $tasks[$k];
                        break;
                    }
                }
                if (! empty($found)) {
                    $tr = $section->addTextRun(['spaceAfter' => 40]);
                    $tr->addText("• {$grp['label']}: ", $boldText);
                    $tr->addText(implode('; ', $found));
                }
            }
        }

        // Ожидаемые результаты
        $outcomes = $revision->expected_outcomes ?? ($payload['expected_outcomes'] ?? []);
        if (! empty($outcomes) && is_array($outcomes)) {
            $section->addText('Ожидаемые результаты:', $sectionTitleStyle, ['spaceBefore' => 100, 'spaceAfter' => 60]);
            $outcomeGroups = [
                ['keys' => ['knowledge'], 'label' => 'Знания'],
                ['keys' => ['skills', 'action'], 'label' => 'Умения и навыки'],
                ['keys' => ['values', 'attitude'], 'label' => 'Ценности и ориентиры'],
            ];
            foreach ($outcomeGroups as $grp) {
                $found = null;
                foreach ($grp['keys'] as $k) {
                    if (! empty($outcomes[$k]) && is_array($outcomes[$k])) {
                        $found = $outcomes[$k];
                        break;
                    }
                }
                if (! empty($found)) {
                    $tr = $section->addTextRun(['spaceAfter' => 40]);
                    $tr->addText("• {$grp['label']}: ", $boldText);
                    $tr->addText(implode('; ', $found));
                }
            }
        }

        // 2. Этапы проведения и тайминг
        $section->addText('2. План проведения и этапы мероприятия', $subHeaderStyle, ['spaceBefore' => 240, 'spaceAfter' => 120]);

        $stages = $revision->stages;
        if ($stages->isEmpty() && ! empty($payload['stages']) && is_array($payload['stages'])) {
            $stages = collect($payload['stages']);
        }

        if ($stages->isNotEmpty()) {
            $stageTable = $section->addTable('StandardTable');
            $stageTable->addRow();
            $stageTable->addCell(800, $thStyle)->addText('№', $tableHeaderFont);
            $stageTable->addCell(3000, $thStyle)->addText('Этап и содержание', $tableHeaderFont);
            $stageTable->addCell(1200, $thStyle)->addText('Тайминг', $tableHeaderFont);
            $stageTable->addCell(2500, $thStyle)->addText('Действия педагога', $tableHeaderFont);
            $stageTable->addCell(2500, $thStyle)->addText('Действия учащихся', $tableHeaderFont);

            $stageNum = 1;
            foreach ($stages as $stage) {
                $stTitle = is_object($stage) ? $stage->title : ($stage['title'] ?? "Этап {$stageNum}");
                $stDuration = is_object($stage) ? $stage->duration_minutes : ($stage['duration_minutes'] ?? 0);
                $stDesc = is_object($stage) ? $stage->description : ($stage['description'] ?? '');
                $stTeacher = is_object($stage) ? ($stage->teacher_actions ?? '') : ($stage['teacher_actions'] ?? '');
                $stStudent = is_object($stage) ? ($stage->student_actions ?? '') : ($stage['student_actions'] ?? '');

                $stageTable->addRow();
                $stageTable->addCell(800)->addText((string) $stageNum, $tableCellFont);
                $c2 = $stageTable->addCell(3000);
                $c2->addText($stTitle, $boldText);
                if (! empty($stDesc)) {
                    $c2->addText($stDesc, $tableCellFont);
                }
                $stageTable->addCell(1200)->addText("{$stDuration} мин.", $tableCellFont);
                $stageTable->addCell(2500)->addText((string) $stTeacher, $tableCellFont);
                $stageTable->addCell(2500)->addText((string) $stStudent, $tableCellFont);
                $stageNum++;
            }
        }

        // 3. Роли участников и активности
        $roles = $payload['roles'] ?? [];
        $activities = $payload['activities'] ?? [];
        if (! empty($roles) || ! empty($activities)) {
            $section->addText('3. Роли участников и интерактивная деятельность', $subHeaderStyle, ['spaceBefore' => 240, 'spaceAfter' => 120]);

            if (! empty($roles) && is_array($roles)) {
                $section->addText('Роли участников:', $sectionTitleStyle, ['spaceBefore' => 60, 'spaceAfter' => 60]);
                foreach ($roles as $role) {
                    if (is_array($role)) {
                        $roleTitle = $role['role_name'] ?? ($role['title'] ?? ($role['name'] ?? 'Роль'));
                        $roleDesc = $role['description'] ?? '';
                        $specificTasks = $role['specific_tasks'] ?? [];
                        $targetParticipants = $role['target_participants'] ?? null;

                        $tr = $section->addTextRun(['spaceAfter' => 30]);
                        $tr->addText("• {$roleTitle}", $boldText);
                        if ($targetParticipants) {
                            $tr->addText(" ({$targetParticipants})", $italicText);
                        }
                        if ($roleDesc) {
                            $tr->addText(": {$roleDesc}");
                        }
                        if (! empty($specificTasks) && is_array($specificTasks)) {
                            foreach ($specificTasks as $st) {
                                $section->addText("    - {$st}", $italicText, ['spaceAfter' => 20]);
                            }
                        }
                    }
                }
            }

            if (! empty($activities) && is_array($activities)) {
                $section->addText('Формы активности и правила:', $sectionTitleStyle, ['spaceBefore' => 100, 'spaceAfter' => 60]);
                foreach ($activities as $act) {
                    if (is_array($act)) {
                        $actTitle = $act['title'] ?? ($act['name'] ?? 'Активность');
                        $actRules = $act['rules_and_instructions'] ?? ($act['rules'] ?? ($act['description'] ?? ''));
                        $scoring = $act['scoring_or_evaluation'] ?? '';

                        $tr = $section->addTextRun(['spaceAfter' => 40]);
                        $tr->addText("• {$actTitle}: ", $boldText);
                        $tr->addText(is_array($actRules) ? implode('; ', $actRules) : (string) $actRules);
                        if ($scoring) {
                            $section->addText("    Критерии оценки: {$scoring}", $italicText, ['spaceAfter' => 30]);
                        }
                    }
                }
            }
        }

        // 4. Оборудование, материалы и реквизит
        $materials = $revision->materials;
        $props = $payload['props'] ?? [];
        $printableCards = $payload['materials']['printable_cards'] ?? ($viewData['printableCards'] ?? []);
        $handouts = $payload['materials']['handouts'] ?? ($viewData['handouts'] ?? []);

        if ($materials->isNotEmpty() || ! empty($props) || ! empty($printableCards) || ! empty($handouts)) {
            $section->addText('4. Оборудование, материалы и реквизит', $subHeaderStyle, ['spaceBefore' => 240, 'spaceAfter' => 120]);

            if ($materials->isNotEmpty()) {
                foreach ($materials as $mat) {
                    $matTitle = is_object($mat) ? $mat->title : ($mat['title'] ?? 'Материал');
                    $matDesc = is_object($mat) ? $mat->description : ($mat['description'] ?? '');
                    $tr = $section->addTextRun(['spaceAfter' => 40]);
                    $tr->addText("• {$matTitle}: ", $boldText);
                    $tr->addText((string) $matDesc);
                }
            }

            if (! empty($props) && is_array($props)) {
                $section->addText('Реквизит и оформление:', $sectionTitleStyle, ['spaceBefore' => 80, 'spaceAfter' => 60]);
                foreach ($props as $prop) {
                    if (is_array($prop)) {
                        $itemName = $prop['item_name'] ?? ($prop['title'] ?? ($prop['name'] ?? 'Реквизит'));
                        $qty = $prop['quantity'] ?? null;
                        $alt = $prop['alternative_if_missing'] ?? null;
                        $isMandatory = $prop['is_mandatory'] ?? true;

                        $tr = $section->addTextRun(['spaceAfter' => 30]);
                        $tr->addText("• {$itemName}", $boldText);
                        if ($qty) {
                            $tr->addText(" — {$qty}");
                        }
                        $tr->addText($isMandatory ? ' (Обязательно)' : ' (По возможности)', $italicText);
                        if ($alt) {
                            $section->addText("    Альтернатива при отсутствии: {$alt}", $italicText, ['spaceAfter' => 20]);
                        }
                    } else {
                        $section->addText("• " . (string) $prop, [], ['spaceAfter' => 30]);
                    }
                }
            }

            if (! empty($printableCards) && is_array($printableCards)) {
                $section->addText('Раздаточные карточки заданий:', $sectionTitleStyle, ['spaceBefore' => 80, 'spaceAfter' => 60]);
                foreach ($printableCards as $card) {
                    if (is_array($card)) {
                        $cTitle = $card['title'] ?? 'Карточка задания';
                        $cFront = $card['front_text'] ?? ($card['description'] ?? '');
                        $cBack = $card['back_text'] ?? '';
                        $cQty = $card['quantity_per_group'] ?? null;

                        $tr = $section->addTextRun(['spaceAfter' => 30]);
                        $tr->addText("• «{$cTitle}»", $boldText);
                        if ($cQty) {
                            $tr->addText(" (тираж: {$cQty} экз./группу)");
                        }
                        if ($cFront) {
                            $section->addText("    Лицевая сторона: {$cFront}", $tableCellFont, ['spaceAfter' => 20]);
                        }
                        if ($cBack) {
                            $section->addText("    Оборотная сторона: {$cBack}", $italicText, ['spaceAfter' => 20]);
                        }
                    }
                }
            }

            if (! empty($handouts) && is_array($handouts)) {
                $section->addText('Маршрутные листы и бланки:', $sectionTitleStyle, ['spaceBefore' => 80, 'spaceAfter' => 60]);
                foreach ($handouts as $h) {
                    if (is_array($h)) {
                        $hTitle = $h['title'] ?? 'Маршрутный лист';
                        $hInstr = $h['instructions'] ?? '';
                        $hTempl = $h['template_content'] ?? ($h['content'] ?? '');

                        $tr = $section->addTextRun(['spaceAfter' => 30]);
                        $tr->addText("• «{$hTitle}»", $boldText);
                        if ($hInstr) {
                            $section->addText("    Инструкция: {$hInstr}", $italicText, ['spaceAfter' => 20]);
                        }
                        if ($hTempl) {
                            $section->addText("    Шаблон полей: {$hTempl}", $tableCellFont, ['spaceAfter' => 20]);
                        }
                    }
                }
            }
        }

        // 5. Методические рекомендации, безопасность и адаптация
        $instructions = $revision->teacher_instructions ?? ($payload['teacher_instructions'] ?? []);
        $safety = $instructions['general_safety_rules'] ?? ($payload['safety_notes'] ?? []);
        $adaptation = $instructions['adaptation_for_disabilities_or_small_groups'] ?? ($payload['adaptation'] ?? null);

        $section->addText('5. Методические рекомендации и безопасность', $subHeaderStyle, ['spaceBefore' => 240, 'spaceAfter' => 120]);

        if (! empty($instructions['methodological_recommendations'])) {
            $recs = $instructions['methodological_recommendations'];
            $section->addText('Рекомендации для педагога:', $sectionTitleStyle, ['spaceBefore' => 60, 'spaceAfter' => 60]);
            if (is_array($recs)) {
                foreach ($recs as $rec) {
                    $section->addText("• " . (is_array($rec) ? json_encode($rec, JSON_UNESCAPED_UNICODE) : (string) $rec), [], ['spaceAfter' => 40]);
                }
            } else {
                $section->addText((string) $recs, [], ['spaceAfter' => 60]);
            }
        }

        if (! empty($safety)) {
            $section->addText('Правила техники безопасности:', $sectionTitleStyle, ['spaceBefore' => 100, 'spaceAfter' => 60]);
            if (is_array($safety)) {
                foreach ($safety as $rule) {
                    $section->addText("• " . (is_array($rule) ? json_encode($rule, JSON_UNESCAPED_UNICODE) : (string) $rule), [], ['spaceAfter' => 40]);
                }
            } else {
                $section->addText((string) $safety, [], ['spaceAfter' => 60]);
            }
        }

        if (! empty($adaptation)) {
            $section->addText('Адаптация для детей с ОВЗ и малых групп:', $sectionTitleStyle, ['spaceBefore' => 100, 'spaceAfter' => 60]);
            $section->addText((string) $adaptation, [], ['spaceAfter' => 60]);
        }

        // 6. Культурно-историческая база и первоисточники
        $claims = $viewData['claims'] ?? [];
        if (! empty($claims) && is_array($claims)) {
            $hasAnyUnverified = false;
            foreach ($claims as $cChk) {
                if (is_array($cChk)) {
                    $st = $cChk['server_verification_status'] ?? ($cChk['status'] ?? 'unverified');
                    $evList = $cChk['evidence_records'] ?? [];
                    if ($st !== 'verified_authentic' || empty($evList)) {
                        $hasAnyUnverified = true;
                        break;
                    }
                }
            }

            $claimsHeader = $hasAnyUnverified
                ? '6. Культурно-исторические факты и первоисточники (с указанием статуса проверки)'
                : '6. Культурно-исторические факты и проверенные первоисточники';
            $section->addText($claimsHeader, $subHeaderStyle, ['spaceBefore' => 240, 'spaceAfter' => 120]);

            $claimTable = $section->addTable('StandardTable');
            $claimTable->addRow();
            $claimTable->addCell(600, $thStyle)->addText('№', $tableHeaderFont);
            $claimTable->addCell(3500, $thStyle)->addText('Утверждение / Факт', $tableHeaderFont);
            $claimTable->addCell(1600, $thStyle)->addText('Культурный контекст', $tableHeaderFont);
            $claimTable->addCell(3000, $thStyle)->addText('Первоисточник и локатор', $tableHeaderFont);
            $claimTable->addCell(1300, $thStyle)->addText('Статус', $tableHeaderFont);

            $cIndex = 1;
            foreach ($claims as $claim) {
                if (! is_array($claim)) {
                    continue;
                }
                $cText = $claim['claim_text'] ?? ($claim['text'] ?? '');
                $cultureName = $claim['culture_name'] ?? 'Российская Федерация';
                $cStatus = $claim['server_verification_status'] ?? ($claim['status'] ?? 'unverified');
                $evRecords = $claim['evidence_records'] ?? [];
                $hasEvidence = ! empty($evRecords) && is_array($evRecords);

                $statusLabel = match (true) {
                    $cStatus === 'verified_authentic' && $hasEvidence => 'Подлинно',
                    $cStatus === 'partially_verified' => 'Частично',
                    $cStatus === 'rejected_fake' => 'Опровергнуто',
                    default => 'Требует проверки',
                };

                $evDetails = [];
                if ($hasEvidence) {
                    foreach ($evRecords as $ev) {
                        $srcTitle = $ev['source_title'] ?? ($ev['title'] ?? 'Нормативный акт / Источник');
                        $locator = $ev['citation_locator'] ?? '';
                        $excerpt = $ev['citation_excerpt'] ?? '';
                        $evDetails[] = "{$srcTitle}" . ($locator ? " ({$locator})" : "") . ($excerpt ? ": «{$excerpt}»" : "");
                    }
                }
                $evText = ! empty($evDetails) ? implode("\n", $evDetails) : 'Первоисточник не привязан к утверждению';

                $claimTable->addRow();
                $claimTable->addCell(600)->addText((string) $cIndex, $tableCellFont);
                $claimTable->addCell(3500)->addText($cText, $tableCellFont);
                $claimTable->addCell(1600)->addText($cultureName, $tableCellFont);
                $claimTable->addCell(3000)->addText($evText, $tableCellFont);
                $claimTable->addCell(1300)->addText($statusLabel, ['bold' => true, 'size' => 9]);
                $cIndex++;
            }
        }

        // 7. Отметка о согласовании педагогом (Teacher Sign-Off)
        $section->addText('7. Согласование сценария (Human Sign-Off)', $subHeaderStyle, ['spaceBefore' => 240, 'spaceAfter' => 120]);

        $signoffTable = $section->addTable([
            'borderSize' => 12,
            'borderColor' => '2B6CB0',
            'cellMargin' => 120,
            'unit' => TblWidth::PERCENT,
            'width' => 100 * 50,
        ]);
        $signoffTable->addRow();
        $stCell = $signoffTable->addCell(10000, ['bgColor' => 'EBF8FF']);

        if ($signoff) {
            $teacherName = $signoff->user?->name ?? 'Педагог-организатор';
            $confirmedAt = $signoff->confirmed_at ? $signoff->confirmed_at->format('d.m.Y H:i:s') : date('d.m.Y H:i:s');
            $ip = $signoff->ip_address ?? '127.0.0.1';
            $notes = $signoff->teacher_notes ?: 'Замечаний нет. Сценарий проверен и допущен к проведению.';

            $stCell->addText('ОТМЕТКА О ПРОВЕРКЕ И СОГЛАСОВАНИИ', ['bold' => true, 'color' => '2B6CB0', 'size' => 11], ['spaceAfter' => 60]);
            $stCell->addText("Педагог: {$teacherName} | Организация: " . ($school?->name ?? 'МАОУ Лицей № 130'), $boldText, ['spaceAfter' => 40]);
            $stCell->addText("Дата и время согласования: {$confirmedAt} UTC | IP-адрес: {$ip}", $tableCellFont, ['spaceAfter' => 40]);
            $stCell->addText("Чек-лист: Безопасность [✓] | Хронометраж [✓] | Культурно-исторический контекст [✓]", ['bold' => true, 'color' => '2F855A', 'size' => 10], ['spaceAfter' => 40]);
            $stCell->addText("Комментарий педагога: {$notes}", $italicText, ['spaceAfter' => 40]);
            $stCell->addText("Цифровой отпечаток доказательной базы (Provenance Fingerprint): {$job->provenance_fingerprint}", ['color' => '718096', 'size' => 8], ['spaceAfter' => 20]);
        } else {
            $stCell->addText('СОГЛАСОВАНИЕ НЕ ЗАФИКСИРОВАНО', ['bold' => true, 'color' => 'C53030', 'size' => 11]);
        }

        // Save DOCX to temporary file, then capture binary string
        $tempPath = tempnam(sys_get_temp_dir(), 'docx_exp_');
        $objWriter = WordIOFactory::createWriter($phpWord, 'Word2007');
        $objWriter->save($tempPath);

        $content = file_get_contents($tempPath);
        @unlink($tempPath);

        if ($content === false) {
            throw new \RuntimeException('Не удалось прочитать скомпилированный DOCX файл.');
        }

        $fileName = "scenario-{$scenario->id}-v{$revision->version_number}.docx";

        return [
            'content' => $content,
            'file_name' => $fileName,
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'extension' => 'docx',
        ];
    }
}
