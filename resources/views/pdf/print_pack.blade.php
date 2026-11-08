<!DOCTYPE html>
<html lang="ru">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Печатный комплект: {{ $scenario->title ?? 'Мероприятие' }}</title>
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
            margin-bottom: 12px;
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
        .doc-type {
            font-size: 10pt;
            font-weight: bold;
            color: #2563eb;
            text-transform: uppercase;
            margin-bottom: 4px;
        }
        .page-break {
            page-break-after: always;
        }
        .section-title {
            font-size: 11pt;
            font-weight: bold;
            color: #1e3a8a;
            border-bottom: 1px solid #cbd5e1;
            padding-bottom: 4px;
            margin-top: 14px;
            margin-bottom: 8px;
        }
        .card-box {
            border: 1.5px dashed #64748b;
            background-color: #f8fafc;
            border-radius: 6px;
            padding: 10px;
            margin-bottom: 14px;
        }
        .cut-marker {
            font-size: 7.5pt;
            color: #94a3b8;
            margin-bottom: 6px;
            text-align: right;
            font-style: italic;
        }
        .card-title {
            font-size: 10pt;
            font-weight: bold;
            color: #0f172a;
            margin-bottom: 4px;
        }
        .card-role {
            font-size: 8.5pt;
            font-weight: bold;
            color: #2563eb;
            margin-bottom: 6px;
        }
        .table-standard {
            width: 100%;
            border-collapse: collapse;
            margin-top: 6px;
            margin-bottom: 10px;
        }
        .table-standard th, .table-standard td {
            border: 1px solid #cbd5e1;
            padding: 5px 8px;
            font-size: 8pt;
            vertical-align: top;
        }
        .table-standard th {
            background-color: #f1f5f9;
            font-weight: bold;
            color: #334155;
            text-align: left;
        }
        .empty-placeholder {
            border: 1px solid #e2e8f0;
            background-color: #f8fafc;
            padding: 12px;
            border-radius: 4px;
            color: #64748b;
            font-style: italic;
            text-align: center;
            margin: 10px 0;
        }
        .signoff-stamp {
            border: 1.5px solid #2563eb;
            background-color: #eff6ff;
            padding: 8px 12px;
            border-radius: 6px;
            margin-top: 14px;
        }
        .badge {
            display: inline-block;
            padding: 2px 6px;
            font-size: 7.5pt;
            font-weight: bold;
            border-radius: 3px;
        }
        .badge-verified {
            background-color: #dcfce7;
            color: #166534;
        }
        .badge-unverified {
            background-color: #fef9c3;
            color: #854d0e;
        }
        .badge-rejected {
            background-color: #fee2e2;
            color: #991b1b;
        }
    </style>
</head>
<body>

    <!-- ОБЛОЖКА И ПАМЯТКА ПЕДАГОГА -->
    <div class="header">
        <div class="school-badge">{{ $scenario->school->name ?? 'МАОУ Лицей № 130' }}</div>
        <div class="doc-type">ПЕЧАТНЫЙ КОМПЛЕКТ МАТЕРИАЛОВ (A4 PRINT PACK)</div>
        <h1>{{ $scenario->title }}</h1>
        <div style="font-size: 8.5pt; color: #475569;">
            Повод: <strong>{{ $occasionTitle ?? $scenario->title }}</strong> | 
            Ревизия: <strong>v{{ $revision->version_number }}</strong> | 
            Продолжительность: <strong>{{ $revision->total_duration_minutes ?? 45 }} мин.</strong>
        </div>
    </div>

    <!-- РАЗДЕЛ 1: ПАМЯТКА И ЧЕКЛИСТ ПЕДАГОГА -->
    <div class="section-title">1. Памятка и организационный чек-лист педагога</div>
    <p style="margin-bottom: 8px;">Данная памятка содержит пошаговый регламент подготовки и проведения воспитательного мероприятия, а также перечень обязательного оборудования.</p>

    <table class="table-standard">
        <thead>
            <tr>
                <th style="width: 8%;">Чек</th>
                <th style="width: 25%;">Этап регламента</th>
                <th style="width: 15%;">Тайминг</th>
                <th style="width: 52%;">Организационные действия педагога</th>
            </tr>
        </thead>
        <tbody>
            @php $stNum = 1; @endphp
            @foreach($stages as $st)
                @php
                    $sTitle = is_object($st) ? $st->title : ($st['title'] ?? "Этап {$stNum}");
                    $sDur = is_object($st) ? $st->duration_minutes : ($st['duration_minutes'] ?? 0);
                    $sTeacher = is_object($st) ? ($st->teacher_actions ?? '') : ($st['teacher_actions'] ?? '');
                @endphp
                <tr>
                    <td style="text-align: center; font-weight: bold; font-size: 10pt;">[ &nbsp; ]</td>
                    <td><strong>Этап {{ $stNum }}:</strong> {{ $sTitle }}</td>
                    <td>{{ $sDur }} мин.</td>
                    <td>{{ $sTeacher ?: 'Проведение этапа в соответствии с методическими рекомендациями.' }}</td>
                </tr>
                @php $stNum++; @endphp
            @endforeach
        </tbody>
    </table>

    <table class="table-standard" style="margin-top: 10px;">
        <thead>
            <tr>
                <th style="width: 8%;">Чек</th>
                <th style="width: 42%;">Необходимый реквизит и материалы</th>
                <th style="width: 50%;">Правила безопасности и адаптации</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td style="text-align: center; font-weight: bold; font-size: 10pt;">[ &nbsp; ]</td>
                <td>
                    @if(!empty($props) && is_array($props))
                        <ul style="margin: 0; padding-left: 14px;">
                            @foreach($props as $prop)
                                @if(is_array($prop))
                                    @php
                                        $itemName = $prop['item_name'] ?? ($prop['title'] ?? ($prop['name'] ?? 'Реквизит'));
                                        $itemQty = $prop['quantity'] ?? null;
                                        $itemAlt = $prop['alternative_if_missing'] ?? null;
                                        $isMandatory = $prop['is_mandatory'] ?? true;
                                    @endphp
                                    <li style="margin-bottom: 3px;">
                                        <strong>{{ $itemName }}</strong>
                                        @if($itemQty) &mdash; <em>{{ $itemQty }}</em>@endif
                                        @if($isMandatory) <span style="color: #2563eb; font-size: 7.5pt;">(Обязательно)</span>@else <span style="color: #64748b; font-size: 7.5pt;">(По возможности)</span>@endif
                                        @if($itemAlt)
                                            <br/><small style="color: #64748b;">Альтернатива при отсутствии: {{ $itemAlt }}</small>
                                        @endif
                                    </li>
                                @else
                                    <li>{{ $prop }}</li>
                                @endif
                            @endforeach
                        </ul>
                    @else
                        Стандартный комплект оборудования учебного кабинета (проектор, доска, письменные принадлежности).
                    @endif
                </td>
                <td>
                    @if(!empty($safetyRules) && is_array($safetyRules))
                        @foreach(array_slice($safetyRules, 0, 3) as $rule)
                            <div>• {{ is_array($rule) ? json_encode($rule, JSON_UNESCAPED_UNICODE) : $rule }}</div>
                        @endforeach
                    @else
                        Соблюдение правил безопасного поведения в классе и регламента работы в группах.
                    @endif
                </td>
            </tr>
        </tbody>
    </table>

    <div class="page-break"></div>

    <!-- РАЗДЕЛ 2: КАРТОЧКИ ЗАДАНИЙ ДЛЯ УЧАЩИХСЯ -->
    <div class="header">
        <div class="school-badge">{{ $scenario->school->name ?? 'МАОУ Лицей № 130' }}</div>
        <div class="doc-type">РАЗДАТОЧНЫЙ МАТЕРИАЛ: КАРТОЧКИ ЗАДАНИЙ</div>
        <h1>{{ $scenario->title }}</h1>
    </div>

    <div class="section-title">2. Карточки заданий для малых групп и учащихся</div>

    @php
        $cardItems = $printableCards;
        if (empty($cardItems) && !empty($materialsPayload['printable_cards'])) {
            $cardItems = $materialsPayload['printable_cards'];
        } elseif (empty($cardItems) && !empty($materialsPayload['cards'])) {
            $cardItems = $materialsPayload['cards'];
        }
    @endphp

    @if(!empty($cardItems) && is_array($cardItems))
        @foreach($cardItems as $cIndex => $card)
            @php
                $cTitle = $card['title'] ?? ("Карточка задания №" . ($cIndex + 1));
                $cRole = $card['target_role'] ?? ($card['role'] ?? null);
                $cQty = $card['quantity_per_group'] ?? null;
                $frontText = $card['front_text'] ?? ($card['description'] ?? ($card['task'] ?? ''));
                $backText = $card['back_text'] ?? null;
                $cQuestions = $card['questions'] ?? ($card['items'] ?? []);
            @endphp
            <div class="card-box">
                <div class="cut-marker">-- ✂ Линия разреза карточки --</div>
                <div class="card-title">{{ $cTitle }}</div>
                <div style="font-size: 8pt; color: #475569; margin-bottom: 6px;">
                    @if($cRole)<strong>Назначение / Ролевая группа:</strong> {{ $cRole }} &bull; @endif
                    @if($cQty)<strong>Тираж:</strong> {{ $cQty }} экз. на малую группу@endif
                </div>

                <!-- Лицевая сторона: Задание / Проблемная ситуация -->
                <div style="background-color: #ffffff; border: 1px solid #cbd5e1; border-radius: 4px; padding: 8px; margin-bottom: 6px;">
                    <div style="font-weight: bold; color: #1e3a8a; font-size: 8pt; margin-bottom: 3px;">
                        [ Лицевая сторона: Содержание задания ]
                    </div>
                    <div style="line-height: 1.4;">{{ $frontText }}</div>
                </div>

                <!-- Оборотная сторона: Вопросы / Подсказки -->
                @if($backText || !empty($cQuestions))
                <div style="background-color: #f1f5f9; border: 1px dashed #94a3b8; border-radius: 4px; padding: 8px;">
                    <div style="font-weight: bold; color: #334155; font-size: 8pt; margin-bottom: 3px;">
                        [ Оборотная сторона: Вопросы для формулирования командного решения ]
                    </div>
                    @if($backText)
                        <div style="line-height: 1.4; margin-bottom: 4px;">{{ $backText }}</div>
                    @endif
                    @if(!empty($cQuestions) && is_array($cQuestions))
                        <ul style="margin: 2px 0 0 0; padding-left: 14px;">
                            @foreach($cQuestions as $q)
                                <li>{{ is_array($q) ? json_encode($q, JSON_UNESCAPED_UNICODE) : $q }}</li>
                            @endforeach
                        </ul>
                    @endif
                </div>
                @endif
            </div>
        @endforeach
    @else
        <div class="empty-placeholder">
            Карточки заданий для данного формата мероприятия не предусмотрены в текущей спецификации сценария. 
            Работа строится фронтально или с использованием общих материалов кабинета.
        </div>
    @endif

    <div class="page-break"></div>

    <!-- РАЗДЕЛ 3: РАЗДАТОЧНЫЕ И СПРАВОЧНЫЕ МАТЕРИАЛЫ -->
    <div class="header">
        <div class="school-badge">{{ $scenario->school->name ?? 'МАОУ Лицей № 130' }}</div>
        <div class="doc-type">СПРАВОЧНЫЕ МАТЕРИАЛЫ И МАРШРУТНЫЕ ЛИСТЫ</div>
        <h1>{{ $scenario->title }}</h1>
    </div>

    <div class="section-title">3. Раздаточные материалы и маршрутные листы</div>

    @php
        $handoutItems = $handouts;
        if (empty($handoutItems) && !empty($materialsPayload['handouts'])) {
            $handoutItems = $materialsPayload['handouts'];
        }
    @endphp

    @if(!empty($handoutItems) && is_array($handoutItems))
        @foreach($handoutItems as $hIndex => $handout)
            @php
                $hTitle = $handout['title'] ?? ("Раздаточный материал №" . ($hIndex + 1));
                $hInstructions = $handout['instructions'] ?? '';
                $templateContent = $handout['template_content'] ?? ($handout['content'] ?? ($handout['text'] ?? ''));
            @endphp
            <div style="border: 1px solid #cbd5e1; padding: 10px; margin-bottom: 12px; border-radius: 4px; background: #fff;">
                <div style="font-size: 10pt; font-weight: bold; color: #1e3a8a; margin-bottom: 4px;">{{ $hTitle }}</div>
                @if($hInstructions)
                    <div style="font-size: 8pt; font-style: italic; color: #475569; margin-bottom: 6px;">
                        <strong>Инструкция для малой группы:</strong> {{ $hInstructions }}
                    </div>
                @endif
                
                @if($templateContent)
                    <div style="background-color: #f8fafc; border: 1px dashed #94a3b8; padding: 8px; border-radius: 4px; margin-top: 6px;">
                        <div style="font-weight: bold; font-size: 8pt; color: #334155; margin-bottom: 4px;">
                            Шаблон маршрутного листа (поля для заполнения учащимися):
                        </div>
                        <div style="line-height: 1.4; margin-bottom: 6px; font-style: italic; color: #475569;">
                            {{ $templateContent }}
                        </div>
                        
                        <!-- Бланки для заполнения малой группой -->
                        <table class="table-standard" style="margin-top: 6px; background-color: #fff;">
                            <thead>
                                <tr>
                                    <th style="width: 32%;">Поле маршрутного листа</th>
                                    <th style="width: 68%;">Записи малой группы (заполняется от руки)</th>
                                </tr>
                            </thead>
                            <tbody>
                                @php
                                    $fields = preg_split('/[\/;,\n]+/', str_replace(['Поля:', 'Поля'], '', $templateContent));
                                    $fields = array_filter(array_map('trim', $fields));
                                    if (empty($fields)) {
                                        $fields = ['Проблема / Кейс', 'Идеи группы', 'Выбранное решение', 'Аргументы', 'Роль участников'];
                                    }
                                @endphp
                                @foreach($fields as $fld)
                                    <tr>
                                        <td style="font-weight: bold; background-color: #f8fafc; font-size: 7.5pt;">{{ $fld }}</td>
                                        <td style="height: 26px; color: #94a3b8; font-size: 7.5pt;">[ ................................................................................. ]</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        @endforeach
    @else
        <div class="empty-placeholder">
            Индивидуальные раздаточные тексты для данного сценария не требуются. 
            Используются материалы учебников, первоисточников и презентации.
        </div>
    @endif

    <!-- РАЗДЕЛ 4: СПРАВОЧНЫЙ ЛИСТ ПЕРВОИСТОЧНИКОВ -->
    @php
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
    @endphp

    <div class="section-title" style="margin-top: 16px;">
        4. Справочный лист первоисточников и культурных утверждений
        @if($hasAnyUnverified)
            <span style="font-size: 8pt; font-weight: normal; color: #b45309;">(содержит утверждения, требующие проверки)</span>
        @endif
    </div>
    <p style="margin-bottom: 6px;">Список первоисточников, нормативных актов и культурных утверждений с указанием фактического статуса верификации:</p>

    @if(!empty($claims) && is_array($claims))
        <table class="table-standard">
            <thead>
                <tr>
                    <th style="width: 5%;">№</th>
                    <th style="width: 45%;">Исторический факт / Культурное утверждение</th>
                    <th style="width: 35%;">Первоисточник и цитата</th>
                    <th style="width: 15%;">Статус</th>
                </tr>
            </thead>
            <tbody>
                @php $clIdx = 1; @endphp
                @foreach($claims as $cl)
                    @if(is_array($cl))
                        @php
                            $cText = $cl['claim_text'] ?? ($cl['text'] ?? '');
                            $cStatus = $cl['server_verification_status'] ?? ($cl['status'] ?? 'unverified');
                            $evs = $cl['evidence_records'] ?? [];
                            $hasEvidence = !empty($evs) && is_array($evs);
                            $evStr = [];
                            if ($hasEvidence) {
                                foreach ($evs as $e) {
                                    $evStr[] = ($e['source_title'] ?? 'Источник') . ($e['citation_locator'] ? " ({$e['citation_locator']})" : '');
                                }
                            }
                            $isVerified = ($cStatus === 'verified_authentic' && $hasEvidence);
                        @endphp
                        <tr>
                            <td>{{ $clIdx }}</td>
                            <td>{{ $cText }}</td>
                            <td>{{ !empty($evStr) ? implode('; ', $evStr) : 'Первоисточник не привязан к утверждению' }}</td>
                            <td>
                                @if($isVerified)
                                    <span class="badge badge-verified">&check; Подлинно</span>
                                @elseif($cStatus === 'partially_verified')
                                    <span class="badge badge-unverified">&#9888; Частично</span>
                                @elseif($cStatus === 'rejected_fake')
                                    <span class="badge badge-rejected">&cross; Опровергнуто</span>
                                @else
                                    <span class="badge badge-unverified">&#9888; Требует проверки</span>
                                @endif
                            </td>
                        </tr>
                        @php $clIdx++; @endphp
                    @endif
                @endforeach
            </tbody>
        </table>
    @endif

    <!-- ОТМЕТКА О СОГЛАСОВАНИИ -->
    @if($signoff)
        <div class="signoff-stamp">
            <div style="font-size: 9pt; font-weight: bold; color: #1e3a8a; margin-bottom: 3px;">
                ОТМЕТКА О ДОПУСКЕ МАТЕРИАЛОВ К ПЕЧАТИ И ПРОВЕДЕНИЮ
            </div>
            <div>
                Педагог: <strong>{{ $signoff->user->name ?? 'Педагог-организатор' }}</strong> | 
                Дата допуска: <strong>{{ $signoff->confirmed_at ? $signoff->confirmed_at->format('d.m.Y H:i') : date('d.m.Y H:i') }}</strong> | 
                Чек-лист безопасности и хронометража: <strong>Подтверждён</strong>
            </div>
            <div style="font-size: 7pt; color: #64748b; margin-top: 3px;">
                Provenance Fingerprint: {{ $exportJob->provenance_fingerprint ?? '—' }}
            </div>
        </div>
    @endif

</body>
</html>
