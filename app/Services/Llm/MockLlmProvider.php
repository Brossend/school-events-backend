<?php

declare(strict_types=1);

namespace App\Services\Llm;

use App\Models\ScenarioRequest;

class MockLlmProvider implements VariantLlmProviderInterface
{
    public function generateVariant(ScenarioRequest $request, string $mode): array
    {
        return match ($mode) {
            'universal' => $this->generateUniversal($request),
            'personalized' => $this->generatePersonalized($request),
            'with_cultural_context' => $this->generateWithCulturalContext($request),
            'without_cultural_context' => $this->generateWithoutCulturalContext($request),
            default => throw new LlmProviderException('LLM_CONFIGURATION_ERROR', 'Неизвестный режим генерации.'),
        };
    }

    public const PROVIDER_NAME = 'mock';

    public const MODEL_IDENTIFIER = 'mock-llm-pedagogical-v1.0';

    public const PROMPT_VERSION = 'v1.3.0';

    public const SCHEMA_VERSION = '1.0.0';

    /**
     * Value direction to mechanism mapping according to TOR and JSON Schema.
     */
    protected const MECHANISMS = [
        'family' => [
            'type' => 'family_investigation',
            'type_ru' => 'Семейное исследование',
            'desc_template' => 'Совместное исследование семейных историй, реликвий и династических традиций участников мероприятия.',
            'activity_title' => 'Семейная архивная экспедиция',
            'activity_desc' => 'Учащиеся вместе с родителями исследуют исторические материалы и составляют древо семейных традиций.',
        ],
        'mutual_aid' => [
            'type' => 'team_mutual_support_task',
            'type_ru' => 'Командная взаимовыручка',
            'desc_template' => 'Командное решение практической задачи, где успех зависит от поддержки и взаимовыручки каждого участника.',
            'activity_title' => 'Маршрут командной взаимовыручки',
            'activity_desc' => 'Команды проходят полосу интеллектуальных испытаний, где результат фиксируется по завершению задания последним участником.',
        ],
        'historical_memory' => [
            'type' => 'historical_source_analysis',
            'type_ru' => 'Анализ исторических источников',
            'desc_template' => 'Критический анализ исторических документов, воспоминаний и свидетельств эпохи для формирования исторической памяти.',
            'activity_title' => 'Лаборатория анализа исторических источников',
            'activity_desc' => 'Групповая работа с архивными свидетельствами и первоисточниками с формулированием обоснованных выводов.',
        ],
        'labor_and_creation' => [
            'type' => 'collaborative_creative_product',
            'type_ru' => 'Коллективный созидательный продукт',
            'desc_template' => 'Создание коллективного общественно-полезного или творческого продукта через разделение трудовых ролей.',
            'activity_title' => 'Творческая артель созидательного труда',
            'activity_desc' => 'Участники создают совместный методический альбом или макет, распределяя роли исследователей, редакторов и мастеров.',
        ],
        'cultural_diversity' => [
            'type' => 'comparative_cultural_study',
            'type_ru' => 'Сравнительное исследование культурных традиций',
            'desc_template' => 'Сравнительное исследование культурных традиций, промыслов и обычаев народов региона.',
            'activity_title' => 'Сравнительная мастерская культурных традиций',
            'activity_desc' => 'Учащиеся сопоставляют традиционные элементы быта и ремесел, выявляя общечеловеческие объединяющие ценности.',
        ],
        'civic_responsibility' => [
            'type' => 'civic_community_action',
            'type_ru' => 'Школьная гражданская инициатива',
            'desc_template' => 'Проектирование и реализация социально-ориентированной гражданской инициативы на благо школы или города.',
            'activity_title' => 'Гражданский форум школьных инициатив',
            'activity_desc' => 'Разработка и презентация командных социальных проектов, направленных на решение конкретной проблемы школьного сообщества.',
        ],
    ];

    public const DIRECTION_TITLES = [
        'historical_memory' => 'Историческая память и преемственность поколений',
        'mutual_aid' => 'Взаимопомощь и солидарность',
        'labor_and_creation' => 'Созидательный труд и творчество',
        'cultural_diversity' => 'Культурное разнообразие и единство',
        'civic_responsibility' => 'Гражданственность и патриотизм',
        'family' => 'Крепкая семья и преемственность поколений',
    ];

    public function generate(ScenarioRequest $request): array
    {
        $startTime = microtime(true);

        $context = $request->context_snapshot;
        $occasionTitle = $context['occasion']['title'] ?? ($request->custom_event_title ?? 'Тематическое школьное мероприятие');
        $direction = $request->value_direction;
        $directionTitle = self::DIRECTION_TITLES[$direction] ?? 'Традиционные ценности';
        $mechanismInfo = self::MECHANISMS[$direction] ?? self::MECHANISMS['civic_responsibility'];

        $duration = $request->duration_minutes;
        $grades = $request->target_grades ?? [5, 6];
        $participants = $request->participants_count;
        $resources = $request->available_resources ?? [];
        $scale = $request->scale;

        // Age group mapping
        $minGrade = min($grades);
        $maxGrade = max($grades);
        if ($maxGrade <= 4) {
            $ageGroup = 'primary_1_4';
        } elseif ($minGrade >= 5 && $maxGrade <= 9) {
            $ageGroup = 'basic_5_9';
        } elseif ($minGrade >= 10 && $maxGrade <= 11) {
            $ageGroup = 'senior_10_11';
        } else {
            $ageGroup = 'mixed';
        }

        // Cultural claims extraction
        $culturalClaims = [];
        $snapshotClaims = $context['cultural_provenance']['claims'] ?? [];
        foreach ($snapshotClaims as $idx => $sc) {
            $claimRef = [];
            foreach ($sc['evidence_records'] ?? [] as $ev) {
                if (! empty($ev['source_title']) && ! empty($ev['citation_locator'])) {
                    $ref = [
                        'source_title' => $ev['source_title'],
                        'citation_locator' => $ev['citation_locator'],
                    ];
                    if (! empty($ev['author'])) {
                        $ref['author'] = $ev['author'];
                    } elseif (! empty($ev['verified_by'])) {
                        $ref['author'] = $ev['verified_by'];
                    }
                    if (! empty($ev['url'])) {
                        $ref['url_or_archive_ref'] = $ev['url'];
                    }
                    $claimRef[] = $ref;
                }
            }

            if (empty($claimRef)) {
                continue;
            }

            $claimId = ! empty($sc['id'])
                ? ('claim_'.str_replace('-', '', (string) $sc['id']))
                : ('claim_'.substr(md5($sc['claim_text'] ?? ('claim_'.$idx)), 0, 10));

            $culturalClaims[] = [
                'claim_id' => $claimId,
                'claim_text' => $sc['claim_text'],
                'culture_name' => $sc['culture_name'],
                'claimed_origin' => 'Культурно-историческая традиция региона',
                'claimed_meaning' => 'Ценностный ориентир преемственности поколений',
                'source_references' => $claimRef,
            ];
        }

        $participantsMin = max(5, min(500, (int) round($participants * 0.8)));
        $participantsMax = min(500, max($participantsMin, (int) round($participants * 1.2)));
        $resourcesText = ! empty($resources) ? implode(', ', $resources) : 'Базовые канцелярские принадлежности, проектор, интерактивная доска';

        // Scale-specific structure building
        if ($scale === 'all_school') {
            $scaleData = $this->buildAllSchoolStructure($occasionTitle, $directionTitle, $mechanismInfo, $duration, $resourcesText);
        } elseif ($scale === 'family_community') {
            $scaleData = $this->buildFamilyCommunityStructure($occasionTitle, $directionTitle, $mechanismInfo, $duration, $resourcesText);
        } else {
            $scaleData = $this->buildClassLevelStructure($occasionTitle, $directionTitle, $mechanismInfo, $duration, $resourcesText);
        }

        $outputPayload = [
            'concept' => $scaleData['concept'],
            'pedagogical_goal' => $scaleData['pedagogical_goal'],
            'value_direction' => $direction,
            'pedagogical_mechanism' => [
                'mechanism_type' => $mechanismInfo['type'],
                'description' => $mechanismInfo['desc_template'],
                'target_activity_title' => $scaleData['activities'][0]['title'],
            ],
            'pedagogical_tasks' => $scaleData['pedagogical_tasks'],
            'expected_outcomes' => $scaleData['expected_outcomes'],
            'target_audience' => [
                'age_group' => $ageGroup,
                'target_grades' => array_values($grades),
                'participants_count_min' => $participantsMin,
                'participants_count_max' => $participantsMax,
                'family_involvement' => ($scale === 'family_community') ? true : (bool) $request->family_involvement,
            ],
            'format' => [
                'format_type' => $request->format_type,
                'scale' => $scale,
                'total_duration_minutes' => $duration,
            ],
            'stages' => $scaleData['stages'],
            'roles' => $scaleData['roles'],
            'activities' => $scaleData['activities'],
            'materials' => $scaleData['materials'],
            'props' => $scaleData['props'],
            'preparation_checklist' => $scaleData['preparation_checklist'],
            'teacher_instructions' => $scaleData['teacher_instructions'],
            'cultural_claims' => $culturalClaims,
        ];

        $latencyMs = (int) round((microtime(true) - $startTime) * 1000) + 120;

        return [
            'provider_name' => self::PROVIDER_NAME,
            'model_identifier' => self::MODEL_IDENTIFIER,
            'prompt_version' => self::PROMPT_VERSION,
            'schema_version' => self::SCHEMA_VERSION,
            'latency_ms' => $latencyMs,
            'prompt_tokens' => 480,
            'completion_tokens' => 1420,
            'sanitized_prompt' => 'Запрос на генерацию сценария: '.$occasionTitle.', масштаб: '.$scale.', формат: '.$request->format_type.', направление: '.$direction,
            'output_payload' => $outputPayload,
        ];
    }

    /**
     * Build Class-level structure (20-45 minutes classroom lesson/workshop).
     */
    protected function buildClassLevelStructure(
        string $occasionTitle,
        string $directionTitle,
        array $mechanismInfo,
        int $duration,
        string $resourcesText
    ): array {
        // Partition into 3 stages: sum == duration, each between 5 and 120
        if ($duration > 120) {
            // For long durations (e.g. 240m intensive), split so no stage exceeds 120
            $stage1 = (int) round(($duration - 120) / 2);
            $stage3 = ($duration - 120) - $stage1;
            $stage2 = 120;
        } else {
            $stage1 = max(5, min(30, (int) round($duration * 0.20)));
            $stage3 = max(5, min(30, (int) round($duration * 0.20)));
            $stage2 = $duration - $stage1 - $stage3;
        }

        $stages = [
            [
                'id' => 'stage_intro',
                'order_index' => 1,
                'title' => 'Вводная мотивационная часть: «Введение в тему: '.$occasionTitle.'»',
                'stage_type' => 'motivation_introduction',
                'duration_minutes' => $stage1,
                'teacher_actions' => 'Педагог приветствует класс, формулирует проблемный вопрос по теме «'.$occasionTitle.'» и настраивает учащихся на диалог.',
                'student_actions' => 'Учащиеся включаются в обсуждение, формулируют личные ожидания и актуализируют имеющийся опыт.',
                'dialogue_prompts' => [
                    'Почему для каждого из нас важно обратиться к теме: '.$occasionTitle.'?',
                    'С какими повседневными поступками у вас ассоциируется направление «'.$directionTitle.'»?',
                ],
                'teacher_tips' => 'Создать доверительную атмосферу, стимулирующую искренние высказывания.',
                'transition_text' => 'А теперь переходим к основной практической работе в группах.',
            ],
            [
                'id' => 'stage_main_activity',
                'order_index' => 2,
                'title' => 'Практический деятельностный этап: «'.$mechanismInfo['activity_title'].'»',
                'stage_type' => 'main_interactive_part',
                'duration_minutes' => $stage2,
                'teacher_actions' => 'Организует групповую работу по механизму «'.($mechanismInfo['type_ru'] ?? $mechanismInfo['activity_title']).'», распределяет раздаточный материал и модерирует выполнение заданий.',
                'student_actions' => 'Участники в малых группах исследуют кейсы, готовят групповые решения и оформляют маршрутный лист.',
                'dialogue_prompts' => [
                    'К какому общему выводу пришла ваша группа при выполнении задания?',
                    'В чем проявилась взаимная поддержка участников вашей команды?',
                ],
                'teacher_tips' => 'Контролировать соблюдение регламента и помогать группам в распределении обязанностей.',
                'transition_text' => 'Время подвести итоги практической работы и представить результаты классу.',
            ],
            [
                'id' => 'stage_reflection',
                'order_index' => 3,
                'title' => 'Итоговая рефлексия и ценностный вывод',
                'stage_type' => 'reflection_summary',
                'duration_minutes' => $stage3,
                'teacher_actions' => 'Организует рефлексивный смысловой круг, обобщает результаты командной работы и фиксирует ценностные ориентиры.',
                'student_actions' => 'Спикеры групп озвучивают итоговые выводы, участники оценивают совместную работу и личный вклад.',
                'dialogue_prompts' => [
                    'Что самого важного для себя вы вынесли из сегодняшнего обсуждения?',
                    'Как этот опыт поможет нам в жизни нашего классного коллектива?',
                ],
                'teacher_tips' => 'Дать возможность высказаться каждому желающему, избегая шаблонных оценок.',
                'transition_text' => 'Благодарю всех за активную работу, взаимное уважение и содержательный диалог!',
            ],
        ];

        return [
            'concept' => 'Классный воспитательный сценарий: «'.$occasionTitle.'». Направлен на погружение учащихся одного классного коллектива в ценностное направление «'.$directionTitle.'» через интерактивный групповой практикум.',
            'pedagogical_goal' => 'Формирование ценностных ориентиров и культуры сотрудничества в классном коллективе в контексте «'.$occasionTitle.'».',
            'pedagogical_tasks' => [
                'personal' => [
                    'Осознание личной причастности к ценностям направления «'.$directionTitle.'».',
                    'Развитие чувства эмпатии и уважения к одноклассникам.',
                ],
                'meta_subject' => [
                    'Развитие навыков аргументации своей точки зрения в малых группах.',
                    'Умение приходить к согласию при решении общей практической задачи.',
                ],
                'subject_specific' => [
                    'Освоение понятийного содержания и фактов события «'.$occasionTitle.'».',
                    'Применение полученных сведений для анализа практической ситуации.',
                ],
            ],
            'expected_outcomes' => [
                'knowledge' => [
                    'Понимание смысловых и исторических оснований события «'.$occasionTitle.'».',
                ],
                'skills' => [
                    'Навык слаженной командной работы и распределения ролей в малой группе.',
                ],
                'values' => [
                    'Уважительное отношение к традициям и взаимная поддержка в коллективе.',
                ],
            ],
            'stages' => $stages,
            'roles' => [
                [
                    'role_name' => 'Хранитель традиций',
                    'description' => 'Учащийся, отслеживающий соблюдение регламента и правил диалога внутри группы.',
                    'target_participants' => 'students',
                    'group_size' => 1,
                    'specific_tasks' => [
                        'Следить за поочередным высказыванием каждого члена группы.',
                        'Контролировать время выполнения этапов задания.',
                    ],
                ],
                [
                    'role_name' => 'Презентатор идей',
                    'description' => 'Учащийся, формулирующий позицию группы и озвучивающий итоговый вывод классу.',
                    'target_participants' => 'students',
                    'group_size' => 1,
                    'specific_tasks' => [
                        'Обобщить предложения участников на общем бланке.',
                        'Выступить с кратким докладом перед классом.',
                    ],
                ],
            ],
            'activities' => [
                [
                    'title' => $mechanismInfo['activity_title'],
                    'activity_type' => 'group_research',
                    'target_stage_id' => 'stage_main_activity',
                    'rules_and_instructions' => $mechanismInfo['activity_desc'].' Каждая малая группа анализирует предложенный кейс и формирует презентационный лист.',
                    'scoring_or_evaluation' => 'Критериальная взаимооценка по параметрам: содержательность, аргументированность и вовлеченность всех участников.',
                ],
            ],
            'materials' => [
                'presentation_slides' => [
                    [
                        'slide_number' => 1,
                        'title' => $occasionTitle.': Введение в тему',
                        'bullet_points' => [
                            'Ключевые ценности мероприятия',
                            'Цели и задачи работы класса',
                        ],
                        'visual_recommendations' => 'Тематические архивные фотографии или инфографика.',
                        'speaker_notes' => 'Обратить внимание на актуальность темы для классного сообщества.',
                    ],
                    [
                        'slide_number' => 2,
                        'title' => 'Практикум: '.$mechanismInfo['activity_title'],
                        'bullet_points' => [
                            'Правила командного взаимодействия',
                            'Регламент презентации решений',
                        ],
                        'visual_recommendations' => 'Схема маршрутного листа и таймер обратного отсчета.',
                        'speaker_notes' => 'Напомнить о важности участия каждого ученика.',
                    ],
                ],
                'printable_cards' => [
                    [
                        'card_type' => 'lesson_card',
                        'title' => 'Карточка кейса: «'.$occasionTitle.'»',
                        'front_text' => 'Описание проблемной ситуации для командного анализа.',
                        'back_text' => 'Контрольные вопросы для формулирования командного решения.',
                        'quantity_per_group' => 4,
                    ],
                ],
                'handouts' => [
                    [
                        'title' => 'Маршрутный лист малой группы',
                        'instructions' => 'Заполните ключевые блоки в процессе групповой работы.',
                        'template_content' => 'Поля: Проблема / Идеи / Выбранное решение / Аргументы / Роль каждого.',
                    ],
                ],
            ],
            'props' => [
                [
                    'item_name' => 'Комплект раздаточных материалов и ватманов',
                    'quantity' => 'По количеству малых групп',
                    'is_mandatory' => true,
                    'alternative_if_missing' => 'Использование стандартных листов формата А4 и маркеров.',
                ],
                [
                    'item_name' => 'Материалы: '.$resourcesText,
                    'quantity' => '1 комплект на класс',
                    'is_mandatory' => false,
                    'alternative_if_missing' => 'Цифровой показ на проекторе.',
                ],
            ],
            'preparation_checklist' => [
                [
                    'timeline_phase' => 'two_weeks_before',
                    'task_description' => 'Утвердить концепцию классного часа и подготовить список необходимых материалов: '.$resourcesText,
                    'responsible_person' => 'Классный руководитель',
                ],
                [
                    'timeline_phase' => 'day_before',
                    'task_description' => 'Распечатать карточки заданий и маршрутные листы для малых групп.',
                    'responsible_person' => 'Классный руководитель',
                ],
            ],
            'teacher_instructions' => [
                'general_safety_rules' => [
                    'Обеспечить безопасную расстановку парт для групповой работы без препятствий на путях эвакуации.',
                    'Контролировать соблюдение дисциплины и взаимоуважения во время эмоциональных обсуждений.',
                ],
                'methodological_recommendations' => [
                    'Не подсказывать готовые ответы, стимулируя самостоятельный поиск решения в группах.',
                    'При затруднении в диалоге задавать наводящие открытые вопросы из сценария.',
                ],
                'adaptation_for_disabilities_or_small_groups' => 'При наличии учащихся с ОВЗ предусмотреть адаптивные роли (хранитель идей, спикер) и увеличить размер шрифта раздаточных материалов.',
            ],
        ];
    }

    /**
     * Build All-School structure (60-180 minutes school-wide festival/quest across grades).
     */
    protected function buildAllSchoolStructure(
        string $occasionTitle,
        string $directionTitle,
        array $mechanismInfo,
        int $duration,
        string $resourcesText
    ): array {
        // Partition into 4 stages: sum == duration, each between 10 and 120
        $stage1 = max(10, min(40, (int) round($duration * 0.15)));
        $stage2 = max(15, min(90, (int) round($duration * 0.40)));
        $stage3 = max(15, min(80, (int) round($duration * 0.30)));
        $stage4 = $duration - $stage1 - $stage2 - $stage3;

        $stages = [
            [
                'id' => 'stage_plenary_opening',
                'order_index' => 1,
                'title' => 'Общешкольная торжественная линейка и открытие фестиваля: «'.$occasionTitle.'»',
                'stage_type' => 'motivation_introduction',
                'duration_minutes' => $stage1,
                'teacher_actions' => 'Ведущий открывает общешкольный сбор, звучит гимн, оглашается цель дня и регламент маршрутного движения потоков.',
                'student_actions' => 'Команды классов выстраиваются с флагами и эмблемами, слушают напутствие и получают маршрутные карты станций.',
                'dialogue_prompts' => [
                    'Какое значение событие «'.$occasionTitle.'» имеет для истории и традиций нашей школы?',
                    'С какими целями команды вступают в общешкольный фестиваль?',
                ],
                'teacher_tips' => 'Обеспечить четкую координацию потоков учащихся и соблюдение торжественного регламента.',
                'transition_text' => 'Команды выдвигаются по индивидуальным маршрутам на тематические станции.',
            ],
            [
                'id' => 'stage_station_routing',
                'order_index' => 2,
                'title' => 'Работа тематических станций: «'.$mechanismInfo['activity_title'].'»',
                'stage_type' => 'main_interactive_part',
                'duration_minutes' => $stage2,
                'teacher_actions' => 'Модераторы станций организуют параллельную работу потоков, фиксируют баллы и курируют выполнение испытаний.',
                'student_actions' => 'Сборные отряды проходят серию интеллектуальных, спортивных и творческих локаций по механизму «'.($mechanismInfo['type_ru'] ?? $mechanismInfo['activity_title']).'».',
                'dialogue_prompts' => [
                    'Какая стратегия помогла команде преодолеть сложный этап на станции?',
                    'Как распределялись задачи между младшими и старшими участниками команды?',
                ],
                'teacher_tips' => 'Следить за таймингом переходов между станциями и безопасностью перемещения по зданию школы.',
                'transition_text' => 'Маршрутный этап завершен. Все команды собираются в актовом зале для презентации итогов.',
            ],
            [
                'id' => 'stage_creative_assembly',
                'order_index' => 3,
                'title' => 'Общешкольная ассамблея презентации командных продуктов',
                'stage_type' => 'practical_task',
                'duration_minutes' => $stage3,
                'teacher_actions' => 'Ведущий координирует презентацию результатов станций, жюри обобщает набранные командами баллы.',
                'student_actions' => 'Капитаны потоков представляют созданные на станциях творческие и социальные артефакты.',
                'dialogue_prompts' => [
                    'Какой вклад каждая станция внесла в общий общешкольный результат?',
                    'В чем проявилось единство школьного сообщества?',
                ],
                'teacher_tips' => 'Поддерживать позитивный настрой зала и равное внимание ко всем выступающим коллективам.',
                'transition_text' => 'Переходим к подведению общешкольных итогов и награждению участников.',
            ],
            [
                'id' => 'stage_closing_reflection',
                'order_index' => 4,
                'title' => 'Торжественный ценностный финал и общешкольная рефлексия',
                'stage_type' => 'reflection_summary',
                'duration_minutes' => $stage4,
                'teacher_actions' => 'Администрация школы подводит итоги фестиваля, вручает дипломы и формулирует общие воспитательные смыслы.',
                'student_actions' => 'Участники выражают взаимную признательность, исполняют общешкольную песню и делятся впечатлениями.',
                'dialogue_prompts' => [
                    'Какие школьные традиции укрепились благодаря сегодняшнему общему делу?',
                    'Какую пользу наше общешкольное единство принесет в будущем?',
                ],
                'teacher_tips' => 'Создать ощущение гордости за школу и сопричастности каждого к общему успеху.',
                'transition_text' => 'Общешкольное событие объявляется успешно завершенным!',
            ],
        ];

        return [
            'concept' => 'Общешкольный масштабный проект: «'.$occasionTitle.'». Объединяет разновозрастные отряды учащихся 1-11 классов, педагогов и администрацию вокруг ценностного направления «'.$directionTitle.'» в формате общешкольного фестиваля-квеста.',
            'pedagogical_goal' => 'Укрепление общешкольного единства, межвозрастного наставничества и сопричастности традициям образовательной организации.',
            'pedagogical_tasks' => [
                'personal' => [
                    'Формирование чувства гордости за свое образовательное учреждение.',
                    'Осознание роли каждого ученика в жизнедеятельности большой школьной семьи.',
                ],
                'meta_subject' => [
                    'Развитие навыков координации масштабных коллективных действий в потоках.',
                    'Освоение практик наставничества старших школьников над младшими классами.',
                ],
                'subject_specific' => [
                    'Глубокое изучение истории школы и контекста события «'.$occasionTitle.'».',
                    'Создание общешкольного социально-культурного продукта совместными силами.',
                ],
            ],
            'expected_outcomes' => [
                'knowledge' => [
                    'Знание общешкольных традиций, ценностей и значимых дат отечественной культуры.',
                ],
                'skills' => [
                    'Умение действовать в составе большой разновозрастной команды.',
                ],
                'values' => [
                    'Гражданственность, преемственность поколений и школьная солидарность.',
                ],
            ],
            'stages' => $stages,
            'roles' => [
                [
                    'role_name' => 'Координатор тематической станции',
                    'description' => 'Педагог-модератор или активист старших классов, управляющий локацией фестиваля.',
                    'target_participants' => 'moderators',
                    'group_size' => 1,
                    'specific_tasks' => [
                        'Организовать инструктаж прибывающих команд потока.',
                        'Оценивать выполнение заданий по шкале критериев в маршрутном листе.',
                    ],
                ],
                [
                    'role_name' => 'Капитан общешкольного отряда',
                    'description' => 'Лидер разновозрастной команды, направляющий группу по станциям школы.',
                    'target_participants' => 'students',
                    'group_size' => 1,
                    'specific_tasks' => [
                        'Вести команду по заданному маршруту без опозданий.',
                        'Помогать младшим школьникам включаться в командные испытания.',
                    ],
                ],
                [
                    'role_name' => 'Ведущий общешкольного форума',
                    'description' => 'Педагог-организатор, координирующий общую программу сбора и финала.',
                    'target_participants' => 'teachers',
                    'group_size' => 1,
                    'specific_tasks' => [
                        'Провести торжественную линейку открытия и финальную церемонию.',
                        'Координировать выступления капитанов отрядов в актовом зале.',
                    ],
                ],
                [
                    'role_name' => 'Медиа-летописец школы',
                    'description' => 'Учащийся школьного пресс-центра, обеспечивающий видео- и фотофиксацию.',
                    'target_participants' => 'students',
                    'group_size' => 2,
                    'specific_tasks' => [
                        'Вести фотохронику прохождения станций отрядами.',
                        'Подготовить экспресс-ролик для показа на церемонии закрытия.',
                    ],
                ],
            ],
            'activities' => [
                [
                    'title' => 'Общешкольный маршрутный квест: «'.$mechanismInfo['activity_title'].'»',
                    'activity_type' => 'station_quest_festival',
                    'target_stage_id' => 'stage_station_routing',
                    'rules_and_instructions' => 'Разновозрастные команды проходят по индивидуальному графику через серию станций, накапливая баллы за выполнение заданий по направлению «'.$directionTitle.'».',
                    'scoring_or_evaluation' => 'Балльная оценка по критериям: сплоченность разновозрастного отряда, креативность решения и скорость выполнения.',
                ],
                [
                    'title' => 'Итоговый смотр командных достижений',
                    'activity_type' => 'plenary_showcase',
                    'target_stage_id' => 'stage_creative_assembly',
                    'rules_and_instructions' => 'Каждая команда презентует изготовленный на станциях коллективный плакат или инсталляцию.',
                    'scoring_or_evaluation' => 'Общешкольное голосование жюри за лучшие номинации отрядов.',
                ],
            ],
            'materials' => [
                'presentation_slides' => [
                    [
                        'slide_number' => 1,
                        'title' => 'Общешкольный сбор: «'.$occasionTitle.'»',
                        'bullet_points' => [
                            'Миссия и ценности общешкольного дня',
                            'Маршруты и правила фестиваля',
                        ],
                        'visual_recommendations' => 'Карта школьного здания с указанием тематических станций.',
                        'speaker_notes' => 'Подчеркнуть важность взаимопомощи между старшими и младшими классами.',
                    ],
                    [
                        'slide_number' => 2,
                        'title' => 'Парад отрядов и презентация станций',
                        'bullet_points' => [
                            'Критерии прохождения станций',
                            'Регламент безопасности и тайминга',
                        ],
                        'visual_recommendations' => 'Эмблемы отрядов и табло подсчета очков.',
                        'speaker_notes' => 'Напомнить о культуре поведения в коридорах и на переходах.',
                    ],
                ],
                'printable_cards' => [
                    [
                        'card_type' => 'station_badge',
                        'title' => 'Маршрутная карта общешкольного отряда',
                        'front_text' => 'Список станций, порядок посещения и контрольное время прибытия.',
                        'back_text' => 'Подписи модераторов станций и набранные баллы отряда.',
                        'quantity_per_group' => 2,
                    ],
                ],
                'handouts' => [
                    [
                        'title' => 'Бюллетень модератора станции',
                        'instructions' => 'Фиксируйте время прибытия и баллы каждого отряда по шкале критериев.',
                        'template_content' => 'Графы: № отряда / Капитан / Балл за слаженность / Балл за задание / Роспись.',
                    ],
                ],
            ],
            'props' => [
                [
                    'item_name' => 'Звукоусилительная аппаратура и радиомикрофоны',
                    'quantity' => '1 комплект в актовый зал',
                    'is_mandatory' => true,
                    'alternative_if_missing' => 'Портативная акустическая колонка.',
                ],
                [
                    'item_name' => 'Оснащение станций и ресурсы: '.$resourcesText,
                    'quantity' => 'По числу станций',
                    'is_mandatory' => true,
                    'alternative_if_missing' => 'Бумажные бланки и стандартный инвентарь классов.',
                ],
            ],
            'preparation_checklist' => [
                [
                    'timeline_phase' => 'two_weeks_before',
                    'task_description' => 'Утвердить общешкольный регламент, согласовать план станций и ответственных педагогов: '.$resourcesText,
                    'responsible_person' => 'Заместитель директора по воспитательной работе',
                ],
                [
                    'timeline_phase' => 'three_days_before',
                    'task_description' => 'Провести инструктаж модераторов станций и сформировать списки разновозрастных отрядов.',
                    'responsible_person' => 'Педагог-организатор',
                ],
                [
                    'timeline_phase' => 'day_before',
                    'task_description' => 'Разместить указатели станций по зданию школы и подготовить маршрутные листы.',
                    'responsible_person' => 'Координатор школьного самоуправления',
                ],
            ],
            'teacher_instructions' => [
                'general_safety_rules' => [
                    'Обеспечить дежурство учителей на лестничных пролетах и рекреациях во время перемещения потоков.',
                    'Категорически исключить бег в коридорах и давку при входе на станции.',
                ],
                'methodological_recommendations' => [
                    'Стимулировать старшеклассников помогать младшим участникам формулировать ответы.',
                    'Поддерживать атмосферу здорового товарищеского соперничества без унижения проигравших.',
                ],
                'adaptation_for_disabilities_or_small_groups' => 'Для маломобильных школьников предусмотреть станции на первом этаже и адаптивные задания интеллектуального характера.',
            ],
        ];
    }

    /**
     * Build Family-Community structure (45-120 minutes family workshop & intergenerational dialogue).
     */
    protected function buildFamilyCommunityStructure(
        string $occasionTitle,
        string $directionTitle,
        array $mechanismInfo,
        int $duration,
        string $resourcesText
    ): array {
        // Partition into 4 stages: sum == duration, each between 10 and 120
        $stage1 = max(10, min(35, (int) round($duration * 0.20)));
        $stage2 = max(15, min(90, (int) round($duration * 0.35)));
        $stage3 = max(15, min(80, (int) round($duration * 0.30)));
        $stage4 = $duration - $stage1 - $stage2 - $stage3;

        $stages = [
            [
                'id' => 'stage_family_icebreaker',
                'order_index' => 1,
                'title' => 'Вводный семейный круг доверия: «Наш семейный очаг и тема: '.$occasionTitle.'»',
                'stage_type' => 'motivation_introduction',
                'duration_minutes' => $stage1,
                'teacher_actions' => 'Педагог приветствует семейные команды (дети, родители, бабушки и дедушки), создает теплую доверительную атмосферу и знакомит с темой.',
                'student_actions' => 'Дети и родители представляют свои семейные экипажи, делятся семейными девизами и формулируют ожидания.',
                'dialogue_prompts' => [
                    'Какие семейные традиции и воспоминания связаны в вашем роду с темой «'.$occasionTitle.'»?',
                    'Что ценного старшее поколение передает молодым для сохранения мира и лада в семье?',
                ],
                'teacher_tips' => 'Проявлять чуткость к разнообразию состава семей и избегать директивного тона.',
                'transition_text' => 'Переходим к практической совместной работе в семейных творческих лабораториях.',
            ],
            [
                'id' => 'stage_family_lab',
                'order_index' => 2,
                'title' => 'Семейная мастерская традиций: «'.$mechanismInfo['activity_title'].'»',
                'stage_type' => 'main_interactive_part',
                'duration_minutes' => $stage2,
                'teacher_actions' => 'Педагог фасилитирует диалог поколений, помогает семьям структурировать воспоминания и оформлять артефакты.',
                'student_actions' => 'Семьи совместно исследуют фотоархивы, составляют древо традиций и создают общий семейный герб или летопись.',
                'dialogue_prompts' => [
                    'Какую малоизвестную историю о своих предках дети открыли сегодня благодаря родителям?',
                    'В чем секрет стойкости и взаимовыручки вашей семьи в трудные времена?',
                ],
                'teacher_tips' => 'Поощрять искренний обмен эмоциями и совместное творчество детей и взрослых.',
                'transition_text' => 'Наши семейные летописи готовы. Пришло время поделиться открытиями с другими семьями.',
            ],
            [
                'id' => 'stage_intergenerational_dialogue',
                'order_index' => 3,
                'title' => 'Межпоколенческий круглый стол и презентация семейных реликвий',
                'stage_type' => 'practical_task',
                'duration_minutes' => $stage3,
                'teacher_actions' => 'Модерирует межсемейный обмен опытом, направляет внимание зала на объединяющие духовно-нравственные ориентиры.',
                'student_actions' => 'Представители семей презентуют созданные страницы семейной книги традиций и реликвии.',
                'dialogue_prompts' => [
                    'Какие общие ценности объединяют все представленные сегодня семейные истории?',
                    'Что из услышанного от других семей захотелось взять в свой дом?',
                ],
                'teacher_tips' => 'Обеспечить равное уважение ко всем семьям вне зависимости от артистизма выступления.',
                'transition_text' => 'Приглашаем всех на заключительную теплую рефлексию за семейным столом.',
            ],
            [
                'id' => 'stage_family_reflection',
                'order_index' => 4,
                'title' => 'Благодарственный финал, чаепитие и рефлексия поколений',
                'stage_type' => 'reflection_summary',
                'duration_minutes' => $stage4,
                'teacher_actions' => 'Обобщает ценностные выводы вечера, благодарит старшее поколение и вручает памятные свидетельства хранителей традиций.',
                'student_actions' => 'Дети дарят родителям изготовленные сувениры с благодарностью, участники делятся теплыми отзывами.',
                'dialogue_prompts' => [
                    'Что нового и дорогого в своих близких открыл каждый из нас сегодня?',
                    'Какую новую традицию мы договорились ввести в наш дом с сегодняшнего дня?',
                ],
                'teacher_tips' => 'Завершить встречу на высокой эмоциональной ноте взаимной благодарности и тепла.',
                'transition_text' => 'Пусть тепло нашей семейной встречи сохраняется в ваших домах каждый день!',
            ],
        ];

        return [
            'concept' => 'Семейно-общественный проект: «'.$occasionTitle.'». Направлен на диалог поколений, укрепление института семьи и сотворчество родителей и детей вокруг направления «'.$directionTitle.'».',
            'pedagogical_goal' => 'Укрепление детско-родительских отношений, сохранение семейной памяти и межпоколенческой преемственности ценностей.',
            'pedagogical_tasks' => [
                'personal' => [
                    'Осознание ценности своей семьи, корней и предков.',
                    'Укрепление эмоционального доверия и уважения между детьми и родителями.',
                ],
                'meta_subject' => [
                    'Развитие культуры межпоколенческого уважительного диалога.',
                    'Навык совместного проектирования семейного творческого продукта.',
                ],
                'subject_specific' => [
                    'Осмысление традиций и истории Отечества через призму истории своего рода.',
                    'Создание рукотворной страницы семейного летописного альбома.',
                ],
            ],
            'expected_outcomes' => [
                'knowledge' => [
                    'Знание истории своего рода, династических ремесел и традиций семьи.',
                ],
                'skills' => [
                    'Опыт теплого доверительного общения и продуктивного сотрудничества поколений.',
                ],
                'values' => [
                    'Святость семьи, уважение к старшим, любовь и верность семейным заветам.',
                ],
            ],
            'stages' => $stages,
            'roles' => [
                [
                    'role_name' => 'Капитан семейного экипажа',
                    'description' => 'Учащийся, координирующий участие своей семьи в конкурсных заданиях.',
                    'target_participants' => 'students',
                    'group_size' => 1,
                    'specific_tasks' => [
                        'Помогать родителям ориентироваться в заданиях мастерской.',
                        'Озвучивать командные решения от имени семейной команды.',
                    ],
                ],
                [
                    'role_name' => 'Хранитель семейной летописи',
                    'description' => 'Родитель или представитель старшего поколения, передающий исторические воспоминания.',
                    'target_participants' => 'parents',
                    'group_size' => 1,
                    'specific_tasks' => [
                        'Рассказать детям о значимых реликвиях и событиях рода.',
                        'Заполнять исторические разделы совместного семейного древа.',
                    ],
                ],
                [
                    'role_name' => 'Семейный фасилитатор',
                    'description' => 'Классный руководитель, создающий комфортную среду для доверительного разговора.',
                    'target_participants' => 'teachers',
                    'group_size' => 1,
                    'specific_tasks' => [
                        'Поддерживать семьи, испытывающие сложности в публичных выступлениях.',
                        'Обеспечить неформальный дружеский тон общего общения.',
                    ],
                ],
                [
                    'role_name' => 'Почетный наставник поколений',
                    'description' => 'Приглашенный ветеран труда, педагог-наставник или представитель общественности.',
                    'target_participants' => 'guests',
                    'group_size' => 1,
                    'specific_tasks' => [
                        'Произнести напутственное слово молодым семьям.',
                        'Принять участие во вручении памятных семейных свидетельств.',
                    ],
                ],
            ],
            'activities' => [
                [
                    'title' => 'Семейная лаборатория родословия: «'.$mechanismInfo['activity_title'].'»',
                    'activity_type' => 'intergenerational_workshop',
                    'target_stage_id' => 'stage_family_lab',
                    'rules_and_instructions' => 'Семьи за круглыми столами объединяют воспоминания родителей и рисунки детей, формируя летописный лист династии по теме «'.$occasionTitle.'».',
                    'scoring_or_evaluation' => 'Взаимное признание без баллов: каждая семья отмечается памятным знаком в уникальной номинации.',
                ],
                [
                    'title' => 'Гостиная семейных историй и диалог поколений',
                    'activity_type' => 'family_dialogue_roundtable',
                    'target_stage_id' => 'stage_intergenerational_dialogue',
                    'rules_and_instructions' => 'Семейные экипажи по очереди делятся главной мудростью, переданной от дедушек и бабушек.',
                    'scoring_or_evaluation' => 'Искренние аплодисменты зала и занесение мудрости в общешкольную Книгу Семьи.',
                ],
            ],
            'materials' => [
                'presentation_slides' => [
                    [
                        'slide_number' => 1,
                        'title' => 'Семейная гостиная: «'.$occasionTitle.'»',
                        'bullet_points' => [
                            'Тепло семейного очага и преемственность поколений',
                            'Программа совместной мастерской',
                        ],
                        'visual_recommendations' => 'Уютные семейные фотографии, теплые тона оформления.',
                        'speaker_notes' => 'Создать доверительный настрой, подчеркнуть благодарность родителям за участие.',
                    ],
                    [
                        'slide_number' => 2,
                        'title' => 'Мастерская: создаем родословную книгу',
                        'bullet_points' => [
                            'Как заполнить древо традиций семьи',
                            'Правила бережного диалога поколений',
                        ],
                        'visual_recommendations' => 'Образец оформления генеалогического листа семьи.',
                        'speaker_notes' => 'Напомнить, что главное — живые воспоминания, а не каллиграфия.',
                    ],
                ],
                'printable_cards' => [
                    [
                        'card_type' => 'family_blank',
                        'title' => 'Бланк летописи семейного рода',
                        'front_text' => 'Шаблон семейного древа и места для памятных дат семьи.',
                        'back_text' => 'Поле для семейного девиза и напутствия будущим поколениям.',
                        'quantity_per_group' => 3,
                    ],
                ],
                'handouts' => [
                    [
                        'title' => 'Памятка диалога поколений',
                        'instructions' => 'Вопросы-помощники для детей при беседе со старшими членами семьи.',
                        'template_content' => 'Подсказки: О чем вы мечтали в моем возрасте? Какая песня была любимой? Что помогало дружить?',
                    ],
                ],
            ],
            'props' => [
                [
                    'item_name' => 'Чайный стол и одноразовая праздничная посуда',
                    'quantity' => 'По количеству участников',
                    'is_mandatory' => false,
                    'alternative_if_missing' => 'Уютная расстановка столов островками с кулером воды.',
                ],
                [
                    'item_name' => 'Наборы для творчества: цветной картон, клей, маркеры, '.$resourcesText,
                    'quantity' => '1 набор на каждую семью',
                    'is_mandatory' => true,
                    'alternative_if_missing' => 'Стандартная бумага и карандаши.',
                ],
            ],
            'preparation_checklist' => [
                [
                    'timeline_phase' => 'two_weeks_before',
                    'task_description' => 'Разослать персональные приглашения семьям, бабушкам и дедушкам с анонсом темы: '.$resourcesText,
                    'responsible_person' => 'Классный руководитель',
                ],
                [
                    'timeline_phase' => 'three_days_before',
                    'task_description' => 'Подготовить бланки семейных древ и памятные свидетельства хранителей традиций.',
                    'responsible_person' => 'Организатор воспитательной работы',
                ],
                [
                    'timeline_phase' => 'hour_before',
                    'task_description' => 'Оформить зал круглыми столами для семей и включить фоновую душевную музыку.',
                    'responsible_person' => 'Классный руководитель',
                ],
            ],
            'teacher_instructions' => [
                'general_safety_rules' => [
                    'Обеспечить комфортную рассадку пожилых участников семьи (ближе к выходу, удобные стулья).',
                    'Соблюдать осторожность при организации горячего чаепития.',
                ],
                'methodological_recommendations' => [
                    'Уделять особое внимание семьям, где присутствуют представители трех поколений.',
                    'Если ребенок пришел один или с опекуном, поддержать его и привлечь в качестве помощника ведущего.',
                ],
                'adaptation_for_disabilities_or_small_groups' => 'Предусмотреть безбарьерный доступ в зал для маломобильных дедушек и бабушек.',
            ],
        ];
    }

    /**
     * Generate deeply personalized variant taking into account school parameters, target grades, resources, and mechanism.
     */
    public function generatePersonalized(ScenarioRequest $request): array
    {
        return $this->generate($request);
    }

    /**
     * Generate universal invariant variant with standardized general discussion format and no deep school-specific ties.
     */
    public function generateUniversal(ScenarioRequest $request): array
    {
        $startTime = microtime(true);
        $context = $request->context_snapshot;
        $occasionTitle = $context['occasion']['title'] ?? ($request->custom_event_title ?? 'Тематическое школьное мероприятие');
        $direction = $request->value_direction;
        $directionTitle = self::DIRECTION_TITLES[$direction] ?? 'Традиционные ценности';
        $duration = $request->duration_minutes;

        // Partition into 3 universal stages: motivation (20%), frontal discussion (60%), summary (20%)
        $stage1 = max(5, min(30, (int) round($duration * 0.20)));
        $stage3 = max(5, min(30, (int) round($duration * 0.20)));
        $stage2 = $duration - $stage1 - $stage3;

        $stages = [
            [
                'id' => 'stage_universal_intro',
                'order_index' => 1,
                'title' => 'Вводная мотивационная беседа: «'.$occasionTitle.'»',
                'stage_type' => 'motivation_introduction',
                'duration_minutes' => $stage1,
                'teacher_actions' => 'Учитель сообщает тему классного часа, обозначает важность направления «'.$directionTitle.'» и задает общий вводный вопрос.',
                'student_actions' => 'Учащиеся слушают вводную информацию и отвечают на вопросы с места.',
                'dialogue_prompts' => [
                    'Что вам известно о событии «'.$occasionTitle.'»?',
                    'Как ценность «'.$directionTitle.'» проявляется в нашей повседневной жизни?',
                ],
                'teacher_tips' => 'Соблюдать регламент вводной части, вовлекая учащихся в общий диалог.',
                'transition_text' => 'Перейдем к подробному рассмотрению основных фактов и понятий темы.',
            ],
            [
                'id' => 'stage_universal_discussion',
                'order_index' => 2,
                'title' => 'Фронтальная беседа и разбор базовых понятий',
                'stage_type' => 'main_interactive_part',
                'duration_minutes' => $stage2,
                'teacher_actions' => 'Учитель излагает ключевые тезисы темы, демонстрирует слайды на доске и организует общеклассное обсуждение.',
                'student_actions' => 'Учащиеся фиксируют ключевые термины в тетрадях и участвуют в обсуждении вопросов учителя.',
                'dialogue_prompts' => [
                    'Какие главные уроки мы можем извлечь из обсуждаемых событий?',
                    'В чем заключается непреходящее значение традиции?',
                ],
                'teacher_tips' => 'Задавать открытые вопросы всему классу, стимулируя аргументированные ответы.',
                'transition_text' => 'Обобщим услышанное и подведем итоги нашего занятия.',
            ],
            [
                'id' => 'stage_universal_conclusion',
                'order_index' => 3,
                'title' => 'Итоговое обобщение и выводы',
                'stage_type' => 'reflection_summary',
                'duration_minutes' => $stage3,
                'teacher_actions' => 'Учитель подводит итоги беседы, фиксирует базовые ценностные ориентиры и благодарит учащихся.',
                'student_actions' => 'Учащиеся формулируют главный вывод и оценивают полезность занятия.',
                'dialogue_prompts' => [
                    'Какой самый важный вывод вы сделали для себя сегодня?',
                ],
                'teacher_tips' => 'Зафиксировать единое смысловое завершение классного часа.',
                'transition_text' => 'Спасибо всем за активное участие в классном часе!',
            ],
        ];

        $outputPayload = [
            'cultural_claims' => [],
            'concept' => 'Универсальный инвариантный сценарий: «'.$occasionTitle.'». Базовый формат общеклассного обсуждения без локализации под конкретную школу и ресурсы.',
            'pedagogical_goal' => 'Общее ознакомление учащихся с ценностным смыслом события «'.$occasionTitle.'» в рамках направления «'.$directionTitle.'».',
            'value_direction' => $direction,
            'pedagogical_mechanism' => [
                'mechanism_type' => self::MECHANISMS[$direction]['type'] ?? 'team_mutual_support_task',
                'target_activity_title' => 'Общеклассная понятийная беседа',
                'description' => 'Универсальная фронтальная форма обсуждения базовых смысловых ориентиров темы.',
            ],
            'pedagogical_tasks' => [
                'personal' => [
                    'Формирование уважительного отношения к историческому наследию.',
                    'Осознание значимости общечеловеческих ценностей.',
                ],
                'meta_subject' => [
                    'Развитие умения внимательно слушать собеседника и аргументировать ответ.',
                    'Навык формулирования собственного мнения в общем диалоге.',
                ],
                'subject_specific' => [
                    'Усвоение ключевых дат, понятий и фактов события «'.$occasionTitle.'».',
                ],
            ],
            'expected_outcomes' => [
                'knowledge' => [
                    'Знание базовых фактов и смыслового значения темы «'.$occasionTitle.'».',
                ],
                'skills' => [
                    'Умение формулировать связное суждение по теме классного часа.',
                ],
                'values' => [
                    'Принятие традиционных ценностей направления «'.$directionTitle.'».',
                ],
            ],
            'target_audience' => [
                'age_group' => 'basic_5_9',
                'target_grades' => [5, 6, 7, 8, 9],
                'family_involvement' => false,
                'participants_count_min' => 15,
                'participants_count_max' => 35,
            ],
            'format' => [
                'format_type' => 'class_hour',
                'scale' => 'class_level',
                'total_duration_minutes' => $duration,
            ],
            'stages' => $stages,
            'roles' => [
                [
                    'role_name' => 'Ведущий обсуждения',
                    'description' => 'Ученик или дежурный, помогающий учителю в оглашении вопросов и регламенте диалога.',
                    'target_participants' => 'students',
                    'group_size' => 1,
                    'specific_tasks' => [
                        'Оглашать вопросы для обсуждения перед классом.',
                        'Следить за очередностью поднятых рук.',
                    ],
                ],
                [
                    'role_name' => 'Фиксатор идей',
                    'description' => 'Ученик, фиксирующий на доске главные тезисы и выводы класса.',
                    'target_participants' => 'students',
                    'group_size' => 1,
                    'specific_tasks' => [
                        'Записывать ключевые слова и выводы на доске.',
                    ],
                ],
            ],
            'activities' => [
                [
                    'title' => 'Общеклассная понятийная беседа',
                    'activity_type' => 'group_research',
                    'target_stage_id' => 'stage_universal_discussion',
                    'rules_and_instructions' => 'Фронтальное рассмотрение ключевых аспектов события с фиксацией тезисов на доске.',
                    'scoring_or_evaluation' => 'Качественная вербальная оценка активности и аргументированности ответов.',
                ],
            ],
            'materials' => [
                'presentation_slides' => [
                    [
                        'slide_number' => 1,
                        'title' => $occasionTitle.': Введение в тему',
                        'bullet_points' => [
                            'Тема и задачи классного часа',
                            'Ключевые понятия',
                        ],
                        'visual_recommendations' => 'Заглавный слайд с официальным названием темы.',
                        'speaker_notes' => 'Озвучить цели занятия и пригласить учащихся к открытому диалогу.',
                    ],
                    [
                        'slide_number' => 2,
                        'title' => 'Ключевые смыслы и обсуждение',
                        'bullet_points' => [
                            'Исторический контекст',
                            'Вопросы для размышления',
                        ],
                        'visual_recommendations' => 'Информационный слайд с вопросами.',
                        'speaker_notes' => 'Модерировать ответы класса, обобщая высказывания.',
                    ],
                ],
                'printable_cards' => [
                    [
                        'card_type' => 'lesson_card',
                        'title' => 'Вопросы к классному часу: «'.$occasionTitle.'»',
                        'front_text' => 'Контрольные вопросы для фронтального обсуждения темы.',
                        'back_text' => 'Памятка для формулирования вывода.',
                        'quantity_per_group' => 1,
                    ],
                ],
                'handouts' => [
                    [
                        'title' => 'Памятка участника классного часа',
                        'instructions' => 'Ознакомьтесь с материалом и запишите главный вывод.',
                        'template_content' => 'Поля: Тема / Ключевые понятия / Мой ценностный вывод.',
                    ],
                ],
            ],
            'props' => [
                [
                    'item_name' => 'Классная доска и мел (маркеры)',
                    'quantity' => '1 комплект',
                    'is_mandatory' => true,
                    'alternative_if_missing' => 'Листы бумаги формата А3.',
                ],
            ],
            'preparation_checklist' => [
                ['timeline_phase' => 'hour_before', 'task_description' => 'Проверить готовность материалов и правила безопасного обсуждения.', 'responsible_person' => 'Классный руководитель'],
                [
                    'timeline_phase' => 'day_before',
                    'task_description' => 'Распечатать памятки участников и проверить проектор для презентации.',
                    'responsible_person' => 'Классный руководитель',
                ],
            ],
            'teacher_instructions' => [
                'general_safety_rules' => [
                    'Соблюдать стандартные правила безопасности в учебном кабинете.',
                ],
                'methodological_recommendations' => [
                    'Поддерживать доброжелательный тон и вовлекать в диалог неактивных учащихся.',
                ],
                'adaptation_for_disabilities_or_small_groups' => 'При необходимости предоставить памятку с укрупненным шрифтом.',
            ],
        ];

        return [
            'provider' => self::PROVIDER_NAME,
            'model' => self::MODEL_IDENTIFIER,
            'prompt_version' => self::PROMPT_VERSION,
            'prompt_tokens' => 100,
            'completion_tokens' => 200,
            'total_latency_ms' => (int) round((microtime(true) - $startTime) * 1000),
            'validation_status' => 'valid',
            'output_payload' => $outputPayload,
        ];
    }

    /**
     * Generate variant without cultural context block (neutral federal/general).
     */
    public function generateWithoutCulturalContext(ScenarioRequest $request): array
    {
        // Temporarily strip cultural claims from context snapshot
        $originalSnapshot = $request->context_snapshot;
        $strippedSnapshot = $originalSnapshot;
        if (isset($strippedSnapshot['cultural_provenance'])) {
            $strippedSnapshot['cultural_provenance']['claims'] = [];
        }
        $request->context_snapshot = $strippedSnapshot;

        try {
            $result = $this->generate($request);
            // Ensure no cultural claims in output payload
            if (isset($result['output_payload'])) {
                $result['output_payload']['cultural_claims'] = [];
            }

            return $result;
        } finally {
            $request->context_snapshot = $originalSnapshot;
        }
    }

    /**
     * Generate variant with verified cultural context.
     */
    public function generateWithCulturalContext(ScenarioRequest $request, bool $mustBeAuthenticOnly = true): array
    {
        // Filter claims to only verified authentic ones with active evidence
        $originalSnapshot = $request->context_snapshot;
        $filteredSnapshot = $originalSnapshot;
        if (isset($filteredSnapshot['cultural_provenance']['claims'])) {
            $filteredClaims = [];
            foreach ($filteredSnapshot['cultural_provenance']['claims'] as $c) {
                $status = $c['effective_status'] ?? $c['status'] ?? 'unverified';
                $evs = $c['evidence_records'] ?? [];
                if ($status === 'verified_authentic' && ! empty($evs)) {
                    $filteredClaims[] = $c;
                }
            }
            $filteredSnapshot['cultural_provenance']['claims'] = $filteredClaims;
        }
        $request->context_snapshot = $filteredSnapshot;

        try {
            return $this->generate($request);
        } finally {
            $request->context_snapshot = $originalSnapshot;
        }
    }
}
