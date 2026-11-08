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

#### `GET /api/v1/health`
- **Доступ:** Публичный (не требует сессии или CSRF)
- **Назначение:** Системный healthcheck сервиса, соединения с базой данных PostgreSQL и кэшем/очередями Redis.
- **Ответ 200 OK (все сервисы в норме):**
  ```json
  {
    "status": "ok",
    "timestamp": "2026-10-21T18:00:00+05:00",
    "services": {
      "database": "connected",
      "redis": "connected"
    }
  }
  ```
- **Ответ 503 Service Unavailable:** при сбое подключения к PostgreSQL или Redis (`status: "degraded"`).

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

#### `GET /api/v1/scenario-requests/{id}`
- **Доступ:** Автор запроса, школьный администратор (`school_admin`) или системный администратор (`system_admin`).
- **Изоляция:** Запрос строго ограничен школой пользователя (`403 Forbidden` / `FORBIDDEN_TENANT_ACCESS`). Попытка доступа к запросу другого педагога без прав администратора возвращает `403 Forbidden` (`FORBIDDEN_AUTHOR_ACCESS`).
- **Ошибки:**
  - `401 Unauthorized`: Сессия отсутствует.
  - `403 Forbidden`: `FORBIDDEN_TENANT_ACCESS` или `FORBIDDEN_AUTHOR_ACCESS`.
  - `404 Not Found`: `SCENARIO_REQUEST_NOT_FOUND`.
- **Ответ 200 OK:**
  ```json
  {
    "data": {
      "id": "018d9b1c-7f28-7654-a312-d81234567890",
      "school_id": "018d9b1c-8888-7654-a312-d81234567890",
      "user_id": "018d9b1c-7f28-7654-a312-d81234567890",
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
      "pedagogical_constraints": "Избегать мелкого шрифта на слайдах",
      "context_snapshot": {
        "school": {
          "id": "018d9b1c-8888-7654-a312-d81234567890",
          "name": "МАОУ Лицей № 130 г. Екатеринбург",
          "region_code": "66"
        },
        "occasion": {
          "type": "calendar_event",
          "id": "018d9b1c-7f28-7654-a312-d81234567890",
          "title": "День народного единства: Уральские традиции"
        }
      },
      "created_at": "2026-10-21T15:30:00Z",
      "updated_at": "2026-10-21T15:30:00Z"
    }
  }
  ```

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

#### `GET /api/v1/scenarios/{id}/revisions/{version}`
- **Доступ:** Пользователи текущей школы
- **Назначение:** Получение детального конверта конкретной ревизии сценария с признаком актуальности `is_current`, данными подтверждения педагогом `signoff` и состоянием модерации `moderation_state`.
- **Ответ 200 OK:**
  ```json
  {
    "data": {
      "id": "018d9b3b-2222-7654-a312-d81234567890",
      "scenario_id": "018d9b3a-1111-7654-a312-d81234567890",
      "version_number": 2,
      "is_current": true,
      "parent_revision_id": "018d9b3b-1111-7654-a312-d81234567890",
      "content_payload": {
        "concept": "Образовательное погружение...",
        "stages": [],
        "materials": []
      },
      "created_at": "2026-10-21T16:00:00Z",
      "signoff": {
        "is_signed_off": true,
        "signed_off_at": "2026-10-21T16:05:00Z",
        "teacher_name": "Елена Сергеевна Смирнова",
        "teacher_id": "018d9b1c-7f28-7654-a312-d81234567890",
        "revision_id": "018d9b3b-2222-7654-a312-d81234567890",
        "notes": "Проверено"
      },
      "moderation_state": {
        "status": "passed",
        "is_ready_for_signoff": true,
        "p0_violations_count": 0,
        "p1_warnings_count": 0
      }
    }
  }
  ```

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

#### `GET /api/v1/scenarios/{id}/revisions/{version}/exports`
- **Доступ:** Авторизованные пользователи текущей школы (автор сценария, `school_admin`, `system_admin`).
- **Назначение:** Получение истории всех экспортных задач и скомпилированных артефактов для данной ревизии.
- **Особенности:** Динамический расчёт флага `is_downloadable` через Live Provenance Gate (блокировка при отзыве/изменении первоисточника куратором или отсутствии фингерпринта).
- **Ответ 200 OK:**
  ```json
  {
    "data": [
      {
        "id": "018d9b1c-9999-7654-a312-d81234567890",
        "revision_id": "018d9b1c-8888-7654-a312-d81234567890",
        "format": "docx",
        "status": "ready",
        "provenance_fingerprint": "a3f8901...",
        "is_downloadable": true,
        "download_url": "/api/v1/export-jobs/018d9b1c-9999-7654-a312-d81234567890/download",
        "created_at": "2026-10-22T14:30:00Z",
        "completed_at": "2026-10-22T14:30:04Z",
        "artifact": {
          "id": "018d9b1c-7777-7654-a312-d81234567890",
          "file_name": "scenario-title-v1.docx",
          "file_size_bytes": 18450,
          "mime_type": "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
          "sha256_hash": "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855",
          "expires_at": "2026-10-29T14:30:00Z"
        }
      }
    ]
  }
  ```

#### `POST /api/v1/scenarios/{id}/revisions/{version}/exports`
- **Доступ:** Авторизованные пользователи текущей школы (`teacher`, `school_admin`)
- **Обязательные заголовки:** `Idempotency-Key: <uuidv4/v7>` (защита от дублирования задач компиляции)
- **Поддерживаемые форматы:**
  - `pdf` — Официальный методический сценарий (A4 PDF Dompdf);
  - `docx` — Полный редактируемый сценарий (Microsoft Word 2007+ / LibreOffice OpenXML);
  - `pptx` — Настоящая презентация из 5 слайдов 16:9 со спикерскими заметками (Microsoft PowerPoint 2007+ OpenXML);
  - `print_pack_pdf` — Комплект печатных раздаточных материалов (памятка педагога, карточки заданий с линиями отреза, справочный лист).
- **Тело запроса:**
  ```json
  {
    "format": "docx"
  }
  ```
- **Правила и ограничения безопасности:**
  - Экспорт разрешён **только для актуальной ревизии** (`version == current_revision.version_number`).
  - Параметр `allow_historical: true` строго запрещён (`409 Conflict`).
  - Ревизия обязана иметь успешно пройденную модерацию (`P0 = 0`, `MODERATION_REVERIFICATION_REQUIRED` при расхождении отпечатка доказательств) и подтверждённый sign-off учителя под тот же fingerprint (`403 Forbidden` / `EXPORT_FORBIDDEN_SIGN_OFF_REQUIRED`).
- **Ответ 202 Accepted:**
  ```json
  {
    "data": {
      "id": "018d9b1c-9999-7654-a312-d81234567890",
      "status": "ready",
      "download_url": "/api/v1/export-jobs/018d9b1c-9999-7654-a312-d81234567890/download",
      "file_name": "scenario-title-v1.docx",
      "file_size_bytes": 18450
    }
  }
  ```

#### `GET /api/v1/export-jobs/{id}`
- **Ответ 200 OK:** Статус сборки и ссылка на скачивание.

#### `GET /api/v1/export-jobs/{id}/download`
- **Доступ:** Пользователи текущей школы (с обязательной валидацией Provenance Gate и запретом legacy NULL).
- **MIME-типы ответа:**
  - `pdf`: `application/pdf`
  - `docx`: `application/vnd.openxmlformats-officedocument.wordprocessingml.document`
  - `pptx`: `application/vnd.openxmlformats-officedocument.presentationml.presentation`
  - `print_pack_pdf`: `application/pdf`
- **Заголовки:** `Content-Disposition: attachment; filename="..."`, `X-Checksum-Sha256: <sha256>`.

---

### 3.9. Этнокультурная экспертиза и кураторский аудит первоисточников (BE-006)

#### `GET /api/v1/catalog/review-queue`
- **Доступ:** `catalog_curator`, `system_admin`.
- **Параметры:** `status` (`pending`, `approved`, `rejected`, `requires_amendment`, `all`), `region_code`, `event_type`, `page`, `per_page`.
- **Область видимости:** региональные кураторы видят события своих регионов и федеральные события; глобальные кураторы и администраторы видят всё.
- **Ответ 200 OK:** список первоисточников и сведений об их статусе рецензирования.

#### `GET /api/v1/event-sources/{id}`
- **Доступ:** авторизованные пользователи (с проверкой видимости события для школы/куратора).
- **Ответ 200 OK:** детальная карточка первоисточника с историей рецензий, статусом проверки и привязанными утверждениями.

#### `PATCH /api/v1/event-sources/{id}`
- **Доступ:** `catalog_curator` (в рамках своего региона), `system_admin`.
- **Тело запроса:** редактирование `title`, `author`, `source_type`, `url`, `doi_or_isbn`.
- **Ответ 200 OK:** обновлённые данные первоисточника.

#### `GET /api/v1/event-sources/{id}/reviews`
- **Доступ:** `catalog_curator`, `system_admin`.
- **Ответ 200 OK:** полный хронологический журнал экспертных рецензий.

#### `POST /api/v1/event-sources/{id}/reviews`
- **Доступ:** `catalog_curator` (с проверкой региональных полномочий), `system_admin`.
- **Тело запроса:**
  ```json
  {
    "decision": "approved",
    "comments": "Первоисточник сверен с академическим изданием, локаторы точны."
  }
  ```
- **Бизнес-логика:**
  - `approved`: переводит связанные утверждения в `verified_authentic`, выставляет верификатора и дату.
  - `rejected`: переводит связанные утверждения в `rejected_fake` (мгновенно активирует блокировку Provenance Gate при экспорте).
  - `requires_amendment`: сбрасывает статус ранее одобренных утверждений в `unverified`.
- **Ответ 201 Created.**

#### `GET /api/v1/cultural-claims/{id}`
- **Доступ:** авторизованные пользователи.
- **Ответ 200 OK:** карточка культурного утверждения со всеми привязанными свидетельствами первоисточников и динамическим статусом проверки.

#### `POST /api/v1/cultural-claims/{id}/evidence`
- **Доступ:** `catalog_curator`, `system_admin`.
- **Тело запроса:**
  ```json
  {
    "event_source_id": "018d9b1c-7f28-7654-a312-d81234567890",
    "citation_locator": "Том 2, стр. 145-148",
    "citation_excerpt": "Фрагмент текста источника, подтверждающий данную традицию (не менее 10 символов).",
    "applicable_academic_year": "2026-2027",
    "notes": "Проверено по архивному фонду.",
    "verify": true
  }
  ```
- **Правила:** защита от межсобытийного связывания (422), проверка типа источника против исторических утверждений (422), проверка на бессодержательные заполнители (422).
- **Ответ 201 Created.**

#### `DELETE /api/v1/cultural-claims/{id}/evidence/{sourceId}`
- **Доступ:** `catalog_curator`, `system_admin`.
- **Ответ 200 OK:** отвязка свидетельства первоисточника от культурного утверждения.

---

### 3.10. Жизненный цикл сценариев: поиск, архивация, восстановление и безопасное удаление (BE-006)

#### `GET /api/v1/scenarios` (расширенная фильтрация)
- **Параметры:** `q` (поиск по названию), `status` (`draft`, `completed`, `archived`), `is_archived` (`true`, `false`, `all`), `sort` (`created_at:desc`, `updated_at:desc`, `title:asc`), `from`, `to`, `page`, `per_page`.
- По умолчанию возвращаются только неархивированные сценарии (`is_archived = false`).
- Учитель видит свои сценарии; школьный администратор и системный администратор видят все сценарии школы.

#### `POST /api/v1/scenarios/{id}/archive`
- **Доступ:** автор сценария, `school_admin`, `system_admin`.
- **Ответ 200 OK:** сценарий переведён в статус `archived` (`is_archived: true`). Идемпотентен.

#### `POST /api/v1/scenarios/{id}/restore`
- **Доступ:** автор сценария, `school_admin`, `system_admin`.
- **Ответ 200 OK:** сценарий восстановлен в статус `draft` (`is_archived: false`). Идемпотентен.

#### `DELETE /api/v1/scenarios/{id}`
- **Доступ:** автор сценария, `school_admin`, `system_admin`.
- **P0 Защитный барьер:** если хоть на одну ревизию сценария оформлен педагогический допуск (`teacher_signoffs`), удаление категорически запрещено:
  - Возвращается `409 Conflict` с кодом `CANNOT_DELETE_SIGNED_OFF_SCENARIO`.
- Если допусков нет, выполняется безопасное удаление через механизм `SoftDeletes` (`deleted_at`), не разрушающее исторические связи в базе.
- **Ответ 200 OK:** сценарий удален.

---

### 3.11. Профиль образовательной организации и школьные традиции (BE-006)

#### `GET /api/v1/schools/{id}`
- **Доступ:** участники данной школы, `system_admin`.
- **Ответ 200 OK:** профиль организации с реквизитами (`inn`, `region_code`, `city`), настройками и счётчиками (`traditions_count`, `active_teachers_count`).

#### `PATCH /api/v1/schools/{id}`
- **Доступ:** `school_admin` этой школы, `system_admin`.
- **Тело запроса:** редактирование `name`, `short_name`, `city`, `inn`, `settings` (контакты, адрес, сайт).
- **Ответ 200 OK:** обновлённый профиль школы.

#### `GET /api/v1/traditions/{id}`
- **Доступ:** участники школы, `system_admin`.
- **Ответ 200 OK:** детальная карточка традиции с текущей активной версией и полным списком ежегодных версий.

#### `PATCH /api/v1/traditions/{id}`
- **Доступ:** автор традиции, `school_admin`, `system_admin`.
- **Тело запроса:** обновление метаданных (`title`, `description`, `established_year`).
- **Неизменяемость версий:** исторические параметры версий (`tradition_versions`) строго неприкосновенны.
- **Ответ 200 OK.**

#### `POST /api/v1/traditions/{id}/archive`
- **Доступ:** автор традиции, `school_admin`, `system_admin`.
- **Ответ 200 OK:** традиция архивирована (`is_archived: true`). Идемпотентен.

---

### 3.12. Сводная панель образовательной организации (Dashboard Summary) (BE-006)

#### `GET /api/v1/dashboard/summary`
- **Доступ:** участники текущей школы (`tenant` middleware).
- **Ответ 200 OK:**
  ```json
  {
    "data": {
      "school": {
        "id": "018d9b1c-7f28-7654-a312-d81234567890",
        "name": "МАОУ Лицей № 130",
        "short_name": "Лицей 130",
        "region_code": "66",
        "city": "Екатеринбург"
      },
      "upcoming_events": [...],
      "recent_scenarios": [...],
      "counts": {
        "total_scenarios": 12,
        "draft_scenarios": 8,
        "completed_scenarios": 4,
        "archived_scenarios": 2,
        "traditions_count": 5
      }
    }
  }
  ```

---

### 3.13. Конструктор сценариев: масштабирование (класс / школа / семья) (BE-006)

В запросе `POST /api/v1/scenario-requests`:
- Параметр `scale` определяет структуру воспитательного события:
  - `class_level`: классный час / урок на 1 класс (20-45 минут), 3 этапа (вводная мотивация, практикум, рефлексия); роли: «Хранитель традиций», «Презентатор идей».
  - `all_school`: общешкольный фестиваль / квест на несколько параллелей или всю школу (60-180 минут), 4 этапа (общешкольная линейка открытия, работа тематических станций, творческая ассамблея, финальная рефлексия); роли: «Координатор тематической станции», «Капитан отряда», «Ведущий общешкольного форума», «Школьный медиа-летописец».
  - `family_community`: семейно-общественное событие с участием родителей и поколений (45-120 минут), флаг `family_involvement: true`; этапы: круг доверия, семейная мастерская родословия, межпоколенческий круглый стол, благодарственное чаепитие; роли: «Капитан семейного экипажа», «Хранитель семейной летописи», «Семейный фасилитатор», «Почетный наставник поколений».
- Параметр `region_code`: при свободном поводе (`custom_event_title`) позволяет явно задать региональный контекст события.
- Все генерируемые шаблоны строго валидируются против схем `generation-output.schema.json` и `scenario.schema.json`.

---

### 3.14. A/B-сравнение сценариев (`/scenario-comparisons`) (BE-008)

#### `GET /api/v1/scenario-comparisons`
- **Роли:** `teacher`, `deputy_director`, `school_admin`, `catalog_curator`, `system_admin`.
- **Заголовки:** `X-School-Id` (обязателен).
- **Ответ:** Список сессий A/B-сравнения текущей школы с метаданными вариантов и статусом выбора педагога.

#### `POST /api/v1/scenario-comparisons`
- **Роли:** `teacher`, `deputy_director`, `school_admin`.
- **Тело запроса:**
  - `scenario_request_id` (uuid, required)
  - `comparison_type` (string, enum: `universal_vs_personalized`, `cultural_context`, required)
- **Логика:**
  - `universal_vs_personalized`: Генерирует универсальный базовый вариант (A) и глубоко персонализированный вариант (B) с учетом традиций школы, профиля классов и ресурсов.
  - `cultural_context`: Генерирует вариант без этнокультурного компонента (A) и вариант с глубокой этнокультурной интеграцией (B), защищенной Provenance Gate.
- **Ответ:** `201 Created` со структурой `ScenarioComparisonDTO`.

#### `GET /api/v1/scenario-comparisons/{id}`
- **Ответ:** Полные данные сессии сравнения, включая идентификаторы сценариев `variant_a_scenario_id` и `variant_b_scenario_id`, зафиксированный выбор и статус модерации.

#### `GET /api/v1/scenario-comparisons/{id}/variants`
- **Ответ:** Структурированное сопоставление вариантов A и B для фронтенда: заголовки, концептуальные отличия, методические акценты, структура этапов, роли и сравнительное резюме `differences_summary`.

#### `POST /api/v1/scenario-comparisons/{id}/selection`
- **Роли:** `teacher`, `deputy_director`, `school_admin`.
- **Тело запроса:**
  - `selected_variant` (string, enum: `variant_a`, `variant_b`, required)
  - `selection_rationale` (string, optional)
- **Защита от фальсификации:** Неизменяемая фиксация (Immutable Record). Повторный выбор возвращает `409 Conflict`.

---

### 3.15. Пилотное исследование с 20 педагогами и аналитика (`/pilot/*`) (BE-008)

#### `GET /api/v1/pilot/participants`
- **Роли:** `school_admin`, `catalog_curator`, `system_admin`.
- **Заголовки:** `X-School-Id`.
- **Ответ:** Список псевдонимизированных участников пилота школы (анонимные коды, предметные области, стаж, статус отправки анкеты, срок действия токена).

#### `POST /api/v1/pilot/participants`
- **Роли:** `school_admin`, `catalog_curator`, `system_admin`.
- **Тело запроса:**
  - `anonymous_code` (string, optional — генерируется автоматически вида `TEACHER-SVR-001`)
  - `subject_area` (string, required)
  - `teaching_experience_years` (integer, min: 0, max: 60, required)
  - `expires_days` (integer, default: 30)
- **Безопасность:** В ответе `201 Created` возвращается открытый одноразовый пригласительный токен `invite_token`. В базе данных сохраняется строго криптографический SHA-256 хэш (`invite_token_hash`).

#### `POST /api/v1/pilot/participants/{id}/reissue`
- **Роли:** `school_admin`, `catalog_curator`, `system_admin`.
- **Логика:** Аннулирует прежний токен и генерирует новый одноразовый пригласительный токен для участника (если анкета еще не была отправлена).

#### `POST /api/v1/pilot/participants/{id}/revoke`
- **Роли:** `school_admin`, `catalog_curator`, `system_admin`.
- **Логика:** Безотзывно отзывает приглашение участника (`revoked_at = now()`).

#### `POST /api/v1/pilot/responses`
- **Аутентификация:** Анонимная по одноразовому пригласительному токену в теле запроса (не требует cookie авторизации).
- **Тело запроса:**
  - `invite_token` (string, required)
  - `scenario_id` (uuid, required)
  - `prep_time_baseline_minutes` (integer, min: 1, required)
  - `prep_time_ai_minutes` (integer, min: 0, required)
  - `personalization_selected` (boolean, required)
  - `manual_edit_percentage` (numeric, min: 0, max: 100, required)
  - `cultural_context_used` (boolean, required)
  - `cultural_usefulness_score` (integer, 1-5, обязателен при `cultural_context_used = true`)
  - `feedback_text` (string, optional)
- **Методология экономии времени:** Расчет выполняется строго на сервере по формуле относительной экономии: `(baseline - ai) / baseline`.
- **Атомарность:** Выполняется в транзакции с пессимистической блокировкой `lockForUpdate()`. После отправки токен навсегда помечается как использованный (`is_submitted = true`). Повторная отправка отклоняется `409 Conflict`.

#### `GET /api/v1/pilot/responses/{id}`
- **Роли:** `school_admin`, `catalog_curator`, `system_admin`.
- **Ответ:** Детальная информация по анкете участника, включая рассчитанную сервером экономию времени и экспертные заключения.

#### `POST /api/v1/pilot/responses/{id}/reviews`
- **Роли:** `catalog_curator` (по региону школы), `system_admin`, `school_admin` (строго своей школы).
- **Разграничение доступа (BE-008.1):**
  - Школа определяется строго через связь `$response->participant->school`, пользовательский ввод школы игнорируется.
  - Администратор школы может вносить заключения только для участников своей организации (попытка межшкольного аудита возвращает `403 Forbidden`).
  - Региональный куратор проверяет анкеты только школ своего региона (`curated_region_codes`).
  - Педагогам внесение заключений запрещено (`403 Forbidden`).
- **Версионирование (Append-Only) (BE-008.1):** Заключения не перезаписываются. Каждое изменение создаёт новую запись с `finding_version = N + 1` и `is_effective = true`, переводя предыдущие версии в `is_effective = false`.
- **Тело запроса:**
  - `expert_audit_status` (string, enum: `audited`, `flagged`, `rejected`, required)
  - `critical_cultural_errors` (integer, min: 0, required)
  - `methodological_remarks` (string, optional)
- **Ответ:** `200 OK` с зафиксированным экспертным заключением (`finding_version`, `is_effective`).

#### `GET /api/v1/pilot/metrics`
- **Роли:** `teacher`, `deputy_director`, `school_admin`, `catalog_curator`, `system_admin`.
- **Параметры:**
  - `scope=school` (по умолчанию) или `scope=global` (для кураторов/администраторов).
  - `include_synthetic=1` (boolean, optional — включение синтетических записей, по умолчанию `false`).
- **Лестница состояний выборки и аудит (BE-008.1):**
  - `no_data`: выборка $N=0$ (метрики `null`).
  - `active_pilot`: выборка $N > 0$, но опрос или экспертный аудит не завершены в полном объёме (например, $N < 20$ либо проверено менее 100% анкет, либо имеются критические ошибки).
  - `completed`: репрезентативная выборка $N \ge 20$ И полный 100% аудит экспертами без критических культурных ошибок (`critical_cultural_errors_count === 0`).
- **Разделение показателей аудита (BE-008.1):**
  - `submitted_responses_count`: общее количество полученных анкет ($N$).
  - `audited_responses_count`: количество проверенных экспертами анкет (по действующим версиям `is_effective = true`).
  - `pending_expert_reviews_count`: количество анкет, ожидающих экспертизы.
  - `expert_review_coverage_percent`: процент покрытия выборки аудитом (`(audited / total) * 100%`).
  - `critical_cultural_errors_count`: сумма критических ошибок среди проверенных анкет (возвращает `null`, если проверено 0 анкет).
- **Методологическая прозрачность:**
  - `personalization_choice_percent` / `self_reported_personalization_choice_percent` — субъективный выбор педагога по результатам анкетного опроса.
  - `ScenarioComparison::selected_variant` — экспериментально зафиксированный выбор в парной A/B-сессии.
- **Целевые критерии качества:**
  - `time_saving_ge_50`: медианная индивидуальная относительная экономия $\ge 50\%$.
  - `personalization_ge_70`: выбор персонализированного варианта $\ge 70\%$.
  - `manual_edit_under_20_ge_70`: доля сценариев с доработками $\le 20\%$ составляет $\ge 70\%$.
  - `cultural_usage_ge_60`: использование этнокультурного контекста $\ge 60\%$.
  - `cultural_usefulness_ge_4`: средняя полезность этнокультурного компонента $\ge 4.0$ / 5.0.
  - `zero_critical_cultural_errors`: 0 критических культурных ошибок при **100% аудите всей выборки**. При частичном аудите (например, 5 из 20 проверено, ошибок 0) критерий НЕ считается выполненным (`false`, статус `pending_review`).
- **Статусы критериев (`criteria_status`):** `pass`, `fail`, `pending_review`, `pending_data`.

#### `GET /api/v1/pilot/export`
- **Роли:** `school_admin`, `catalog_curator`, `system_admin`. Педагогам доступ запрещен (`403 Forbidden`).
- **Параметры:** `scope=school|global`, `include_synthetic=1`.
- **Ответ:** Полный выгружаемый отчет с метаданными экспорта (`exported_at`, `scope`, `school_id`) и полным блоком агрегированных метрик.

---

## 4. Раздел будущих возможностей (Future Roadmap / Planned Endpoints)

> [!WARNING]
> Данные эндпоинты зарезервированы в целевом архитектурном дизайне сервиса для последующих этапов развития. В текущем базовом ядре серверной части они **не реализованы**. Попытка вызова вернёт HTTP `404 Not Found`.

### 4.1. `POST /api/v1/auth/forgot-password`
- **Статус:** Запланировано (Future Phase)
- **Назначение:** Восстановление пароля пользователя через ссылку на электронную почту с нейтральным ответом при любых входных данных.


## LLM-001 / OpenAPI 1.6.0

POST /scenario-comparisons сохраняет HTTP 201. Для litellm ответ имеет status=pending; GET /scenario-comparisons/{id} опрашивается до completed/failed. Failed — конечный статус, автоматического повтора платной генерации нет. Необязательный UUID Idempotency-Key повторяет ту же пару в пределах пользователя/школы; конфликт запроса/типа — 409. Mock сохраняет синхронное completed. Варианты не считаются готовыми до проверки и атомарного сохранения. В differences_summary доступны фактические pedagogical_approach и безопасный error_code/error_message при ошибке.

Существующие GenerationRun endpoints сохраняют форму ответа; provider_name/model_identifier/latency/tokens теперь отражают реальный вызов. Неизвестный usage — null. Ошибки LiteLLM имеют безопасные коды LLM_*, validation — VALIDATION_FAILED. Ошибка серверной конфигурации перед запуском — HTTP 503. Контракт sign-off, provenance, RBAC и tenant isolation не ослаблен.


## LLM-001.1 quality_review (OpenAPI 1.7.0)

ScenarioEnvelope.data и ScenarioRevisionEnvelope.data имеют additive quality_review; ScenarioComparisonEnvelope.data.quality_review содержит A/B. status только blocked/requires_review. technical_checks отделён от expert_assessment=not_performed. cultural_coverage.complete=false и whole_document_verified=false даже при exact_supported_claim. Fresh GET пересчитывает по актуальным evidence; immutable generation audit остаётся в GenerationRun.sanitized_prompt. QUALITY_GATE_BLOCKED (422) дополнительно запрещает Sign-Off/export/download для реальных LiteLLM descendants с известными ошибками. Культурный B с blocking findings недоступен для выбора. См. quality-gate-llm001-1.md и cultural-claim-coverage-llm001-1.md.
