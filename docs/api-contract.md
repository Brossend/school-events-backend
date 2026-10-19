# Спецификация REST API v1 «Школьные события»

**Версия:** 1.2 (Фаза 0: Final Contract Fixes)  
**Базовый URL:** `/api/v1`  
**Формат обмена:** JSON (`Content-Type: application/json`, `Accept: application/json`)  
**Основной метод аутентификации (Nuxt 4 SPA):** Cookie-based сессионная аутентификация Laravel Sanctum с CSRF-защитой  
**Изоляция школы (Tenant Header):** `X-School-Id: <uuid>` (передается для переключения между доступными школами; проверяется на сервере)

---

## 1. Общие стандарты протокола и безопасность

### 1.1. Аутентификация для Nuxt 4 SPA (Cookie/CSRF Flow)
Для взаимодействия с браузерным приложением Nuxt 4 используется сессионный механизм Sanctum:
1. **Инициализация CSRF:** Перед отправкой запроса на вход клиент запрашивает:
   `GET /sanctum/csrf-cookie`
   Сервер выставляет CSRF-куку `XSRF-TOKEN`. Клиент передает её значение в заголовке `X-XSRF-TOKEN`.
2. **Аутентификация:** Запрос `POST /api/v1/auth/login`. При успехе сервер выполняет `$request->session()->regenerate()` и возвращает сессионную куку `laravel_session`. **Bearer-токен для браузерного входа НЕ выдаётся.**
3. **Требования к Origin:** В `.env` настраиваются `SANCTUM_STATEFUL_DOMAINS` (напр., `localhost:3000`, `school-events.sber-urfu.ru`) и `SESSION_DOMAIN`.
4. **Завершение сессии:** `POST /api/v1/auth/logout` уничтожает серверную сессию, инвалидирует CSRF-токен и очищает куку.
5. **Изоляция школы и запрет инъекции `school_id`:**
   - Выбор школы осуществляется строго через заголовок `X-School-Id: <uuid>` или дефолтное членство пользователя.
   - Попытка передачи поля `school_id` в теле любого приватного запроса приводит к немедленному ответу `422 Unprocessable Content` с кодом `TENANT_INJECTION_FORBIDDEN`. Клиенту запрещено явно передавать идентификатор тенанта в теле запроса.
6. **Обработка статусных кодов в Nuxt SPA:**
   - `401 Unauthorized`: Сессия завершилась/истекла. Фронтенд очищает стейт пользователя и перенаправляет на экран авторизации (`59:456`).
   - `419 Authentication Timeout`: Срок действия CSRF-токена истёк. Фронтенд автоматически вызывает `GET /sanctum/csrf-cookie` и повторяет последний запрос без вылогинивания пользователя.

### 1.2. Стандартные конверты ответов (Response Envelopes)

#### Одиночный ресурс (200 OK / 201 Created)
```json
{
  "data": {
    "id": "018d9b1c-7f28-7654-a312-d81234567890",
    "title": "День народного единства: Уральские традиции",
    "created_at": "2026-10-18T15:30:00Z"
  }
}
```

#### Список с пагинацией (200 OK)
```json
{
  "data": [
    {
      "id": "018d9b1c-7f28-7654-a312-d81234567890",
      "title": "День народного единства"
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 4,
    "per_page": 20,
    "total": 76
  },
  "links": {
    "first": "/api/v1/calendar-events?page=1",
    "last": "/api/v1/calendar-events?page=4",
    "prev": null,
    "next": "/api/v1/calendar-events?page=2"
  }
}
```

#### Стандартный конверт ошибки (4xx / 5xx)
```json
{
  "error": {
    "code": "VALIDATION_FAILED",
    "message": "Переданные данные не прошли валидацию.",
    "details": [
      {
        "field": "duration_minutes",
        "message": "Продолжительность мероприятия должна быть кратна 5 минутам и не превышать 240 минут."
      }
    ],
    "trace_id": "req-9f2a620d-7a11"
  }
}
```

---

## 2. Идемпотентность и Optimistic Locking

### 2.1. Контракт идемпотентности
Для операций генерации сценариев и экспорта клиент передаёт заголовок:
`Idempotency-Key: <uuidv4>`
- Сервер вычисляет SHA-256 от тела запроса.
- Если ключ уже существует, но `payload_sha256` отличается, возвращается `422 Unprocessable Content` (`IDEMPOTENCY_PAYLOAD_MISMATCH`).
- Если запрос ещё выполняется воркером, возвращается `409 Conflict` или текущий статус задачи `202 Accepted` со ссылкой на опрос.

### 2.2. Optimistic Locking и проверка ревизий
При сохранении правок в ревизию сценария клиент передаёт:
- Заголовок `If-Match: "<optimistic_lock_version>"`.
- Параметр `{version}` в URL.
- **Проверка актуальности версии:** Если переданный в URL номер версии не совпадает с актуальной версией сценария (`version != current_revision.version_number`), сервер возвращает `409 Conflict`:
  ```json
  {
    "error": {
      "code": "STALE_REVISION_EDIT_FORBIDDEN",
      "message": "Вы пытаетесь отредактировать устаревшую ревизию. Пожалуйста, обновите страницу для получения актуальной версии.",
      "current_version": 3
    }
  }
  ```

---

## 3. Реестр конечных точек (Endpoints)

### 3.1. Аутентификация и сессия (`/auth`)

#### `POST /api/v1/auth/login`
- **Доступ:** Публичный (защищён Rate Limiter: 5 попыток в минуту на IP)
- **Тело запроса:**
  ```json
  {
    "email": "teacher@school130.ru",
    "password": "SecurePassword2026!"
  }
  ```
- **Ответ 200 OK (сессионная кука `laravel_session` выставляется в заголовок Set-Cookie):**
  ```json
  {
    "data": {
      "user": {
        "id": "018d9b1c-7f28-7654-a312-d81234567890",
        "name": "Елена Сергеевна Смирнова",
        "email": "teacher@school130.ru",
        "system_role": "none"
      },
      "schools": [
        {
          "school_id": "018d9b1c-8888-7654-a312-d81234567890",
          "name": "МАОУ Лицей № 130 г. Екатеринбург",
          "role": "teacher",
          "is_default": true
        }
      ]
    }
  }
  ```

#### `POST /api/v1/auth/logout`
- **Доступ:** Авторизованный
- **Ответ 204 No Content**

#### `GET /api/v1/auth/me`
- **Доступ:** Авторизованный
- **Ответ 200 OK:** Профиль пользователя, системная роль и список членств в школах.

#### `GET /api/v1/tenant/current`
- **Доступ:** Авторизованный пользователь с активным членством в образовательной организации
- **Заголовки:** Опционально `X-School-Id: <uuid>` (для явного переключения школы)
- **Назначение:** Получение контекста активной школы пользователя для инициализации сессии фронтенда и применения школьных настроек.
- **Ошибки:**
  - `401 Unauthorized`: Сессия отсутствует.
  - `403 Forbidden`: `NO_ACTIVE_SCHOOL_MEMBERSHIP` (пользователь без школ) или `SYSTEM_USER_TENANT_FORBIDDEN` (системный пользователь без членства в школе).
  - `422 Unprocessable Content`: `SCHOOL_SELECTION_REQUIRED` (несколько школ без установленной школы по умолчанию).
- **Ответ 200 OK:**
  ```json
  {
    "data": {
      "school_id": "018d9b1c-8888-7654-a312-d81234567890",
      "school_name": "МАОУ Лицей № 130 г. Екатеринбург",
      "role": "teacher"
    }
  }
  ```

#### `GET /api/v1/system/status`
- **Доступ:** Системные пользователи (`system_admin`, `catalog_curator`)
- **Назначение:** Проверка прав доступа к системной консоли управления и текущей системной роли.
- **Ошибки:** `403 Forbidden` (`FORBIDDEN_SYSTEM_ACCESS`) для обычных пользователей школ.
- **Ответ 200 OK:**
  ```json
  {
    "data": {
      "system_status": "operational",
      "system_role": "system_admin"
    }
  }
  ```

#### `POST /api/v1/auth/forgot-password`
- **Доступ:** Публичный
- **Тело:** `{ "email": "any-teacher@school.ru" }`
- **Ответ 200 OK:**
  ```json
  {
    "data": {
      "message": "Если указанный адрес зарегистрирован, ссылка для восстановления пароля отправлена на почту."
    }
  }
  ```

---

### 3.2. Календарь, даты и источники (`/calendar-events`)

#### `GET /api/v1/calendar-events`
- **Доступ:** Любой авторизованный пользователь
- **Query-параметры:**
  - `region_code`: код региона (напр. `66`)
  - `from`, `to`: диапазон дат проведения (ГГГГ-ММ-ДД)
  - `type`: `federal`, `regional`, `cultural_traditional`, `school_custom`
  - `q`: строка поиска
  - `page`, `per_page`: параметры пагинации
- **Ответ 200 OK:** Список событий с расписанием `occurrences` на текущий учебный год.

#### `GET /api/v1/calendar-events/{id}`
- **Доступ:** Любой авторизованный пользователь
- **Ответ 200 OK:** Полные сведения о событии, проверенные этнографические источники (`event_sources`) и подтвержденные культурные утверждения (`cultural_claims`) с доказательной базой (`evidence_records`).

#### `POST /api/v1/calendar-events`
- **Доступ:** `catalog_curator` (для федеральных/региональных), `school_admin` (только для своей школы `school_custom`)
- **Тело:** Свойства события. При попытке передать чужой `school_id` — отказ `422/403`.
- **Ответ 201 Created**.

---

### 3.3. Школьные традиции (`/schools/{schoolId}/traditions`, `/traditions/{id}/versions`)

#### `GET /api/v1/schools/{schoolId}/traditions`
- **Доступ:** Педагоги и администраторы данной школы
- **Ответ 200 OK:** Список многолетних традиций школы с версиями.

#### `POST /api/v1/schools/{schoolId}/traditions`
- **Доступ:** Педагог / администратор школы
- **Тело:** Название, описание и год основания традиции.
- **Ответ 201 Created**.

#### `POST /api/v1/traditions/{id}/versions`
- **Доступ:** Педагог / администратор школы
- **Назначение:** Создание версии традиции на новый учебный год без перезаписи прошлогодней.
- **Ответ 201 Created**.

---

### 3.4. Конструктор и генерация сценариев

#### `POST /api/v1/scenario-requests`
- **Доступ:** Педагог школы (`role: teacher`)
- **Правило повода:** Обязан быть указан **строго один** повод (`calendar_event_id`, `tradition_version_id` или `custom_event_title`).
- **Защита Tenant:** Передача `school_id` в теле запрещена и вызывает ошибку `422 Unprocessable Content` (`TENANT_INJECTION_FORBIDDEN`).
- **Тело запроса:**
  ```json
  {
    "calendar_event_id": "018d9b1c-7f28-7654-a312-d81234567890",
    "tradition_version_id": null,
    "custom_event_title": null,
    "scale": "class_level",
    "format_type": "class_hour",
    "target_grades": [5, 6],
    "participants_count": 28,
    "duration_minutes": 45,
    "value_direction": "cultural_diversity",
    "family_involvement": false,
    "available_resources": ["интерактивная_доска", "рабочие_тетради"],
    "pedagogical_constraints": "Избегать мелкого шрифта на слайдах"
  }
  ```
- **Ответ 201 Created:** Снимок контекста `context_snapshot` фиксируется на сервере.

#### `POST /api/v1/scenario-requests/{id}/generate`
- **Доступ:** Автор запроса
- **Заголовки:** `Idempotency-Key: <uuidv4>`
- **Ответ 202 Accepted:**
  ```json
  {
    "data": {
      "generation_run_id": "018d9b2e-9999-7654-a312-d81234567890",
      "status": "queued",
      "estimated_wait_seconds": 15,
      "poll_url": "/api/v1/generation-runs/018d9b2e-9999-7654-a312-d81234567890"
    }
  }
  ```

#### `GET /api/v1/generation-runs/{id}`
- **Доступ:** Автор запроса
- **Ответ 200 OK (завершено):**
  ```json
  {
    "data": {
      "id": "018d9b2e-9999-7654-a312-d81234567890",
      "status": "completed",
      "scenario_id": "018d9b3a-1111-7654-a312-d81234567890",
      "revision_id": "018d9b3b-2222-7654-a312-d81234567890",
      "moderation_status": "pending_verification"
    }
  }
  ```

---

### 3.5. Сценарии и частичное редактирование

#### `GET /api/v1/scenarios`
- **Доступ:** Пользователи текущей школы
- **Ответ 200 OK:** Список сценариев текущей школы («Мои мероприятия»).

#### `GET /api/v1/scenarios/{id}`
- **Доступ:** Пользователи текущей школы
- **Ответ 200 OK:** Карточка сценария с актуальной ревизией, этапами, материалами и состоянием модерации.

#### `PATCH /api/v1/scenarios/{id}/revisions/{version}`
- **Доступ:** Автор сценария / администратор школы
- **Заголовки:** `If-Match: "2"`
- **Правило частичного обновления (Partial Edit):** Все поля опциональны. Передаются только изменяемые поля. Непереданные поля наследуются сервером из текущей ревизии.
- **Проверка версии:** Если `{version}` не совпадает с актуальной версией сценария, возвращается `409 Conflict` (`STALE_REVISION_EDIT_FORBIDDEN`).
- **Ответ 201 Created:** Создается **новая неизменяемая ревизия** (v=3). Для неё создаётся запись `revision_moderation_states` в статусе `pending_verification`. Прошлый sign-off остаётся в истории для v=2, но не распространяется на v=3.

---

### 3.6. Модерация и повторная проверка

#### `GET /api/v1/scenarios/{id}/revisions/{version}/verifications`
- **Доступ:** Пользователи текущей школы
- **Ответ 200 OK:** Журнал находок автоматической проверки.

#### `POST /api/v1/scenarios/{id}/revisions/{version}/verify`
- **Доступ:** Автор сценария
- **Назначение:** Принудительный запуск новой проверки модерации (создаёт новый `verification_run`).
- **Ответ 202 Accepted**.

---

### 3.7. Подтверждение готовности педагогом (Human Sign-Off)

#### `POST /api/v1/scenarios/{id}/revisions/{version}/signoff`
- **Доступ:** Педагог текущей школы
- **Тело (все обязательные флаги обязаны быть строго `true`):**
  ```json
  {
    "checklist_confirmed": {
      "safety_checked": true,
      "timing_fit": true,
      "cultural_context_approved": true
    },
    "teacher_notes": "Сценарий проверен, реквизит готов."
  }
  ```
- **Ошибки:**
  - `422 Unprocessable Content`: если `p0_violations_count > 0` (`P0_BLOCK_ACTIVE`), статус не `passed`/`with_warnings` либо хоть один флаг чек-листа не равен `true`.
- **Ответ 200 OK:** Создана запись в `teacher_signoffs`, разблокирован экспорт.

---

### 3.8. Экспорт материалов (`/exports`)

#### `POST /api/v1/scenarios/{id}/revisions/{version}/exports`
- **Доступ:** Авторизованные пользователи текущей школы (`teacher`, `school_admin`)
- **Обязательные заголовки:** `Idempotency-Key: <uuidv4/v7>` (защита от дублирования задач компиляции)
- **Тело запроса:**
  ```json
  {
    "format": "pdf"
  }
  ```
- **Правила и ограничения (Early MVP):**
  - Экспорт разрешён **только для актуальной ревизии** (`version == current_revision.version_number`).
  - Параметр `allow_historical: true` для раннего MVP строго запрещён; при попытке экспортировать неактуальную ревизию сервер возвращает `409 Conflict` (`HISTORICAL_EXPORT_FORBIDDEN`).
  - Ревизия обязана иметь успешно пройденную модерацию (P0 = 0) и подтверждённый sign-off учителя (`403 Forbidden` / `SIGNOFF_REQUIRED`).
- **Ответ 202 Accepted:**
  ```json
  {
    "data": {
      "export_job_id": "018d9b1c-9999-7654-a312-d81234567890",
      "status": "queued",
      "status_url": "/api/v1/export-jobs/018d9b1c-9999-7654-a312-d81234567890"
    }
  }
  ```

#### `GET /api/v1/export-jobs/{id}`
- **Ответ 200 OK:** Статус сборки и ссылка на скачивание.

#### `GET /api/v1/export-jobs/{id}/download`
- **Доступ:** Пользователи текущей школы
- **Ответ 200 OK:** Бинарный поток файла (`Content-Type: application/pdf`).

---

### 3.9. Пилот и аналитика (`/pilot`)

#### `POST /api/v1/pilot/responses`
- **Доступ:** Участник пилота (по одноразовому токену приглашения `invite_token`)
- **Тело:** Метрики подготовки, индивидуальное относительное сокращение времени, оценка полезности.
- **Ответ 201 Created**.

#### `GET /api/v1/pilot/metrics`
- **Доступ:** `system_admin`, `catalog_curator`
- **Ответ 200 OK:** Сводные метрики по пилоту. До поступления проверенных данных возвращается `status: "no_data"`.
