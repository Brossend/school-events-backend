# Модель данных (Data Model) «Школьные события»

**Версия:** 1.2 (Фаза 0: Final Contract Fixes)  
**СУБД:** PostgreSQL 16  
**Генерация UUID:** UUIDv7 на уровне приложения через `Illuminate\Support\Str::uuid7()`  
**Общее число таблиц:** **27 таблиц**  
**Статус:** Hardened Contract Final (Архитектурное проектирование)

---

## 1. Архитектурные принципы схемы

1. **Типы первичных ключей:** Все таблицы используют нативный тип PostgreSQL `uuid`. Значения генерируются в коде Laravel 13 через `Illuminate\Support\Str::uuid7()`. Несуществующая встроенная функция `uuid_generate_v7()` в PostgreSQL 16 **не вызывается**.
2. **Изоляция школ (Multi-tenancy):** Все приватные таблицы содержат `school_id uuid NOT NULL REFERENCES schools(id) ON DELETE RESTRICT`.
3. **Единый источник истины для школы по умолчанию:** Поле `is_default` в таблице `school_memberships`. В таблице `users` поле `default_school_id` устранено, исключая рассинхронизацию.
4. **Реляционная гарантия provenance (Composite FK):** В таблице `cultural_claim_sources` внешние ключи составные:
   `FOREIGN KEY (cultural_claim_id, calendar_event_id) REFERENCES cultural_claims(id, calendar_event_id)` и
   `FOREIGN KEY (event_source_id, calendar_event_id) REFERENCES event_sources(id, calendar_event_id)`.
   Это на уровне СУБД делает **физически невозможным** связать утверждение одного события с источником другого события.
5. **Взаимоисключающий выбор повода в конструкторе:** В таблице `scenario_requests` на уровне `CHECK` гарантируется, что указан ровно один повод (календарь, традиция или собственное название).
6. **Разделение контента и workflow:** Таблица `scenario_revisions` неизменяема (append-only). Статусы модерации вынесены в `revision_moderation_states`. Подтверждения педагога вынесены в исторический журнал `teacher_signoffs`.

---

## 2. Домен Identity & Организации (3 таблицы)

### 2.1. `schools` (Школы / Образовательные организации)
| Поле | Тип | Nullable | Описание / Ограничения |
|---|---|---|---|
| `id` | `uuid` | NO | PK (UUIDv7) |
| `name` | `varchar(255)` | NO | Полное наименование образовательной организации |
| `short_name` | `varchar(100)` | YES | Краткое наименование (напр., «МАОУ Лицей № 130») |
| `region_code` | `varchar(10)` | NO | Код региона РФ (напр., «66» для Свердловской обл.) |
| `city` | `varchar(100)` | NO | Город (напр., «Екатеринбург») |
| `inn` | `varchar(12)` | YES | ИНН школы |
| `settings` | `jsonb` | NO | DEFAULT `'{}'` (брендинг, лимиты) |
| `created_at` | `timestamptz` | NO | DEFAULT `CURRENT_TIMESTAMP` |
| `updated_at` | `timestamptz` | NO | DEFAULT `CURRENT_TIMESTAMP` |

- **Индексы:** `idx_schools_region (region_code, city)`.

### 2.2. `users` (Пользователи системы)
| Поле | Тип | Nullable | Описание / Ограничения |
|---|---|---|---|
| `id` | `uuid` | NO | PK (UUIDv7) |
| `name` | `varchar(255)` | NO | ФИО пользователя |
| `email` | `varchar(255)` | NO | Рабочий email (UNIQUE) |
| `password` | `varchar(255)` | NO | Хэш пароля (стандартное поле Laravel Authenticatable, Bcrypt/Argon2id) |
| `system_role` | `varchar(32)` | NO | `CHECK (system_role IN ('none', 'catalog_curator', 'system_admin'))` DEFAULT `'none'` |
| `curator_scope`| `varchar(16)` | YES | `CHECK (curator_scope IN ('global', 'regional'))` (для куратора) |
| `curated_region_codes`| `jsonb` | NO | DEFAULT `'[]'` (список кодов регионов куратора) |
| `is_active` | `boolean` | NO | DEFAULT `true` |
| `created_at` | `timestamptz` | NO | DEFAULT `CURRENT_TIMESTAMP` |
| `updated_at` | `timestamptz` | NO | DEFAULT `CURRENT_TIMESTAMP` |

- **Индексы:** `UNIQUE (email)`.

### 2.3. `school_memberships` (Членство и роли в школах)
| Поле | Тип | Nullable | Описание / Ограничения |
|---|---|---|---|
| `id` | `uuid` | NO | PK (UUIDv7) |
| `school_id` | `uuid` | NO | FK -> `schools.id` ON DELETE CASCADE |
| `user_id` | `uuid` | NO | FK -> `users.id` ON DELETE CASCADE |
| `role` | `varchar(32)` | NO | `CHECK (role IN ('teacher', 'school_admin'))` |
| `is_default` | `boolean` | NO | DEFAULT `false` (ЕДИНЫЙ источник истины для школы по умолчанию) |
| `created_at` | `timestamptz` | NO | DEFAULT `CURRENT_TIMESTAMP` |
| `updated_at` | `timestamptz` | NO | DEFAULT `CURRENT_TIMESTAMP` |

- **Ограничения:** `UNIQUE (school_id, user_id)` (строго одна активная роль в одной школе).
- **Индексы:** `idx_memberships_user (user_id)`.
- **Частичный уникальный индекс:** `CREATE UNIQUE INDEX uq_user_default_school ON school_memberships (user_id) WHERE is_default = true;` (гарантия СУБД: не более одной школы по умолчанию для каждого пользователя).

---

## 3. Домен Календаря, Источников и Provenance Evidence (6 таблиц)

### 3.1. `calendar_events` (Каталог событий)
| Поле | Тип | Nullable | Описание / Ограничения |
|---|---|---|---|
| `id` | `uuid` | NO | PK (UUIDv7) |
| `school_id` | `uuid` | YES | FK -> `schools.id` ON DELETE CASCADE (NULL = федеральное/региональное) |
| `title` | `varchar(255)` | NO | Название праздника / памятной даты |
| `event_type` | `varchar(32)` | NO | `CHECK (event_type IN ('federal', 'regional', 'cultural_traditional', 'school_custom'))` |
| `region_code` | `varchar(10)` | YES | Код региона для региональных событий (NULL = РФ) |
| `date_type` | `varchar(32)` | NO | `CHECK (date_type IN ('fixed_annual', 'floating_rule', 'single_occurrence'))` |
| `fixed_month` | `smallint` | YES | 1..12 для `fixed_annual` |
| `fixed_day` | `smallint` | YES | 1..31 для `fixed_annual` |
| `description` | `text` | NO | Методическое и контекстное описание |
| `target_age_groups` | `jsonb` | NO | `["primary_1_4", "basic_5_9", "senior_10_11"]` |
| `recommended_value_directions`| `jsonb`| NO | Массив рекомендуемых ценностей |
| `status` | `varchar(32)` | NO | `CHECK (status IN ('draft', 'pending_review', 'approved', 'rejected'))` |
| `created_at` | `timestamptz` | NO | DEFAULT `CURRENT_TIMESTAMP` |
| `updated_at` | `timestamptz` | NO | DEFAULT `CURRENT_TIMESTAMP` |

- **Индексы:** `idx_cal_dates (fixed_month, fixed_day)`, `idx_cal_region (region_code)`, `idx_cal_status (status)`.

### 3.2. `event_occurrences` (Конкретные даты проведения событий)
| Поле | Тип | Nullable | Описание / Ограничения |
|---|---|---|---|
| `id` | `uuid` | NO | PK (UUIDv7) |
| `calendar_event_id` | `uuid` | NO | FK -> `calendar_events.id` ON DELETE CASCADE |
| `academic_year` | `varchar(9)` | NO | Формат `'2026-2027'` |
| `start_date` | `date` | NO | Дата начала |
| `end_date` | `date` | YES | Дата окончания (для фестивалей/декад) |
| `source_reference` | `varchar(500)` | YES | Нормативный акт или приказ на данный год |
| `created_at` | `timestamptz` | NO | DEFAULT `CURRENT_TIMESTAMP` |

- **Ограничения:** `UNIQUE (calendar_event_id, academic_year, start_date)` (поддержка нескольких этапов/дат в году).

### 3.3. `event_sources` (Проверенные источники сведений)
| Поле | Тип | Nullable | Описание / Ограничения |
|---|---|---|---|
| `id` | `uuid` | NO | PK (UUIDv7) |
| `calendar_event_id` | `uuid` | NO | FK -> `calendar_events.id` ON DELETE CASCADE |
| `source_type` | `varchar(32)` | NO | `CHECK (source_type IN ('normative_act', 'scientific_publication', 'ethnographic_archive', 'encyclopedia'))` |
| `title` | `varchar(500)` | NO | Библиографическое наименование |
| `author` | `varchar(255)` | YES | Автор / составитель / учреждение |
| `url` | `varchar(1000)`| YES | Ссылка на первоисточник / архивную запись |
| `doi_or_isbn` | `varchar(64)` | YES | Идентификатор DOI/ISBN |
| `verified_by_user_id`| `uuid`| YES | FK -> `users.id` (эксперт/куратор) |
| `verification_date` | `date` | YES | Дата проверки |
| `created_at` | `timestamptz` | NO | DEFAULT `CURRENT_TIMESTAMP` |

- **Ограничения:** `UNIQUE (id, calendar_event_id)` (для составного внешнего ключа).

### 3.4. `source_reviews` (Журнал модерации источников)
| Поле | Тип | Nullable | Описание / Ограничения |
|---|---|---|---|
| `id` | `uuid` | NO | PK (UUIDv7) |
| `event_source_id` | `uuid` | NO | FK -> `event_sources.id` ON DELETE CASCADE |
| `reviewer_user_id` | `uuid` | NO | FK -> `users.id` ON DELETE RESTRICT |
| `decision` | `varchar(32)` | NO | `CHECK (decision IN ('approved', 'rejected', 'requires_amendment'))` |
| `comments` | `text` | NO | Обоснование решения модератора |
| `reviewed_at` | `timestamptz` | NO | DEFAULT `CURRENT_TIMESTAMP` |

### 3.5. `cultural_claims` (Верифицируемые культурные факты)
| Поле | Тип | Nullable | Описание / Ограничения |
|---|---|---|---|
| `id` | `uuid` | NO | PK (UUIDv7) |
| `calendar_event_id` | `uuid` | NO | FK -> `calendar_events.id` ON DELETE CASCADE |
| `claim_text` | `text` | NO | Точная формулировка традиции / обряда / факта |
| `culture_name` | `varchar(100)` | NO | Название культуры/народа |
| `provenance_notes` | `text` | NO | Описание происхождения |
| `created_at` | `timestamptz` | NO | DEFAULT `CURRENT_TIMESTAMP` |

- **Ограничения:** `UNIQUE (id, calendar_event_id)` (для составного внешнего ключа).

### 3.6. `cultural_claim_sources` (Evidence-таблица подтверждения фактов)
| Поле | Тип | Nullable | Описание / Ограничения |
|---|---|---|---|
| `id` | `uuid` | NO | PK (UUIDv7) |
| `calendar_event_id` | `uuid` | NO | FK -> `calendar_events.id` ON DELETE CASCADE |
| `cultural_claim_id` | `uuid` | NO | FK -> `cultural_claims.id` ON DELETE CASCADE |
| `event_source_id` | `uuid` | NO | FK -> `event_sources.id` ON DELETE RESTRICT |
| `citation_locator` | `varchar(255)`| NO | Конкретная страница, глава, лист архивного дела |
| `citation_excerpt` | `text` | YES | Конкретная выдержка / цитата из текста первоисточника |
| `verification_status`| `varchar(32)` | NO | `CHECK (verification_status IN ('unverified', 'verified_authentic', 'disputed', 'rejected_fake'))` |
| `verified_by_user_id`| `uuid`| YES | FK -> `users.id` (куратор/эксперт) |
| `verified_at` | `timestamptz` | YES | Дата и время проверки |
| `applicable_academic_year`| `varchar(9)`| YES | Применимость к учебному году |
| `notes` | `text` | YES | Экспертные замечания/комментарии куратора (отдельно от цитаты) |
| `created_at` | `timestamptz` | NO | DEFAULT `CURRENT_TIMESTAMP` |

- **Составные внешние ключи:**
  - `FOREIGN KEY (cultural_claim_id, calendar_event_id) REFERENCES cultural_claims(id, calendar_event_id) ON DELETE CASCADE`
  - `FOREIGN KEY (event_source_id, calendar_event_id) REFERENCES event_sources(id, calendar_event_id) ON DELETE RESTRICT`
  *(Гарантирует на уровне БД, что claim и source принадлежат одному и тому же событию).*
- **Ограничения:** `UNIQUE (cultural_claim_id, event_source_id, citation_locator)`.

---

## 4. Домен Школьных Традиций (2 таблицы)

### 4.1. `school_traditions` (Школьные традиции и обычаи)
| Поле | Тип | Nullable | Описание / Ограничения |
|---|---|---|---|
| `id` | `uuid` | NO | PK (UUIDv7) |
| `school_id` | `uuid` | NO | FK -> `schools.id` ON DELETE RESTRICT |
| `title` | `varchar(255)` | NO | Название традиции |
| `description` | `text` | NO | Описание и история традиции |
| `established_year` | `smallint`| YES | Год основания |
| `created_by_user_id` | `uuid` | NO | FK -> `users.id` ON DELETE RESTRICT |
| `created_at` | `timestamptz` | NO | DEFAULT `CURRENT_TIMESTAMP` |
| `updated_at` | `timestamptz` | NO | DEFAULT `CURRENT_TIMESTAMP` |

- **Индексы:** `idx_traditions_school (school_id)`.

### 4.2. `tradition_versions` (Ежегодные версии традиций)
| Поле | Тип | Nullable | Описание / Ограничения |
|---|---|---|---|
| `id` | `uuid` | NO | PK (UUIDv7) |
| `tradition_id` | `uuid` | NO | FK -> `school_traditions.id` ON DELETE CASCADE |
| `academic_year` | `varchar(9)` | NO | Учебный год `'2026-2027'` |
| `parameters_snapshot`| `jsonb`| NO | Снимок параметров проведения |
| `notes` | `text` | YES | Инновации версии текущего года |
| `created_by_user_id` | `uuid` | NO | FK -> `users.id` ON DELETE RESTRICT |
| `created_at` | `timestamptz` | NO | DEFAULT `CURRENT_TIMESTAMP` |

- **Ограничения:** `UNIQUE (tradition_id, academic_year)`.

---

## 5. Домен Конструктора и Телеметрии LLM (3 таблицы)

### 5.1. `scenario_requests` (Параметры генерации сценария)
| Поле | Тип | Nullable | Описание / Ограничения |
|---|---|---|---|
| `id` | `uuid` | NO | PK (UUIDv7) |
| `school_id` | `uuid` | NO | FK -> `schools.id` ON DELETE RESTRICT |
| `user_id` | `uuid` | NO | FK -> `users.id` ON DELETE RESTRICT (автор-педагог) |
| `calendar_event_id` | `uuid`| YES | FK -> `calendar_events.id` |
| `tradition_version_id`| `uuid`| YES | FK -> `tradition_versions.id` |
| `custom_event_title` | `varchar(255)`| YES | Свое название |
| `scale` | `varchar(32)` | NO | `CHECK (scale IN ('class_level', 'all_school', 'family_community'))` |
| `format_type` | `varchar(64)` | NO | `CHECK (format_type IN ('class_hour', 'quest', 'quiz', 'workshop', 'festival', 'roundtable'))` |
| `target_grades` | `jsonb` | NO | `[5, 6]` |
| `participants_count` | `integer` | NO | Число участников |
| `duration_minutes` | `integer` | NO | Заявленная длительность (20..240) |
| `value_direction` | `varchar(32)` | NO | `CHECK (value_direction IN ('family', 'mutual_aid', 'historical_memory', 'labor_and_creation', 'cultural_diversity', 'civic_responsibility'))` |
| `family_involvement` | `boolean` | NO | DEFAULT `false` |
| `available_resources`| `jsonb` | NO | DEFAULT `'[]'` |
| `pedagogical_constraints`| `text`| YES | Ограничения |
| `context_snapshot` | `jsonb` | NO | Неизменяемый снимок проверенных источников |
| `created_at` | `timestamptz` | NO | DEFAULT `CURRENT_TIMESTAMP` |

- **Ограничение выбора повода:**
  `CHECK ((CASE WHEN calendar_event_id IS NOT NULL THEN 1 ELSE 0 END + CASE WHEN tradition_version_id IS NOT NULL THEN 1 ELSE 0 END + CASE WHEN custom_event_title IS NOT NULL THEN 1 ELSE 0 END) = 1)`
  *(Строго один повод: календарь, традиция или собственное название).*
- **Индексы:** `idx_requests_school (school_id, user_id, created_at DESC)`.

### 5.2. `generation_runs` (Служебные запуски LLM и телеметрия)
| Поле | Тип | Nullable | Описание / Ограничения |
|---|---|---|---|
| `id` | `uuid` | NO | PK (UUIDv7) |
| `scenario_request_id` | `uuid` | NO | FK -> `scenario_requests.id` ON DELETE CASCADE |
| `provider_name` | `varchar(64)` | NO | `'mock'`, `'gigachat'`, `'qwen'`, `'gpt6_luna'` |
| `model_identifier` | `varchar(64)` | NO | Идентификатор модели |
| `prompt_version` | `varchar(32)` | NO | Версия шаблона промпта |
| `schema_version` | `varchar(32)` | NO | Версия JSON Schema |
| `status` | `varchar(32)` | NO | `CHECK (status IN ('queued', 'running', 'completed', 'failed', 'timed_out'))` |
| `attempts` | `smallint` | NO | DEFAULT 1 |
| `latency_ms` | `integer` | YES | Время ответа модели |
| `prompt_tokens` | `integer` | YES | Токены промпта |
| `completion_tokens` | `integer` | YES | Токены ответа |
| `error_code` | `varchar(64)` | YES | Код ошибки (`TIMEOUT`, `SCHEMA_VIOLATION`, etc.) |
| `error_message` | `text` | YES | Санитизированный текст ошибки (без секретов) |
| `sanitized_prompt` | `text` | YES | Очищенный промпт (без токенов и ПДн) |
| `sanitized_response`| `jsonb` | YES | Полученный JSON |
| `created_at` | `timestamptz` | NO | DEFAULT `CURRENT_TIMESTAMP` |
| `completed_at` | `timestamptz` | YES | Время завершения |

### 5.3. `idempotency_keys` (Защита от дублей запросов)
| Поле | Тип | Nullable | Описание / Ограничения |
|---|---|---|---|
| `id` | `uuid` | NO | PK (UUIDv7) |
| `key` | `varchar(64)` | NO | Клиентский заголовок Idempotency-Key |
| `user_id` | `uuid` | NO | FK -> `users.id` ON DELETE CASCADE |
| `endpoint` | `varchar(255)`| NO | URI эндпоинта |
| `payload_sha256` | `varchar(64)` | NO | Хэш тела входящего запроса |
| `status` | `varchar(32)` | NO | `CHECK (status IN ('processing', 'completed', 'failed'))` |
| `response_code` | `integer` | YES | Код ответа HTTP |
| `response_body` | `jsonb` | YES | Кэшированное тело ответа |
| `expires_at` | `timestamptz` | NO | Срок жизни ключа (24 часа) |
| `created_at` | `timestamptz` | NO | DEFAULT `CURRENT_TIMESTAMP` |

- **Ограничения:** `UNIQUE (user_id, endpoint, key)`.

---

## 6. Домен Сценариев и Ревизий (5 таблиц)

### 6.1. `scenarios` (Контейнер сценария мероприятия)
| Поле | Тип | Nullable | Описание / Ограничения |
|---|---|---|---|
| `id` | `uuid` | NO | PK (UUIDv7) |
| `school_id` | `uuid` | NO | FK -> `schools.id` ON DELETE RESTRICT |
| `user_id` | `uuid` | NO | FK -> `users.id` ON DELETE RESTRICT (автор) |
| `scenario_request_id`| `uuid` | NO | FK -> `scenario_requests.id` ON DELETE RESTRICT (1:1) |
| `title` | `varchar(255)` | NO | Заголовок сценария |
| `current_revision_id`| `uuid` | YES | FK -> `scenario_revisions.id` |
| `optimistic_lock_version`| `integer`| NO | DEFAULT 1 (счетчик оптимистичной блокировки) |
| `is_archived` | `boolean` | NO | DEFAULT `false` |
| `created_at` | `timestamptz` | NO | DEFAULT `CURRENT_TIMESTAMP` |
| `updated_at` | `timestamptz` | NO | DEFAULT `CURRENT_TIMESTAMP` |

- **Ограничения:** `UNIQUE (scenario_request_id)`.

### 6.2. `scenario_revisions` (Неизменяемые редакции сценария)
| Поле | Тип | Nullable | Описание / Ограничения |
|---|---|---|---|
| `id` | `uuid` | NO | PK (UUIDv7) |
| `scenario_id` | `uuid` | NO | FK -> `scenarios.id` ON DELETE CASCADE |
| `version_number` | `integer` | NO | Номер редакции (1, 2, 3...) |
| `source_generation_run_id`| `uuid`| YES | FK -> `generation_runs.id` |
| `created_by_user_id`| `uuid` | NO | FK -> `users.id` |
| `content_payload` | `jsonb` | NO | Канонический неизменяемый снимок полного нормализованного методического сценария (`scenario.schema.json`) без потери полей |
| `concept` | `text` | NO | Концепция |
| `pedagogical_goal` | `text` | NO | Воспитательная цель |
| `pedagogical_mechanism`| `jsonb`| NO | Воспитательный механизм из ТЗ |
| `pedagogical_tasks` | `jsonb` | NO | Личностные, метапредметные, предметные задачи |
| `expected_outcomes` | `jsonb` | NO | Ожидаемые результаты |
| `target_audience` | `jsonb` | NO | Аудитория |
| `format` | `jsonb` | NO | Формат |
| `total_duration_minutes`| `integer`| NO | Общая длительность |
| `teacher_instructions` | `jsonb` | NO | Инструкции и безопасность |
| `created_at` | `timestamptz` | NO | DEFAULT `CURRENT_TIMESTAMP` |

- **Ограничения:** `UNIQUE (scenario_id, version_number)`.
- **Правило:** Таблица **строго неизменяема (Append-only)**.

### 6.3. `scenario_stages` (Нормализованные этапы сценария)
| Поле | Тип | Nullable | Описание / Ограничения |
|---|---|---|---|
| `id` | `uuid` | NO | PK (UUIDv7) |
| `revision_id` | `uuid` | NO | FK -> `scenario_revisions.id` ON DELETE CASCADE |
| `order_index` | `smallint` | NO | Порядковый номер (1..N) |
| `stage_identifier` | `varchar(64)` | NO | Идентификатор этапа (напр. `'stage_intro'`) |
| `title` | `varchar(255)` | NO | Название этапа |
| `duration_minutes` | `integer` | NO | Длительность этапа |
| `stage_type` | `varchar(64)` | NO | Тип этапа |
| `teacher_actions` | `text` | NO | Реплики и действия ведущего |
| `student_actions` | `text` | NO | Деятельность учащихся |
| `dialogue_prompts` | `jsonb` | NO | Вопросы для диалога |
| `teacher_tips` | `text` | YES | Методические подсказки |
| `transition_text` | `text` | YES | Фраза-мостик |

- **Ограничения:** `UNIQUE (revision_id, order_index)`.

### 6.4. `scenario_materials` (Материалы, слайды и раздатка)
| Поле | Тип | Nullable | Описание / Ограничения |
|---|---|---|---|
| `id` | `uuid` | NO | PK (UUIDv7) |
| `revision_id` | `uuid` | NO | FK -> `scenario_revisions.id` ON DELETE CASCADE |
| `material_type` | `varchar(32)` | NO | `CHECK (material_type IN ('presentation_slide', 'printable_card', 'handout_sheet', 'props_checklist'))` |
| `title` | `varchar(255)` | NO | Заголовок |
| `order_index` | `smallint` | NO | Порядок показа / печати |
| `content` | `jsonb` | NO | Содержимое материала |
| `created_at` | `timestamptz` | NO | DEFAULT `CURRENT_TIMESTAMP` |

### 6.5. `revision_moderation_states` (Состояние процесса модерации ревизии)
| Поле | Тип | Nullable | Описание / Ограничения |
|---|---|---|---|
| `revision_id` | `uuid` | NO | PK, FK -> `scenario_revisions.id` ON DELETE CASCADE |
| `status` | `varchar(32)` | NO | `CHECK (status IN ('pending_verification', 'running', 'verification_blocked', 'verification_passed_with_warnings', 'verification_passed', 'failed_engine'))` |
| `p0_violations_count`| `integer` | NO | DEFAULT 0 |
| `p1_violations_count`| `integer` | NO | DEFAULT 0 |
| `latest_verification_run_id`| `uuid`| YES | FK -> `verification_runs.id` |
| `updated_at` | `timestamptz` | NO | DEFAULT `CURRENT_TIMESTAMP` |

*(Обратите внимание: статус `signed_off` исключён из перечисления, так как подтверждение педагога вынесено в отдельный журнал `teacher_signoffs`).*

---

## 7. Домен Модерации и Sign-Off (4 таблицы)

### 7.1. `verification_runs` (Запуски автоматической проверки качества)
| Поле | Тип | Nullable | Описание / Ограничения |
|---|---|---|---|
| `id` | `uuid` | NO | PK (UUIDv7) |
| `revision_id` | `uuid` | NO | FK -> `scenario_revisions.id` ON DELETE CASCADE |
| `rule_engine_version`| `varchar(32)`| NO | Версия правил |
| `status` | `varchar(32)` | NO | `CHECK (status IN ('running', 'passed', 'passed_with_warnings', 'blocked', 'failed_engine'))` |
| `p0_violations_count`| `integer` | NO | DEFAULT 0 |
| `p1_violations_count`| `integer` | NO | DEFAULT 0 |
| `execution_time_ms` | `integer` | YES | Время работы проверки |
| `created_at` | `timestamptz` | NO | DEFAULT `CURRENT_TIMESTAMP` |

- **Правило хранения истории:** Таблица `verification_runs` является строго **Append-only**. При повторных запусках модерации ревизии прежние запуски и их `verification_findings` никогда не удаляются и не перезаписываются, обеспечивая полный аудиторский след. В `revision_moderation_states` обновляется ссылка `latest_verification_run_id`.

### 7.2. `verification_findings` (Детальные замечания проверки)
| Поле | Тип | Nullable | Описание / Ограничения |
|---|---|---|---|
| `id` | `uuid` | NO | PK (UUIDv7) |
| `verification_run_id`| `uuid` | NO | FK -> `verification_runs.id` ON DELETE CASCADE |
| `severity` | `varchar(8)` | NO | `CHECK (severity IN ('P0', 'P1'))` |
| `rule_code` | `varchar(64)` | NO | Код правила (`P0_STEREOTYPE`, `P0_FABRICATED_CULTURE`, `P1_TIMING_MISMATCH`) |
| `target_path` | `varchar(255)`| NO | JSON-путь нарушения |
| `message` | `text` | NO | Описание проблемы |
| `suggested_fix` | `text` | YES | Предложение по исправлению |
| `status` | `varchar(32)` | NO | `CHECK (status IN ('open', 'resolved', 'acknowledged_warning'))` |
| `created_at` | `timestamptz` | NO | DEFAULT `CURRENT_TIMESTAMP` |

### 7.3. `teacher_signoffs` (Исторический журнал подтверждений педагога)
| Поле | Тип | Nullable | Описание / Ограничения |
|---|---|---|---|
| `id` | `uuid` | NO | PK (UUIDv7) |
| `revision_id` | `uuid` | NO | FK -> `scenario_revisions.id` ON DELETE CASCADE |
| `user_id` | `uuid` | NO | FK -> `users.id` ON DELETE RESTRICT (педагог) |
| `is_confirmed` | `boolean` | NO | Строго `true` |
| `checklist_confirmed`| `jsonb` | NO | Чек-лист подтверждения |
| `teacher_notes` | `text` | YES | Комментарий педагога |
| `ip_address` | `varchar(45)` | YES | Аудит-лог IP-адреса |
| `confirmed_at` | `timestamptz` | NO | Время подтверждения |
| `created_at` | `timestamptz` | NO | DEFAULT `CURRENT_TIMESTAMP` |

- **Ограничения:** `UNIQUE (revision_id, user_id)`.

---

## 8. Домен Экспорта и Артефактов (2 таблицы)

### 8.1. `export_jobs` (Задачи компиляции методических материалов)
| Поле | Тип | Nullable | Описание / Ограничения |
|---|---|---|---|
| `id` | `uuid` | NO | PK (UUIDv7) |
| `revision_id` | `uuid` | NO | FK -> `scenario_revisions.id` ON DELETE CASCADE |
| `user_id` | `uuid` | NO | FK -> `users.id` ON DELETE RESTRICT |
| `format` | `varchar(16)` | NO | `CHECK (format IN ('pdf', 'docx', 'slides_pdf'))` |
| `status` | `varchar(32)` | NO | `CHECK (status IN ('queued', 'processing', 'ready', 'failed'))` |
| `error_message` | `text` | YES | Ошибка сборщика |
| `created_at` | `timestamptz` | NO | DEFAULT `CURRENT_TIMESTAMP` |
| `completed_at` | `timestamptz` | YES | Время завершения |

### 8.2. `exported_artifacts` (Скомпилированные файлы)
| Поле | Тип | Nullable | Описание / Ограничения |
|---|---|---|---|
| `id` | `uuid` | NO | PK (UUIDv7) |
| `export_job_id` | `uuid` | NO | FK -> `export_jobs.id` ON DELETE CASCADE |
| `file_disk` | `varchar(32)` | NO | `'local'`, `'s3'` |
| `file_path` | `varchar(1000)`| NO | Приватный путь на диске |
| `file_name` | `varchar(255)` | NO | Имя файла для скачивания |
| `file_size_bytes` | `bigint` | NO | Размер в байтах |
| `mime_type` | `varchar(100)` | NO | MIME-тип |
| `sha256_hash` | `varchar(64)` | NO | Хэш-сумма файла |
| `expires_at` | `timestamptz` | YES | Дата автоочистки временного файла |
| `created_at` | `timestamptz` | NO | DEFAULT `CURRENT_TIMESTAMP` |

---

## 9. Домен Пилота (3 таблицы)

### 9.1. `pilot_participants` (Псевдонимизированные участники пилота)
| Поле | Тип | Nullable | Описание / Ограничения |
|---|---|---|---|
| `id` | `uuid` | NO | PK (UUIDv7) |
| `school_id` | `uuid` | NO | FK -> `schools.id` ON DELETE RESTRICT |
| `anonymous_code` | `varchar(64)` | NO | Псевдоним (напр. `'TEACHER-URFU-014'`) (UNIQUE) |
| `invite_token_hash`| `varchar(64)`| NO | Хэш одноразового пригласительного токена |
| `subject_area` | `varchar(100)` | NO | Предметная область |
| `teaching_experience_years`| `smallint`| NO | Стаж работы |
| `is_submitted` | `boolean` | NO | DEFAULT `false` |
| `created_at` | `timestamptz` | NO | DEFAULT `CURRENT_TIMESTAMP` |

### 9.2. `pilot_responses` (Метрики эффективности подготовки)
| Поле | Тип | Nullable | Описание / Ограничения |
|---|---|---|---|
| `id` | `uuid` | NO | PK (UUIDv7) |
| `participant_id` | `uuid` | NO | FK -> `pilot_participants.id` ON DELETE CASCADE (1:1) |
| `scenario_id` | `uuid` | NO | FK -> `scenarios.id` ON DELETE RESTRICT |
| `prep_time_baseline_minutes`| `integer`| NO | Базовое время без ИИ в минутах |
| `prep_time_ai_minutes` | `integer` | NO | Время с ИИ в минутах |
| `relative_time_saving` | `numeric(5,4)`| NO | Относительное сокращение: `(T_base - T_ai) / T_base` |
| `personalization_selected`| `boolean` | NO | Выбран персонализированный вариант |
| `manual_edit_percentage` | `numeric(5,2)`| NO | Процент ручной доработки (0.00%..100.00%) |
| `cultural_context_used` | `boolean` | NO | Использован этнокультурный контекст |
| `cultural_usefulness_score`| `smallint`| NO | Оценка полезности (1..5) |
| `feedback_text` | `text` | YES | Текстовый отзыв |
| `created_at` | `timestamptz` | NO | DEFAULT `CURRENT_TIMESTAMP` |

- **Ограничения:** `UNIQUE (participant_id)`.

### 9.3. `pilot_review_findings` (Экспертная оценка культурной чистоты)
| Поле | Тип | Nullable | Описание / Ограничения |
|---|---|---|---|
| `id` | `uuid` | NO | PK (UUIDv7) |
| `pilot_response_id` | `uuid` | NO | FK -> `pilot_responses.id` ON DELETE CASCADE |
| `expert_name` | `varchar(255)` | NO | ФИО эксперта-этнографа |
| `expert_audit_status`| `varchar(32)` | NO | `CHECK (expert_audit_status IN ('pending_audit', 'audited'))` |
| `critical_cultural_errors`| `integer` | NO | Количество P0-ошибок (цель по ТЗ: строго 0) |
| `methodological_remarks`| `text` | YES | Замечания эксперта |
| `reviewed_at` | `timestamptz` | YES | Дата проведения экспертизы |
