<?php

declare(strict_types=1);

namespace App\Services\Export\Renderers;

use App\Models\ExportJob;
use App\Models\ScenarioRevision;
use PhpOffice\PhpPresentation\DocumentLayout;
use PhpOffice\PhpPresentation\IOFactory as PresIOFactory;
use PhpOffice\PhpPresentation\PhpPresentation;
use PhpOffice\PhpPresentation\Slide;
use PhpOffice\PhpPresentation\Style\Alignment;
use PhpOffice\PhpPresentation\Style\Color;

class PptxExportRenderer implements ExportRendererInterface
{
    public function render(ScenarioRevision $revision, ExportJob $job, array $viewData): array
    {
        $oldLevel = error_reporting();
        error_reporting($oldLevel & ~E_DEPRECATED & ~E_USER_DEPRECATED);

        try {
            $scenario = $revision->scenario;
            $school = $scenario->school;
            $payload = $revision->content_payload ?? [];
            $claims = $viewData['claims'] ?? [];
            $stages = $viewData['stages'] ?? $revision->stages;

            $presentation = new PhpPresentation();
            $presentation->getLayout()->setDocumentLayout(DocumentLayout::LAYOUT_SCREEN_16X9);

            // Document properties
            $docProps = $presentation->getDocumentProperties();
            $docProps->setCreator('Система «Школьные события» (Сбер × УрФУ)');
            $docProps->setTitle($scenario->title);
            $docProps->setSubject('Презентация сценария воспитательного мероприятия');
            $docProps->setDescription("Презентация из 5 слайдов 16:9 для ревизии v{$revision->version_number}");

            // Extract or construct 5 slide payloads
            $slideSpecs = $this->resolveFiveSlides($scenario, $revision, $payload, $claims, $stages, $school);

            // Slide 1 uses active slide, slides 2-5 are created
            foreach ($slideSpecs as $index => $spec) {
                $slide = ($index === 0) ? $presentation->getActiveSlide() : $presentation->createSlide();
                $this->buildSlide($slide, $spec, $index + 1);
            }

            // Save PPTX to temporary file, then capture binary string
            $tempPath = tempnam(sys_get_temp_dir(), 'pptx_exp_');
            $objWriter = PresIOFactory::createWriter($presentation, 'PowerPoint2007');
            $objWriter->save($tempPath);

            $content = file_get_contents($tempPath);
            @unlink($tempPath);

            if ($content === false) {
                throw new \RuntimeException('Не удалось прочитать скомпилированный PPTX файл.');
            }

            $fileName = "scenario-presentation-{$scenario->id}-v{$revision->version_number}.pptx";

            return [
                'content' => $content,
                'file_name' => $fileName,
                'mime_type' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                'extension' => 'pptx',
            ];
        } finally {
            error_reporting($oldLevel);
        }
    }

    /**
     * Build standard 5-slide structure adhering strictly to canonical revision data.
     *
     * @param array<string, mixed> $payload
     * @param list<mixed> $claims
     * @param iterable<mixed> $stages
     * @return list<array{
     *     title: string,
     *     subtitle: string,
     *     bullets: list<string>,
     *     provenance_tag: ?string,
     *     speaker_notes: string
     * }>
     */
    public function resolveFiveSlides(
        object $scenario,
        ScenarioRevision $revision,
        array $payload,
        array $claims,
        iterable $stages,
        ?object $school
    ): array {
        $rawSlides = $payload['materials']['presentation_slides'] ?? ($payload['presentation_slides'] ?? []);

        $duration = $revision->total_duration_minutes ?? 45;
        $schoolName = $school?->name ?? 'МАОУ Лицей № 130';
        $theme = $payload['theme'] ?? $scenario->title;
        $goal = $revision->pedagogical_goal ?? 'Воспитание уважения к историческому и культурному наследию.';

        // Analyze claims provenance
        $hasAnyUnverifiedClaim = false;
        $verifiedSources = [];
        $claimBullets = [];

        if (! empty($claims) && is_array($claims)) {
            foreach ($claims as $cl) {
                if (! is_array($cl)) {
                    continue;
                }
                $cText = $cl['claim_text'] ?? ($cl['text'] ?? '');
                $cStatus = $cl['server_verification_status'] ?? ($cl['status'] ?? 'unverified');
                $evs = $cl['evidence_records'] ?? [];
                $hasEv = ! empty($evs) && is_array($evs);
                $isAuthentic = ($cStatus === 'verified_authentic' && $hasEv);

                if ($isAuthentic) {
                    $srcTitle = $evs[0]['source_title'] ?? 'Первоисточник';
                    $srcLoc = ! empty($evs[0]['citation_locator']) ? " ({$evs[0]['citation_locator']})" : '';
                    $verifiedSources[] = "{$srcTitle}{$srcLoc}";
                    $claimBullets[] = "Исторический факт [Подлинно]: {$cText} (Источник: {$srcTitle}{$srcLoc})";
                } else {
                    $hasAnyUnverifiedClaim = true;
                    $claimBullets[] = "Историческое утверждение [Требует проверки]: {$cText}";
                }
            }
        }

        $firstVerifiedSource = $verifiedSources[0] ?? null;
        $provTagContext = $hasAnyUnverifiedClaim
            ? '⚠ Содержит утверждения, требующие проверки первоисточников'
            : ($firstVerifiedSource ? "✓ Первоисточник: {$firstVerifiedSource}" : '✓ Первоисточник подтверждён');
        $provTagReflection = $hasAnyUnverifiedClaim
            ? '⚠ Сценарий содержит утверждения, требующие проверки первоисточников'
            : '✓ Все первоисточники верифицированы в системе «Школьные события»';

        // Stage bullets
        $stageBullets = [];
        $stageList = is_array($stages) ? $stages : iterator_to_array($stages);
        foreach (array_slice($stageList, 0, 4) as $st) {
            $stTitle = is_object($st) ? $st->title : ($st['title'] ?? 'Этап');
            $stDur = is_object($st) ? $st->duration_minutes : ($st['duration_minutes'] ?? 0);
            $stageBullets[] = "{$stTitle} — {$stDur} мин.";
        }
        if (empty($stageBullets)) {
            $stageBullets = [
                '1. Вводно-мотивационный этап (5 мин.)',
                '2. Основной содержательный этап (25 мин.)',
                '3. Практическая интерактивная часть (10 мин.)',
                '4. Подведение итогов и рефлексия (5 мин.)',
            ];
        }

        // Role & activity bullets
        $actBullets = [];
        $roles = $payload['roles'] ?? [];
        if (! empty($roles) && is_array($roles)) {
            foreach (array_slice($roles, 0, 3) as $r) {
                if (is_array($r)) {
                    $rTitle = $r['role_name'] ?? ($r['title'] ?? ($r['name'] ?? 'Участник'));
                    $rDesc = $r['description'] ?? '';
                    $actBullets[] = "Роль: {$rTitle} — {$rDesc}";
                }
            }
        }
        $activities = $payload['activities'] ?? [];
        if (! empty($activities) && is_array($activities)) {
            foreach (array_slice($activities, 0, 2) as $act) {
                if (is_array($act)) {
                    $actTitle = $act['title'] ?? ($act['name'] ?? 'Активность');
                    $actBullets[] = "Задание: {$actTitle}";
                }
            }
        }
        if (empty($actBullets)) {
            $actBullets = [
                'Формат взаимодействия: командная работа и групповое исследование',
                'Распределение обязанностей: аналитики, спикеры, хронометристы',
                'Правила: взаимное уважение, аргументированность, опора на факты',
            ];
        }

        // Expected outcomes
        $outcomes = $revision->expected_outcomes ?? ($payload['expected_outcomes'] ?? []);
        $outcomeBullets = [];
        if (! empty($outcomes['knowledge']) && is_array($outcomes['knowledge'])) {
            $outcomeBullets[] = 'Что нового узнали: ' . implode('; ', array_slice($outcomes['knowledge'], 0, 2));
        }
        $skillItems = $outcomes['skills'] ?? ($outcomes['action'] ?? []);
        if (! empty($skillItems) && is_array($skillItems)) {
            $outcomeBullets[] = 'Умения и навыки: ' . implode('; ', array_slice($skillItems, 0, 2));
        }
        $valItems = $outcomes['values'] ?? ($outcomes['attitude'] ?? []);
        if (! empty($valItems) && is_array($valItems)) {
            $outcomeBullets[] = 'Ценностный вывод: ' . implode('; ', array_slice($valItems, 0, 2));
        }
        if (empty($outcomeBullets)) {
            $outcomeBullets = [
                'Осмысление исторического значения событий и традиций',
                'Формирование личного отношения и гражданской позиции',
                'Вопросы для самооценки: что было самым интересным? какой главный вывод?',
            ];
        }

        // Candidate complementary slides
        $candIntro = [
            'id' => 'intro',
            'title' => $scenario->title,
            'subtitle' => "Тема: {$theme} | Продолжительность: {$duration} мин. | {$schoolName}",
            'bullets' => [
                'Формат мероприятия: ' . ($revision->format['type'] ?? 'Классный час'),
                'Целевая аудитория: ' . ($revision->target_audience['age_group'] ?? '5–9 классы'),
                'Ключевая цель: ' . $goal,
            ],
            'provenance_tag' => null,
            'speaker_notes' => "Вступительное слово педагога: озвучить тему «{$theme}», настроить учащихся на активную работу, огласить регламент проведения.",
        ];

        $candCulture = [
            'id' => 'culture',
            'title' => 'Культурно-исторический контекст',
            'subtitle' => $hasAnyUnverifiedClaim ? 'Исторические свидетельства (требуется проверка)' : 'Проверенные исторические первоисточники',
            'bullets' => ! empty($claimBullets) ? array_slice($claimBullets, 0, 3) : ['Ценностная направленность: ' . ($payload['value_direction'] ?? 'историческая память и культура')],
            'provenance_tag' => $provTagContext,
            'speaker_notes' => "Разъяснить ценностное значение обсуждаемого события. Обратить внимание учащихся на первоисточники и исторические свидетельства, выделить подтверждённые факты.",
        ];

        $candPlan = [
            'id' => 'plan',
            'title' => 'План и регламент проведения',
            'subtitle' => "Общий хронометраж: {$duration} минут",
            'bullets' => $stageBullets,
            'provenance_tag' => null,
            'speaker_notes' => "Ознакомить участников с регламентом и временными рамками каждого этапа. Следить за соблюдением тайминга.",
        ];

        $candRoles = [
            'id' => 'roles',
            'title' => 'Интерактивная деятельность и роли',
            'subtitle' => 'Организация совместной познавательной деятельности',
            'bullets' => array_slice($actBullets, 0, 4),
            'provenance_tag' => null,
            'speaker_notes' => "Разделить класс на малые группы, раздать карточки заданий, контролировать вовлеченность всех учащихся и соблюдение правил диалога.",
        ];

        $candReflection = [
            'id' => 'reflection',
            'title' => 'Рефлексия и ценностные итоги',
            'subtitle' => 'Подведение итогов мероприятия и выводы',
            'bullets' => array_merge($outcomeBullets, [
                'Итоговый вопрос: как традиции прошлого объединяют нас сегодня?',
            ]),
            'provenance_tag' => $provTagReflection,
            'speaker_notes' => "Провести финальный круг обсуждения. Дать возможность высказаться представителям каждой группы. Завершить мероприятие позитивным ценностным выводом.",
        ];

        // Case 1: If raw slides exist and have at least 5 slides, take the first 5 (documented selection policy)
        if (! empty($rawSlides) && is_array($rawSlides) && count($rawSlides) >= 5) {
            $result = [];
            foreach (array_slice($rawSlides, 0, 5) as $idx => $s) {
                $bullets = [];
                if (! empty($s['content'])) {
                    $bullets = is_array($s['content']) ? $s['content'] : [(string) $s['content']];
                } elseif (! empty($s['bullet_points'])) {
                    $bullets = is_array($s['bullet_points']) ? $s['bullet_points'] : [(string) $s['bullet_points']];
                }

                $provTag = null;
                if (! empty($s['cultural_claim_ids']) || ! empty($s['claim_reference'])) {
                    $provTag = $hasAnyUnverifiedClaim
                        ? '⚠ Требует проверки первоисточников'
                        : '✓ Подтверждено проверенным первоисточником';
                }

                $notes = (string) ($s['speaker_notes'] ?? ($s['teacher_notes'] ?? 'Заметки педагога к слайду.'));

                $result[] = [
                    'title' => (string) ($s['title'] ?? "Слайд " . ($idx + 1)),
                    'subtitle' => (string) ($s['subtitle'] ?? ($s['visual_recommendations'] ?? $scenario->title)),
                    'bullets' => array_values(array_map('strval', $bullets)),
                    'provenance_tag' => $provTag,
                    'speaker_notes' => $notes,
                ];
            }

            return $result;
        }

        // Case 2: If raw slides exist (1 to 4 slides), preserve ALL of them and supplement up to 5 from pedagogical modules
        if (! empty($rawSlides) && is_array($rawSlides) && count($rawSlides) > 0) {
            $result = [];
            $usedTitles = [];

            foreach ($rawSlides as $idx => $s) {
                $bullets = [];
                if (! empty($s['content'])) {
                    $bullets = is_array($s['content']) ? $s['content'] : [(string) $s['content']];
                } elseif (! empty($s['bullet_points'])) {
                    $bullets = is_array($s['bullet_points']) ? $s['bullet_points'] : [(string) $s['bullet_points']];
                }

                $provTag = null;
                if (! empty($s['cultural_claim_ids']) || ! empty($s['claim_reference'])) {
                    $provTag = $hasAnyUnverifiedClaim
                        ? '⚠ Требует проверки первоисточников'
                        : '✓ Подтверждено проверенным первоисточником';
                }

                $sTitle = (string) ($s['title'] ?? "Слайд " . ($idx + 1));
                $notes = (string) ($s['speaker_notes'] ?? ($s['teacher_notes'] ?? 'Заметки педагога к слайду.'));

                $result[] = [
                    'title' => $sTitle,
                    'subtitle' => (string) ($s['subtitle'] ?? ($s['visual_recommendations'] ?? $scenario->title)),
                    'bullets' => array_values(array_map('strval', $bullets)),
                    'provenance_tag' => $provTag,
                    'speaker_notes' => $notes,
                ];
                $usedTitles[] = mb_strtolower($sTitle);
            }

            // Complement up to 5 from candidates
            $pool = [$candCulture, $candPlan, $candRoles, $candReflection, $candIntro];
            foreach ($pool as $cand) {
                if (count($result) >= 5) {
                    break;
                }
                // Avoid duplicating topics already covered by raw slides
                $candTitleLower = mb_strtolower($cand['title']);
                $alreadyHasSimilar = false;
                foreach ($usedTitles as $ut) {
                    if (str_contains($ut, 'введение') && str_contains($candTitleLower, 'введение')) {
                        $alreadyHasSimilar = true;
                        break;
                    }
                    if (str_contains($ut, 'план') && str_contains($candTitleLower, 'план')) {
                        $alreadyHasSimilar = true;
                        break;
                    }
                    if (str_contains($ut, 'рефлекс') && str_contains($candTitleLower, 'рефлекс')) {
                        $alreadyHasSimilar = true;
                        break;
                    }
                }

                if (! $alreadyHasSimilar) {
                    $result[] = [
                        'title' => $cand['title'],
                        'subtitle' => $cand['subtitle'],
                        'bullets' => $cand['bullets'],
                        'provenance_tag' => $cand['provenance_tag'],
                        'speaker_notes' => $cand['speaker_notes'],
                    ];
                    $usedTitles[] = $candTitleLower;
                }
            }

            // If still less than 5, fill from remaining pool items
            foreach ($pool as $cand) {
                if (count($result) >= 5) {
                    break;
                }
                $candTitleLower = mb_strtolower($cand['title']);
                if (! in_array($candTitleLower, $usedTitles, true)) {
                    $result[] = [
                        'title' => $cand['title'],
                        'subtitle' => $cand['subtitle'],
                        'bullets' => $cand['bullets'],
                        'provenance_tag' => $cand['provenance_tag'],
                        'speaker_notes' => $cand['speaker_notes'],
                    ];
                    $usedTitles[] = $candTitleLower;
                }
            }

            return array_slice($result, 0, 5);
        }

        // Case 3: Zero raw slides -> return canonical 5-slide pedagogical sequence
        return [
            [
                'title' => $candIntro['title'],
                'subtitle' => $candIntro['subtitle'],
                'bullets' => $candIntro['bullets'],
                'provenance_tag' => $candIntro['provenance_tag'],
                'speaker_notes' => $candIntro['speaker_notes'],
            ],
            [
                'title' => $candCulture['title'],
                'subtitle' => $candCulture['subtitle'],
                'bullets' => $candCulture['bullets'],
                'provenance_tag' => $candCulture['provenance_tag'],
                'speaker_notes' => $candCulture['speaker_notes'],
            ],
            [
                'title' => $candPlan['title'],
                'subtitle' => $candPlan['subtitle'],
                'bullets' => $candPlan['bullets'],
                'provenance_tag' => $candPlan['provenance_tag'],
                'speaker_notes' => $candPlan['speaker_notes'],
            ],
            [
                'title' => $candRoles['title'],
                'subtitle' => $candRoles['subtitle'],
                'bullets' => $candRoles['bullets'],
                'provenance_tag' => $candRoles['provenance_tag'],
                'speaker_notes' => $candRoles['speaker_notes'],
            ],
            [
                'title' => $candReflection['title'],
                'subtitle' => $candReflection['subtitle'],
                'bullets' => $candReflection['bullets'],
                'provenance_tag' => $candReflection['provenance_tag'],
                'speaker_notes' => $candReflection['speaker_notes'],
            ],
        ];
    }

    /**
     * Build visually distinct and cleanly aligned slide in 16:9 ratio.
     *
     * @param array{
     *     title: string,
     *     subtitle: string,
     *     bullets: list<string>,
     *     provenance_tag: ?string,
     *     speaker_notes: string
     * } $spec
     */
    protected function buildSlide(Slide $slide, array $spec, int $slideNumber): void
    {
        // 1. Slide Title Box
        $titleShape = $slide->createRichTextShape();
        $titleShape->setHeight(70)->setWidth(860)->setOffsetX(50)->setOffsetY(40);
        $titleParagraph = $titleShape->createParagraph();
        $titleRun = $titleParagraph->createTextRun($spec['title']);
        $titleRun->getFont()->setName('Calibri')->setSize(24)->setBold(true)->setColor(new Color('FF1A365D'));

        // 2. Subtitle Box
        if (! empty($spec['subtitle'])) {
            $subShape = $slide->createRichTextShape();
            $subShape->setHeight(35)->setWidth(860)->setOffsetX(50)->setOffsetY(115);
            $subParagraph = $subShape->createParagraph();
            $subRun = $subParagraph->createTextRun($spec['subtitle']);
            $subRun->getFont()->setName('Calibri')->setSize(13)->setItalic(true)->setColor(new Color('FF4A5568'));
        }

        // 3. Main Content Box (Bullet Points)
        $contentShape = $slide->createRichTextShape();
        $contentShape->setHeight(300)->setWidth(860)->setOffsetX(50)->setOffsetY(160);

        foreach ($spec['bullets'] as $bullet) {
            $bulletParagraph = $contentShape->createParagraph();
            $bulletParagraph->getBulletStyle()->setBulletType(\PhpOffice\PhpPresentation\Style\Bullet::TYPE_BULLET);
            $bulletRun = $bulletParagraph->createTextRun(" {$bullet}");
            $bulletRun->getFont()->setName('Calibri')->setSize(16)->setColor(new Color('FF2D3748'));
        }

        // 4. Provenance Tag / Badge (Footer Left)
        if (! empty($spec['provenance_tag'])) {
            $tagShape = $slide->createRichTextShape();
            $tagShape->setHeight(30)->setWidth(600)->setOffsetX(50)->setOffsetY(480);
            $tagParagraph = $tagShape->createParagraph();
            $tagRun = $tagParagraph->createTextRun($spec['provenance_tag']);
            $tagRun->getFont()->setName('Calibri')->setSize(11)->setBold(true)->setColor(new Color('FF2C5282'));
        }

        // 5. Slide Number (Footer Right)
        $numShape = $slide->createRichTextShape();
        $numShape->setHeight(30)->setWidth(150)->setOffsetX(760)->setOffsetY(480);
        $numParagraph = $numShape->createParagraph();
        $numParagraph->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $numRun = $numParagraph->createTextRun("Слайд {$slideNumber} / 5");
        $numRun->getFont()->setName('Calibri')->setSize(11)->setColor(new Color('FFA0AEC0'));

        // 6. Speaker Notes for the Teacher
        if (! empty($spec['speaker_notes'])) {
            $note = $slide->getNote();
            $noteShape = $note->createRichTextShape();
            $noteShape->setHeight(400)->setWidth(600)->setOffsetX(40)->setOffsetY(40);
            $noteParagraph = $noteShape->createParagraph();
            $noteRun = $noteParagraph->createTextRun("Методические заметки для педагога к слайду {$slideNumber}:\n" . $spec['speaker_notes']);
            $noteRun->getFont()->setName('Calibri')->setSize(12)->setColor(new Color('FF1A202C'));
        }
    }
}
