# Архитектура бэкенда «Школьные события»

**Версия:** 1.2 (Фаза 0: Final Contract Fixes)  
**Дата:** 18 октября 2026 г.  
**Проект:** Образовательный кейс «Школьные события» (Сбер × УрФУ)  
**Статус:** Hardened Contract Final (Архитектурное проектирование)

---

## 1. Введение и архитектурные цели

Сервис «Школьные события» предназначен для проектирования, верификации и подготовки методических материалов к школьным мероприятиям на базе федерального и регионального календаря, этнокультурного контекста, традиций школы и педагогических ограничений. 

Главные архитектурные требования:
1. **Трёхконтурная валидация методического сценария:**
   - *Контур 1 (JSON Schema Draft 2020-12):* Строгий синтаксис, типы, запрет самооценки модели, автоматическое согласование `value_direction` и `mechanism_type` через `if/then`.
   - *Контур 2 (Laravel `ScenarioSemanticValidator`):* Проверка уникальности этапов, последовательности `order_index`, ссылок `target_stage_id`, совпадения названия активности механизма и арифметической суммы хронометража.
   - *Контур 3 (Серверный Moderation Engine):* Проверка подлинности фактов по реестру `cultural_claim_sources`, выявление этностереотипов (P0) и нарушений безопасности.
2. **Строгая изоляция школ (Tenant Isolation):** Изоляция на уровне БД через `school_id`, middleware `EnforceTenantIsolation`, запрет передачи `school_id` в телах запросов (ошибка `422`), единый источник истины `school_memberships.is_default`.
3. **Реляционная гарантия Provenance:** Составные внешние ключи `(cultural_claim_id, calendar_event_id)` и `(event_source_id, calendar_event_id)` в таблице `cultural_claim_sources`, физически исключающие прикрепление источника от другого события.
4. **Разделение неизменяемого контента и изменяемого workflow:** Ревизии сценариев строго неизменяемы (append-only). Статусы модерации вынесены в `revision_moderation_states`. Подтверждение педагога вынесено в исторический журнал `teacher_signoffs`.
5. **Чистый SPA-контракт Sanctum (Cookie/Session + CSRF):** Стандартный браузерный вход не создаёт и не возвращает Bearer-токены. Используются HttpOnly SameSite сессии с обязательной регенерацией сессии при входе.

---

## 2. Топология и технологический стек

```
+------------------------------------------------------------------------+
|                      Client Applications                               |
|   - Nuxt 4 / Vue 3 / TS Web Application (Figma Design v1.0)           |
|     (Cookie-based Session Auth + CSRF Protection via Sanctum)          |
|   - External API / CLI (Bearer Token Auth, отдельный шлюз)            |
+------------------------------------------------------------------------+
                                   |
                             HTTPS / JSON REST
                          Base URL: /api/v1
                                   |
+----------------------------------v-------------------------------------+
|                      Nginx Reverse Proxy & TLS                         |
+----------------------------------v-------------------------------------+
|                 Laravel 13 Application (PHP 8.3+ FPM)                  |
|                                                                        |
|  [Middleware Pipeline Order]                                           |
|    1. EncryptCookies                                                   |
|    2. AddQueuedCookiesToResponse                                       |
|    3. StartSession                                                     |
|    4. EnsureFrontendRequestsAreStateful (Sanctum)                      |
|    5. ValidateCsrfToken                                                |
|    6. SubstituteBindings                                               |
|    7. EnforceTenantIsolation (Проверка членства и выбор школы)         |
|    8. Idempotency (Проверка ключа и SHA-256 payload)                   |
|                                                                        |
|  [Domain / Application Modules]                                        |
|    - Identity & Access          - Calendar & Provenance                |
|    - School Traditions          - Scenario Constructor                 |
|    - LLM Engine & Telemetry     - Immutable Revisions & Stages         |
|    - ScenarioSemanticValidator  - Moderation Engine (P0/P1)            |
|    - Human Sign-Off (Teacher)   - Export & Artifacts (PDF)             |
|                                                                        |
|  [Infrastructure Adapters]                                             |
|    - LlmProviderInterface (MockLlmProvider / GigaChat / Qwen)          |
|    - DocumentGeneratorInterface (PDF via Storage Disk)                 |
+------------------------------------------------------------------------+
              |                                            |
              v                                            v
+-----------------------------+              +---------------------------+
|    PostgreSQL 16 Engine     |              |       Redis 7 Cache       |
|  - Native UUID (Str::uuid7) |              |  - Queue Worker (Jobs)    |
|  - Composite Foreign Keys   |              |  - Horizon Monitoring     |
|  - JSONB for Scenarios      |              |  - Idempotency Locks/TTL  |
|  - Separate Workflow Tables |              |  - Rate Limiting          |
+-----------------------------+              +---------------------------+
```

---

## 3. Трёхконтурная семантическая валидация LLM

Для исключения методических и структурных дефектов внедрена трёхконтурная модель проверки:

```
[Сырой JSON от LLM]
       │
       ▼
┌────────────────────────────────────────────────────────┐
│ Контур 1: JSON Schema (generation-output.schema.json) │
│ - Валидность синтаксиса и типов данных                 │
│ - Обязательное присутствие всех 15 разделов            │
│ - minItems >= 1 для всех материалов (слайды, карточки) │
│ - Запрет поля verification_status (additionalProps=f)  │
│ - Совместимость value_direction и mechanism_type (if)  │
└────────────────────────────────────────────────────────┘
       │ (Успех)
       ▼
┌────────────────────────────────────────────────────────┐
│ Контур 2: Laravel ScenarioSemanticValidator            │
│ - Уникальность stages[].id и целостность order_index   │
│ - Наличие target_stage_id в списке этапов              │
│ - Наличие target_activity_title в списке активностей   │
│ - Точное равенство: sum(stage.duration) == total_time  │
│ - Диапазон аудитории: participants_min <= max          │
└────────────────────────────────────────────────────────┘
       │ (Успех)
       ▼
┌────────────────────────────────────────────────────────┐
│ Контур 3: ModerationEngineService & Provenance Gate    │
│ - Сверка cultural_claims с реестром cultural_claim_src │
│ - Проверка статуса источника (status == approved)      │
│ - Проверка актуальности для текущего учебного года     │
│ - Проверка текста на этностереотипы (P0_STEREOTYPE)    │
│ - Вынесение вердикта: blocked / passed_with_warnings   │
└────────────────────────────────────────────────────────┘
```

### Контракт `ScenarioSemanticValidator`:
- **Класс:** `App\Modules\Scenarios\Validators\ScenarioSemanticValidator`
- **Вход:** Декодированный массив данных `array $data`.
- **Выход:** `ScenarioValidationResult` со списком типизированных ошибок:
  - `RULE_STAGE_ID_DUPLICATE`: Обнаружены повторяющиеся `id` этапов.
  - `RULE_STAGE_ORDER_SEQUENCE`: `order_index` должен образовывать строгую непрерывную последовательность $1, 2, \dots, N$.
  - `RULE_ACTIVITY_STAGE_NOT_FOUND`: Активность ссылается на несуществующий `stage_id`.
  - `RULE_MECHANISM_ACTIVITY_NOT_FOUND`: Активность, указанная в механизме воспитания, не найдена в списке `activities`.
  - `RULE_TIMING_SUM_MISMATCH`: Сумма этапов $\sum \text{duration\_minutes}$ не равна заявленной общей длительности `total_duration_minutes`.
  - `RULE_AUDIENCE_RANGE_INVALID`: `participants_count_min` превышает `participants_count_max`.

---

## 4. Реляционная гарантия Provenance источников

Для исключения ситуаций, когда утверждение привязывается к источнику от другого праздника или непроверенному документу:
1. **Составные внешние ключи:**
   Таблица `cultural_claim_sources` связывается с `cultural_claims` и `event_sources` через пару `(id, calendar_event_id)`:
   ```sql
   FOREIGN KEY (cultural_claim_id, calendar_event_id)
       REFERENCES cultural_claims(id, calendar_event_id) ON DELETE CASCADE,
   FOREIGN KEY (event_source_id, calendar_event_id)
       REFERENCES event_sources(id, calendar_event_id) ON DELETE RESTRICT
   ```
   Попытка связать утверждение события А с источником события Б отвергается СУБД на уровне ограничения внешнего ключа (`foreign key violation`).
2. **Требования к источнику для статуса `verified_authentic`:**
   - Источник обязан иметь статус `approved` в журнале `source_reviews`.
   - Поле `citation_locator` обязательно (конкретная страница или архивный лист).
   - Поле `applicable_academic_year` должно соответствовать году сценария (для плавающих дат).
   - Произвольная строка от LLM не принимается: сопоставление идёт по серверному реестру.

---

## 5. Спецификация Sanctum SPA Authentication

Для первого клиента Nuxt 4 SPA реализована сессионная аутентификация:
1. **CSRF-инициализация:** Перед вызовом входа клиент делает `GET /sanctum/csrf-cookie`. Сервер возвращает зашифрованную куку `XSRF-TOKEN`.
2. **Вход (`POST /api/v1/auth/login`):**
   - Проверка учётных данных через стандартный гард `web`: `Auth::guard('web')->attempt(['email' => $email, 'password' => $password])`.
   - **Обязательная регенерация сессии:** `$request->session()->regenerate();` для предотвращения Session Fixation атак.
   - Возврат профиля и доступных школ. **Bearer-токен в браузерной авторизации НЕ создаётся и НЕ возвращается.**
3. **Завершение сессии (`POST /api/v1/auth/logout`):**
   ```php
   Auth::guard('web')->logout();
   $request->session()->invalidate();
   $request->session()->regenerateToken();
   ```
4. **Атрибуты кук и домены:**
   - `SameSite: Lax`, `HttpOnly: true`, `Secure: true` (на HTTPS).
   - `SANCTUM_STATEFUL_DOMAINS=localhost:3000,school.sber-urfu.ru`.
   - `SESSION_DOMAIN=.sber-urfu.ru` (для работы с поддоменами фронтенда).
5. **Обработка статусных кодов в SPA:**
   - `401 Unauthorized`: Сессия завершилась/истекла. Nuxt выполняет редирект на экран авторизации (`59:456`).
   - `419 Authentication Timeout`: Срок действия CSRF-токена истёк. Клиент прозрачно повторяет `GET /sanctum/csrf-cookie` и дублирует исходный запрос.
6. **Поле пароля:** В таблице `users` используется стандартное поле `password varchar(255) NOT NULL`, полностью совместимое с интерфейсом `Illuminate\Contracts\Auth\Authenticatable`.

---

## 6. Правила Tenant Context и валидация DTO

1. **Единый источник истины школы по умолчанию:**
   - Источником служит колонка `is_default` в `school_memberships`.
   - При каждом запросе middleware `EnforceTenantIsolation` находит активное членство пользователя:
     `$membership = $user->memberships()->where('is_default', true)->first() ?? $user->memberships()->first();`
   - Если активного членства нет — ответ `403 Forbidden` (`NO_ACTIVE_SCHOOL_MEMBERSHIP`).
2. **Запрет подмены `school_id` в DTO:**
   - Если клиент передает `school_id` в теле приватного запроса (конструктор, традиция, сценарий), сервер возвращает ошибку `422 Unprocessable Content`:
     ```json
     {
       "error": {
         "code": "TENANT_INJECTION_FORBIDDEN",
         "message": "Передача school_id в теле запроса запрещена. Контекст школы определяется автоматически или через заголовок X-School-Id."
       }
     }
     ```
3. **Выбор повода в `scenario_requests`:**
   - В теле запроса обязан присутствовать **строго один** повод:
     - Либо `calendar_event_id` (календарь);
     - Либо `tradition_version_id` (традиция школы);
     - Либо `custom_event_title` (авторский повод).
   - При 0 или >1 поводов — ошибка валидации `422`.
   - Если указан `calendar_event_id`: проверяется, что `calendar_event.school_id IS NULL` (глобальное) ИЛИ `calendar_event.school_id == TenantContext::getSchoolId()`. Иначе `404 Not Found`.
   - Если указан `tradition_version_id`: проверяется, что традиция принадлежит текущей школе. Иначе `404 Not Found`.
4. **Частичное обновление сценария (`PATCH /scenarios/{id}/revisions/{version}`):**
   - Все поля в DTO опциональны. Пропущенные поля наследуются из текущей ревизии.
   - Параметр `{version}` из URL сравнивается с текущей версией сценария:
     `if ($version !== $scenario->currentRevision->version_number) { throw new StaleRevisionEditConflictException(); }`
     При несовпадении возвращается `409 Conflict`.
