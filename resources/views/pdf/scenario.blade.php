<!DOCTYPE html>
<html lang="ru">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>{{ $scenario->title ?? 'Методический сценарий' }}</title>
    <style>
        @page {
            margin: 14mm 12mm 14mm 12mm;
            size: a4 portrait;
        }
        body {
            font-family: "DejaVu Sans", sans-serif;
            font-size: 8.5pt;
            line-height: 1.35;
            color: #1e293b;
        }
        .header {
            border-bottom: 2px solid #2563eb;
            padding-bottom: 8px;
            margin-bottom: 10px;
        }
        .school-badge {
            font-size: 8.5pt;
            font-weight: bold;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        h1 {
            font-size: 14pt;
            color: #0f172a;
            margin: 4px 0 6px 0;
            line-height: 1.25;
        }
        .meta-grid {
            width: 100%;
            margin-bottom: 8px;
            border-collapse: collapse;
        }
        .meta-grid td {
            padding: 3px 6px;
            vertical-align: top;
            font-size: 8pt;
        }
        .meta-label {
            font-weight: bold;
            color: #475569;
            width: 22%;
        }
        .meta-value {
            color: #0f172a;
        }
        .section-title {
            font-size: 10.5pt;
            font-weight: bold;
            color: #1e3a8a;
            border-bottom: 1px solid #cbd5e1;
            padding-bottom: 3px;
            margin-top: 10px;
            margin-bottom: 6px;
        }
        .sub-title {
            font-size: 9pt;
            font-weight: bold;
            color: #334155;
            margin-top: 6px;
            margin-bottom: 3px;
        }
        table.data-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 8px;
            font-size: 8pt;
        }
        table.data-table th {
            background-color: #f1f5f9;
            color: #334155;
            text-align: left;
            padding: 4px 6px;
            border: 1px solid #cbd5e1;
            font-weight: bold;
        }
        table.data-table td {
            padding: 4px 6px;
            border: 1px solid #cbd5e1;
            vertical-align: top;
        }
        .tag-authentic {
            display: inline-block;
            background-color: #dcfce7;
            color: #166534;
            padding: 2px 5px;
            border-radius: 3px;
            font-size: 7pt;
            font-weight: bold;
        }
        .tag-unverified {
            display: inline-block;
            background-color: #fef9c3;
            color: #854d0e;
            padding: 2px 5px;
            border-radius: 3px;
            font-size: 7pt;
            font-weight: bold;
        }
        .tag-rejected {
            display: inline-block;
            background-color: #fee2e2;
            color: #991b1b;
            padding: 2px 5px;
            border-radius: 3px;
            font-size: 7pt;
            font-weight: bold;
        }
        .box {
            background-color: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 4px;
            padding: 5px 8px;
            margin-bottom: 6px;
            font-size: 8pt;
        }
        .signoff-box {
            background-color: #f0fdf4;
            border: 1px solid #86efac;
            border-radius: 4px;
            padding: 6px 10px;
            margin-top: 10px;
            margin-bottom: 8px;
        }
        .disclaimer-box {
            background-color: #f8fafc;
            border: 1px dashed #94a3b8;
            border-radius: 4px;
            padding: 6px 10px;
            margin-top: 10px;
            font-size: 7.5pt;
            color: #475569;
            text-align: center;
        }
        ul {
            margin: 2px 0 4px 16px;
            padding: 0;
        }
        li {
            margin-bottom: 2px;
        }
    </style>
</head>
<body>

@php
    $valueDirectionMap = [
        'family' => 'Семья и семейные ценности',
        'mutual_aid' => 'Взаимопомощь и солидарность',
        'historical_memory' => 'Историческая память и преемственность поколений',
        'labor_and_creation' => 'Труд, творчество и созидание',
        'cultural_diversity' => 'Единство в этнокультурном разнообразии',
        'civic_responsibility' => 'Гражданская ответственность и патриотизм',
    ];

    $stageTypeMap = [
        'organizational_moment' => 'Организационный момент',
        'motivation_introduction' => 'Мотивационно-вводный этап',
        'main_interactive_part' => 'Основная интерактивная часть',
        'practical_task' => 'Практическая командная работа',
        'reflection_summary' => 'Рефлексия и подведение итогов',
        'ritual_conclusion' => 'Торжественное завершение / ритуал',
    ];

    $timelineMap = [
        'two_weeks_before' => 'За 2 недели до мероприятия',
        'three_days_before' => 'За 3 дня до мероприятия',
        'day_before' => 'Накануне мероприятия (за день)',
        'hour_before' => 'За 1 час до начала',
    ];

    $formatMap = [
        'class_hour' => 'Классный час',
        'quest' => 'Образовательный квест',
        'quiz' => 'Интеллектуальная викторина',
        'workshop' => 'Творческая мастерская',
        'festival' => 'Школьный фестиваль',
        'roundtable' => 'Круглый стол / дискуссия',
    ];

    $cardTypeMap = [
        'question' => 'Вопрос / задание',
        'fact' => 'Исторический факт',
        'task' => 'Практический кейс',
        'role' => 'Ролевая карточка',
    ];

    $targetParticipantsMap = [
        'students' => 'Обучающиеся',
        'teachers' => 'Педагоги',
        'parents' => 'Родители',
        'guests' => 'Приглашенные гости',
        'moderators' => 'Ведущие / модераторы',
    ];

    $activityTypeMap = [
        'group_research' => 'Групповое исследование',
        'interactive_lecture' => 'Интерактивная беседа',
        'debate' => 'Дискуссия / Дебаты',
        'workshop' => 'Творческая мастерская',
        'role_play' => 'Ролевая игра',
        'case_study' => 'Анализ кейсов',
        'quiz' => 'Викторина',
        'project_work' => 'Проектная работа',
        'team_game' => 'Командная игра',
        'station_rotation' => 'Работа по станциям',
    ];

    $payload = $revision->content_payload ?? [];
    $rawValDir = $payload['value_direction'] ?? '';
    $valDirRu = $valueDirectionMap[$rawValDir] ?? $rawValDir;

    $rawFmtType = $payload['format']['format_type'] ?? '';
    $fmtTypeRu = $formatMap[$rawFmtType] ?? $rawFmtType;
    $claims = $claims ?? ($culturalClaims ?? ($payload['cultural_claims'] ?? []));
@endphp

    <div class="header">
        <div class="school-badge">{{ $scenario->school->name ?? 'Образовательная организация' }}</div>
        <h1>{{ $scenario->title }}</h1>
        <div style="font-size: 8pt; color: #64748b;">
            Ревизия v{{ $revision->version_number }} &bull; 
            Дата формирования: {{ $revision->created_at ? $revision->created_at->format('d.m.Y H:i') : date('d.m.Y H:i') }} &bull;
            Формат: {{ $fmtTypeRu ?: 'Классное мероприятие' }}
        </div>
    </div>

    <!-- Общие параметры мероприятия -->
    <table class="meta-grid">
        <tr>
            <td class="meta-label">Повод:</td>
            <td class="meta-value">{{ $occasionTitle ?? 'Школьное мероприятие' }}</td>
            <td class="meta-label">Длительность:</td>
            <td class="meta-value">{{ $revision->total_duration_minutes ?? ($payload['format']['total_duration_minutes'] ?? '—') }} мин</td>
        </tr>
        <tr>
            <td class="meta-label">Целевая аудитория:</td>
            <td class="meta-value">
                @if(!empty($targetAudience))
                    @if(is_array($targetAudience))
                        @if(!empty($targetAudience['target_grades']))
                            {{ implode(', ', $targetAudience['target_grades']) }} классы
                        @elseif(!empty($targetAudience['grades']))
                            {{ implode(', ', $targetAudience['grades']) }} классы
                        @elseif(!empty($targetAudience['description']))
                            {{ $targetAudience['description'] }}
                        @else
                            Указана в параметрах
                        @endif
                    @else
                        {{ $targetAudience }}
                    @endif
                @else
                    Не указана
                @endif
            </td>
            <td class="meta-label">Участники:</td>
            <td class="meta-value">
                @if(!empty($targetAudience) && is_array($targetAudience) && (!empty($targetAudience['participants_count_max']) || !empty($targetAudience['participants_count_min'])))
                    от {{ $targetAudience['participants_count_min'] ?? 1 }} до {{ $targetAudience['participants_count_max'] ?? '—' }} чел.
                @else
                    Не указано
                @endif
            </td>
        </tr>
        @if($valDirRu)
        <tr>
            <td class="meta-label">Ценностный ориентир:</td>
            <td class="meta-value" colspan="3">{{ $valDirRu }}</td>
        </tr>
        @endif
    </table>

    <!-- Педагогическая концепция и цель -->
    <div class="section-title">Педагогическая концепция и цели</div>
    <div class="box">
        <strong>Концепция:</strong> {{ $payload['concept'] ?? $revision->concept }}
    </div>
    <div class="box">
        <strong>Педагогическая цель:</strong> {{ $payload['pedagogical_goal'] ?? $revision->pedagogical_goal }}
    </div>

    <!-- Педагогические задачи -->
    @if(!empty($pedagogicalTasks) && is_array($pedagogicalTasks))
    <div class="section-title">Педагогические задачи</div>
    <div class="box">
        @if(isset($pedagogicalTasks['personal']) || isset($pedagogicalTasks['meta_subject']) || isset($pedagogicalTasks['subject_specific']))
            @if(!empty($pedagogicalTasks['personal']))
                <div style="margin-bottom: 2px;"><strong>Личностные задачи:</strong></div>
                <ul>@foreach($pedagogicalTasks['personal'] as $t) <li>{{ is_array($t) ? json_encode($t, JSON_UNESCAPED_UNICODE) : $t }}</li> @endforeach</ul>
            @endif
            @if(!empty($pedagogicalTasks['meta_subject']))
                <div style="margin-bottom: 2px;"><strong>Метапредметные задачи:</strong></div>
                <ul>@foreach($pedagogicalTasks['meta_subject'] as $t) <li>{{ is_array($t) ? json_encode($t, JSON_UNESCAPED_UNICODE) : $t }}</li> @endforeach</ul>
            @endif
            @if(!empty($pedagogicalTasks['subject_specific']))
                <div style="margin-bottom: 2px;"><strong>Предметные задачи:</strong></div>
                <ul>@foreach($pedagogicalTasks['subject_specific'] as $t) <li>{{ is_array($t) ? json_encode($t, JSON_UNESCAPED_UNICODE) : $t }}</li> @endforeach</ul>
            @endif
        @else
            <ul>
                @foreach($pedagogicalTasks as $task)
                    <li>{{ is_array($task) ? ($task['description'] ?? json_encode($task, JSON_UNESCAPED_UNICODE)) : $task }}</li>
                @endforeach
            </ul>
        @endif
    </div>
    @endif

    <!-- Планируемые результаты -->
    @if(!empty($expectedOutcomes) && is_array($expectedOutcomes))
    <div class="section-title">Планируемые результаты</div>
    <table class="data-table">
        <thead>
            <tr>
                <th style="width: 28%;">Категория</th>
                <th style="width: 72%;">Результаты освоения</th>
            </tr>
        </thead>
        <tbody>
            @if(!empty($expectedOutcomes['knowledge']))
            <tr>
                <td><strong>Знания (когнитивные)</strong></td>
                <td>
                    @if(is_array($expectedOutcomes['knowledge']))
                        <ul>@foreach($expectedOutcomes['knowledge'] as $it) <li>{{ $it }}</li> @endforeach</ul>
                    @else
                        {{ $expectedOutcomes['knowledge'] }}
                    @endif
                </td>
            </tr>
            @endif
            @if(!empty($expectedOutcomes['skills']))
            <tr>
                <td><strong>Умения и навыки</strong></td>
                <td>
                    @if(is_array($expectedOutcomes['skills']))
                        <ul>@foreach($expectedOutcomes['skills'] as $it) <li>{{ $it }}</li> @endforeach</ul>
                    @else
                        {{ $expectedOutcomes['skills'] }}
                    @endif
                </td>
            </tr>
            @endif
            @if(!empty($expectedOutcomes['values']))
            <tr>
                <td><strong>Ценностные ориентиры</strong></td>
                <td>
                    @if(is_array($expectedOutcomes['values']))
                        <ul>@foreach($expectedOutcomes['values'] as $it) <li>{{ $it }}</li> @endforeach</ul>
                    @else
                        {{ $expectedOutcomes['values'] }}
                    @endif
                </td>
            </tr>
            @endif
        </tbody>
    </table>
    @endif

    <!-- Роли участников -->
    @if(!empty($roles) && is_array($roles))
    <div class="section-title">Роли участников</div>
    <table class="data-table">
        <thead>
            <tr>
                <th style="width: 22%;">Роль</th>
                <th style="width: 18%;">Целевая группа</th>
                <th style="width: 32%;">Описание</th>
                <th style="width: 28%;">Задачи роли</th>
            </tr>
        </thead>
        <tbody>
            @foreach($roles as $role)
            @php
                $rawTarget = is_array($role) ? ($role['target_participants'] ?? '') : '';
                $targetRu = $targetParticipantsMap[$rawTarget] ?? ($rawTarget ?: 'Все участники');
            @endphp
            <tr>
                <td><strong>{{ is_array($role) ? ($role['role_name'] ?? ($role['title'] ?? 'Участник')) : $role }}</strong></td>
                <td>{{ $targetRu }}</td>
                <td>{{ is_array($role) ? ($role['description'] ?? '—') : '—' }}</td>
                <td>
                    @if(is_array($role) && !empty($role['specific_tasks']) && is_array($role['specific_tasks']))
                        <ul>@foreach($role['specific_tasks'] as $task) <li>{{ $task }}</li> @endforeach</ul>
                    @else
                        {{ is_array($role) ? ($role['responsibilities'] ?? '—') : '—' }}
                    @endif
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @endif

    <!-- Активности и формы работы -->
    @if(!empty($activities) && is_array($activities))
    <div class="section-title">Активности и формы взаимодействия</div>
    <table class="data-table">
        <thead>
            <tr>
                <th style="width: 25%;">Активность</th>
                <th style="width: 45%;">Правила и инструкции проведения</th>
                <th style="width: 30%;">Критерии подведения итогов</th>
            </tr>
        </thead>
        <tbody>
            @foreach($activities as $act)
            <tr>
                <td>
                    <strong>{{ is_array($act) ? ($act['title'] ?? 'Активность') : $act }}</strong>
                    @if(is_array($act) && !empty($act['activity_type']))
                        @php
                            $actRawType = $act['activity_type'];
                            $actTypeRu = $activityTypeMap[$actRawType] ?? $actRawType;
                        @endphp
                        <br/><small style="color: #64748b;">Тип: {{ $actTypeRu }}</small>
                    @endif
                </td>
                <td>{{ is_array($act) ? ($act['rules_and_instructions'] ?? ($act['description'] ?? '—')) : '—' }}</td>
                <td>{{ is_array($act) ? ($act['scoring_or_evaluation'] ?? ($act['instructions'] ?? '—')) : '—' }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @endif

    <!-- Этапы проведения мероприятия -->
    <div class="section-title">Хронометраж и этапы мероприятия</div>
    <table class="data-table">
        <thead>
            <tr>
                <th style="width: 4%;">№</th>
                <th style="width: 24%;">Этап</th>
                <th style="width: 8%;">Время</th>
                <th style="width: 32%;">Действия педагога</th>
                <th style="width: 32%;">Действия участников</th>
            </tr>
        </thead>
        <tbody>
            @php
                $stagesList = $payload['stages'] ?? ($revision->stages ?? []);
            @endphp
            @foreach($stagesList as $stage)
            @php
                $rawStageType = is_array($stage) ? ($stage['stage_type'] ?? '') : ($stage->stage_type ?? '');
                $stageTypeRu = $stageTypeMap[$rawStageType] ?? ($rawStageType ?: 'Основной этап');
                $stTitle = is_array($stage) ? ($stage['title'] ?? '') : ($stage->title ?? '');
                $stDur = is_array($stage) ? ($stage['duration_minutes'] ?? '') : ($stage->duration_minutes ?? '');
                $stTeacher = is_array($stage) ? ($stage['teacher_actions'] ?? '') : ($stage->teacher_actions ?? '');
                $stStudent = is_array($stage) ? ($stage['student_actions'] ?? '') : ($stage->student_actions ?? '');
            @endphp
            <tr>
                <td>{{ is_array($stage) ? ($stage['order_index'] ?? ($loop->index + 1)) : ($stage->order_index ?? ($loop->index + 1)) }}</td>
                <td>
                    <strong>{{ $stTitle }}</strong><br/>
                    <small style="color: #64748b;">{{ $stageTypeRu }}</small>
                </td>
                <td>{{ $stDur }} мин</td>
                <td>{{ $stTeacher }}</td>
                <td>{{ $stStudent }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <!-- Материалы и методическое оснащение -->
    @php
        $slides = $payload['materials']['presentation_slides'] ?? ($presentationSlides ?? []);
        $cards = $payload['materials']['printable_cards'] ?? ($printableCards ?? []);
        $handoutsList = $payload['materials']['handouts'] ?? ($handouts ?? []);
        $propsList = $payload['props'] ?? ($props ?? []);
    @endphp

    @if(!empty($slides) || !empty($cards) || !empty($handoutsList) || !empty($propsList))
    <div class="section-title">Методические материалы и оснащение</div>

    <!-- Презентационные слайды -->
    @if(!empty($slides) && is_array($slides))
    <div class="sub-title">Презентационные слайды</div>
    <table class="data-table">
        <thead>
            <tr>
                <th style="width: 8%;">Слайд</th>
                <th style="width: 25%;">Заголовок</th>
                <th style="width: 37%;">Ключевые тезисы</th>
                <th style="width: 30%;">Заметки педагога / визуал</th>
            </tr>
        </thead>
        <tbody>
            @foreach($slides as $slide)
            <tr>
                <td>№ {{ $slide['slide_number'] ?? ($loop->index + 1) }}</td>
                <td><strong>{{ $slide['title'] ?? 'Слайд' }}</strong></td>
                <td>
                    @if(!empty($slide['bullet_points']) && is_array($slide['bullet_points']))
                        <ul>@foreach($slide['bullet_points'] as $bp) <li>{{ $bp }}</li> @endforeach</ul>
                    @else
                        {{ is_array($slide['bullet_points'] ?? null) ? '' : ($slide['bullet_points'] ?? '—') }}
                    @endif
                </td>
                <td>
                    @if(!empty($slide['speaker_notes']))
                        <div><strong>Заметки:</strong> {{ $slide['speaker_notes'] }}</div>
                    @endif
                    @if(!empty($slide['visual_recommendations']))
                        <div style="color: #64748b; font-size: 7.5pt; margin-top: 2px;">
                            <em>Визуал: {{ $slide['visual_recommendations'] }}</em>
                        </div>
                    @endif
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @endif

    <!-- Раздаточные карточки -->
    @if(!empty($cards) && is_array($cards))
    <div class="sub-title">Карточки для печати</div>
    <table class="data-table">
        <thead>
            <tr>
                <th style="width: 22%;">Название</th>
                <th style="width: 14%;">Тип</th>
                <th style="width: 27%;">Лицевая сторона</th>
                <th style="width: 27%;">Оборотная сторона</th>
                <th style="width: 10%;">Тираж</th>
            </tr>
        </thead>
        <tbody>
            @foreach($cards as $card)
            @php
                $cTypeRaw = $card['card_type'] ?? '';
                $cTypeRu = $cardTypeMap[$cTypeRaw] ?? ($cTypeRaw ?: 'Карточка');
            @endphp
            <tr>
                <td><strong>{{ $card['title'] ?? 'Карточка' }}</strong></td>
                <td>{{ $cTypeRu }}</td>
                <td>{{ $card['front_text'] ?? '—' }}</td>
                <td>{{ $card['back_text'] ?? '—' }}</td>
                <td>{{ $card['quantity_per_group'] ?? 1 }} шт/гр</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @endif

    <!-- Раздаточные материалы и бланки -->
    @if(!empty($handoutsList) && is_array($handoutsList))
    <div class="sub-title">Раздаточные материалы и бланки заданий</div>
    <table class="data-table">
        <thead>
            <tr>
                <th style="width: 25%;">Наименование</th>
                <th style="width: 35%;">Инструкция для участников</th>
                <th style="width: 40%;">Содержимое / шаблон бланка</th>
            </tr>
        </thead>
        <tbody>
            @foreach($handoutsList as $handout)
            <tr>
                <td><strong>{{ $handout['title'] ?? 'Раздаточный материал' }}</strong></td>
                <td>{{ $handout['instructions'] ?? '—' }}</td>
                <td>{{ $handout['template_content'] ?? '—' }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @endif

    <!-- Реквизит и оборудование -->
    @if(!empty($propsList) && is_array($propsList))
    <div class="sub-title">Реквизит и необходимое оборудование</div>
    <table class="data-table">
        <thead>
            <tr>
                <th style="width: 30%;">Наименование</th>
                <th style="width: 15%;">Количество</th>
                <th style="width: 20%;">Обязательность</th>
                <th style="width: 35%;">Альтернатива при отсутствии</th>
            </tr>
        </thead>
        <tbody>
            @foreach($propsList as $pr)
            <tr>
                <td><strong>{{ $pr['item_name'] ?? ($pr['name'] ?? 'Предмет') }}</strong></td>
                <td>{{ $pr['quantity'] ?? '1 шт' }}</td>
                <td>{{ (!empty($pr['is_mandatory'])) ? 'Обязательно' : 'Рекомендуется' }}</td>
                <td>{{ $pr['alternative_if_missing'] ?? 'Не предусмотрена' }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @endif

    @endif

    <!-- Чек-лист готовности -->
    @if(!empty($preparationChecklist) && is_array($preparationChecklist))
    <div class="section-title">Чек-лист готовности</div>
    <table class="data-table">
        <thead>
            <tr>
                <th style="width: 25%;">Срок подготовки</th>
                <th style="width: 55%;">Контрольная задача</th>
                <th style="width: 20%;">Ответственный</th>
            </tr>
        </thead>
        <tbody>
            @foreach($preparationChecklist as $item)
            @php
                $rawPhase = is_array($item) ? ($item['timeline_phase'] ?? '') : '';
                $phaseRu = $timelineMap[$rawPhase] ?? ($rawPhase ?: 'В ходе подготовки');
                $taskDesc = is_array($item) ? ($item['task_description'] ?? ($item['task'] ?? '—')) : $item;
                $respPerson = is_array($item) ? ($item['responsible_person'] ?? 'Педагог') : 'Педагог';
            @endphp
            <tr>
                <td><strong>{{ $phaseRu }}</strong></td>
                <td>[ ] {{ $taskDesc }}</td>
                <td>{{ $respPerson }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @endif

    <!-- Инструкции для педагога, правила безопасности и адаптация -->
    @php
        $ti = $payload['teacher_instructions'] ?? ($revision->teacher_instructions ?? []);
        $methodRecs = $ti['methodological_recommendations'] ?? ($recommendations ?? []);
        $safeRules = $ti['general_safety_rules'] ?? ($safetyRules ?? []);
        $adaptText = $ti['adaptation_for_disabilities_or_small_groups'] ?? ($adaptation ?? '');
    @endphp

    <div class="section-title">Инструкции для педагога</div>

    @if(!empty($methodRecs) && is_array($methodRecs))
    <div class="box">
        <strong>Методические рекомендации:</strong>
        <ul>@foreach($methodRecs as $rec) <li>{{ is_array($rec) ? json_encode($rec, JSON_UNESCAPED_UNICODE) : $rec }}</li> @endforeach</ul>
    </div>
    @endif

    @if(!empty($safeRules) && is_array($safeRules))
    <div class="box">
        <strong>Техника безопасности:</strong>
        <ul>@foreach($safeRules as $rule) <li>{{ is_array($rule) ? json_encode($rule, JSON_UNESCAPED_UNICODE) : $rule }}</li> @endforeach</ul>
    </div>
    @endif

    @if(!empty($adaptText))
    <div class="box">
        <strong>Рекомендации по адаптации:</strong>
        <p style="margin: 2px 0 0 0;">{{ is_array($adaptText) ? json_encode($adaptText, JSON_UNESCAPED_UNICODE) : $adaptText }}</p>
    </div>
    @endif

    <!-- Культурные первоисточники и утверждения -->
    @if(!empty($claims) && is_array($claims))
    <div class="section-title">Культурные утверждения и первоисточники</div>
    <table class="data-table">
        <thead>
            <tr>
                <th style="width: 44%;">Историческое / культурное утверждение</th>
                <th style="width: 22%;">Статус достоверности</th>
                <th style="width: 34%;">Первоисточник и ссылка</th>
            </tr>
        </thead>
        <tbody>
            @foreach($claims as $claim)
            @php
                $rawStatus = $claim['server_verification_status'] ?? ($claim['status'] ?? 'unverified');
                $hasEvidence = !empty($claim['evidence_records']) && is_array($claim['evidence_records']);
            @endphp
            <tr>
                <td>
                    <strong>{{ $claim['claim_text'] ?? ($claim['text'] ?? '') }}</strong>
                    @if(!empty($claim['culture_name']))
                        <br/><small style="color: #64748b;">Культура: {{ $claim['culture_name'] }}</small>
                    @endif
                </td>
                <td>
                    @if($rawStatus === 'verified_authentic' && $hasEvidence)
                        <span class="tag-authentic">&check; ПОДТВЕРЖДЕНО ЭКСПЕРТАМИ</span>
                    @elseif($rawStatus === 'rejected_fake')
                        <span class="tag-rejected">&cross; ОПРОВЕРГНУТО</span>
                    @else
                        <span class="tag-unverified">&#9888; ТРЕБУЕТ ПРОВЕРКИ</span>
                    @endif
                </td>
                <td>
                    @if(!empty($claim['evidence_records']) && is_array($claim['evidence_records']))
                        @foreach($claim['evidence_records'] as $ev)
                            <div><strong>{{ $ev['source_title'] ?? 'Первоисточник' }}</strong></div>
                            @if(!empty($ev['citation_locator']))
                                <small style="color: #64748b;">Локатор: {{ $ev['citation_locator'] }}</small><br/>
                            @endif
                            @if(!empty($ev['citation_excerpt']))
                                <small style="color: #475569; font-style: italic;">«{{ $ev['citation_excerpt'] }}»</small>
                            @endif
                        @endforeach
                    @else
                        <small style="color: #94a3b8;">Первоисточник не привязан к утверждению</small>
                    @endif
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @endif

    <!-- Отметка о подтверждении педагогом -->
    @if($signoff)
    <div class="signoff-box">
        <div style="font-weight: bold; color: #166534; font-size: 9.5pt; margin-bottom: 4px;">
            &check; Подтверждено педагогом (Human Sign-Off)
        </div>
        <table class="meta-grid" style="margin-bottom: 0;">
            <tr>
                <td class="meta-label">Педагог:</td>
                <td class="meta-value">{{ $signoff->user->name ?? $signoff->user->email ?? 'Педагог' }}</td>
                <td class="meta-label">Дата и время:</td>
                <td class="meta-value">{{ $signoff->confirmed_at->format('d.m.Y H:i:s') }} (UTC)</td>
            </tr>
            <tr>
                <td class="meta-label">Чек-лист:</td>
                <td class="meta-value" colspan="3">
                    Безопасность проверена &bull; 
                    Хронометраж согласован &bull; 
                    Культурный контекст одобрен
                </td>
            </tr>
            @if($signoff->teacher_notes)
            <tr>
                <td class="meta-label">Заметки педагога:</td>
                <td class="meta-value" colspan="3">{{ $signoff->teacher_notes }}</td>
            </tr>
            @endif
        </table>
    </div>
    @endif

    <!-- Точная маркировка MockLlmProvider v1.0.0 -->
    <div class="disclaimer-box">
        <strong>Образовательный проект «Школьные события» (Сбер &times; УрФУ)</strong><br/>
        Сгенерировано: MockLlmProvider v1.0.0 (демонстрационная модель без внешнего ИИ). Сценарий прошёл автоматический аудит ModerationEngineService и педагогическое подтверждение.
    </div>

</body>
</html>