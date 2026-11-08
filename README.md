> **LLM-001.1: PENDING INDEPENDENT REVIEW.** Добавлены дополнительный Quality Gate и консервативная проверка культурного текста во всех разделах. Промпт school-methodist-1.5.2. Автоматическая модерация не равна содержательной экспертизе. См. [Quality Gate](docs/quality-gate-llm001-1.md), [покрытие фактов](docs/cultural-claim-coverage-llm001-1.md), [приёмка](docs/LLM-001.1-review.md).

> **LLM-001: PENDING INDEPENDENT REVIEW.** Добавлен реальный LiteLLM provider, изолированная Redis-проверка и асинхронное A/B. Рабочий контур не переключался с mock. Настройка и ограничения: [интеграция](docs/llm-provider-integration.md), [очереди](docs/queues-llm001.md), [отчёт](docs/LLM-001-review.md). Secrets создаются локально; vendor устанавливается через Composer.

# Школьные события — Laravel Backend (Сбер × УрФУ)

Основной репозиторий и CI/CD: [GitLab backend](https://gitlab.com/Brossend2335/school-events-backend). [Frontend на GitLab](https://gitlab.com/Brossend2335/school-events-frontend). GitHub используется только как зеркало `main`; изменения сначала проходят GitLab. Конфигурация pipeline: `.gitlab-ci.yml`; настройка переменных и будущего развёртывания описана в [REPO-001](docs/REPO-001-report.md).

В Git входят только `.env.example` и `.env.testing.example`. Создавайте реальные `.env` локально, задавайте собственные пароли БД и ключ приложения. CI использует MockProvider без LiteLLM-ключа. Результат заключительной культурной live-проверки: [LLM-001.3](docs/LLM-001.3-live-result.md); он не заменяет экспертную педагогическую оценку.

Модульный монолит на **Laravel 13**, **PHP 8.4-FPM**, **PostgreSQL 16**, **Redis 7** и **Nginx**, спроектированный под SPA-фронтенд с сессионной авторизацией Laravel Sanctum, строгой изоляцией школ (multi-tenancy), асинхронной очередью генерации сценариев и верифицируемым шлюзом культурных источников (Provenance Gate).

---

## 1. Системные требования

- **ОС:** Windows 10/11 с установленным **Docker Desktop** (WSL 2 backend) или Linux / macOS с Docker Engine 24+.
- **Docker Compose:** v2.20+.
- **Порты на хосте:**
  - `8000` — Nginx Web API (доступен на интерфейсе `0.0.0.0:8000`);
  - `5432` — PostgreSQL 16 (доступен **строго** на `127.0.0.1:5432` для безопасности);
  - Redis 7 **не публикует** портов наружу и изолирован внутри внутренней Docker-сети `school_network`.

---

## 2. Актуальная последовательность запуска из чистого архива (Clean Setup)

Для воспроизведения на новой машине без установленного локального PHP или Composer:

### Пошаговые команды через Docker Compose:

1. **Создайте файл конфигурации окружения:**
   ```bash
   cp .env.example .env
   ```

2. **Соберите и запустите контейнеры:**
   ```bash
   docker compose up -d --build
   ```

3. **Установите зависимости Composer внутри контейнера:**
   ```bash
   docker compose exec app composer install --no-interaction --prefer-dist --optimize-autoloader
   ```

4. **Сгенерируйте ключ приложения:**
   ```bash
   docker compose exec app php artisan key:generate --force
   ```

5. **Примените миграции к рабочей базе данных (`school_events`):**
   ```bash
   docker compose exec app php artisan migrate --force
   ```
   В схеме БД задействовано 19 миграций (включая неизменяемый аудит доказательной базы `cultural_claim_evidence_audit_events`, криптографический фингерпринт доказательств `provenance_fingerprint` в `export_jobs`, `exported_artifacts`, `teacher_signoffs`, `revision_moderation_states`, расширение форматов экспорта на `pdf`, `docx`, `pptx`, `print_pack_pdf`, сессии A/B-сравнения `scenario_comparisons`, участников пилота `pilot_participants`, анкеты `pilot_responses`, экспертные заключения `pilot_review_findings` и защиту от дубликатов рецензий `single_effective_review_constraint`).

6. **Наполните базу данных демонстрационными данными:**
   ```bash
   docker compose exec app php artisan db:seed --force
   ```
   *Сидер создает демонстрационные школы (Лицей № 130, Школа № 50), учетные записи педагогов и администраторов, календарные события, утвержденные первоисточники и исторические традиции.*

7. **Фоновый воркер Redis (Queue Worker):**
   Контейнер `school-queue-1` запускается автоматически в составе Docker Compose стека и выполняет команду:
   ```bash
   php artisan queue:work redis --tries=3 --timeout=120
   ```
   Он обрабатывает асинхронные задачи генерации сценариев (`ExecuteGenerationRunJob`) и компиляции PDF-документов (`ExecuteExportJob`).

8. **Проверьте работоспособность сервиса:**
   ```bash
   curl -i http://localhost:8000/api/v1/health
   ```

---

## 3. Статус генерации и внешних LLM

В текущей кодовой базе строго разграничены реализованная серверная логика и архитектурные контракты интеграции с языковыми моделями:
- **Действующий провайдер генерации (РЕАЛИЗОВАН):** `App\Services\Llm\MockLlmProvider`, реализующий контракт `App\Services\Llm\LlmProviderInterface`.
  - Формирует 100% валидный `content_payload`, удовлетворяющий строгим схемам `docs/generation-output.schema.json` и `docs/scenario.schema.json` (Draft 2020-12).
  - Генерирует детерминированную педагогическую структуру: цели, личностные/метапредметные/предметные задачи, этапы с поминутным таймингом, роли участников, карточки раздаточных материалов и культурные утверждения со связыванием с первоисточниками каталога.
  - Активен по умолчанию и покрыт 16 сквозными интеграционными тестами (`LlmGenerationTest`).
- **Сменяемая архитектура (ГОТОВА):** Сервисный слой полностью абстрагирован через `LlmProviderInterface` и фабрику регистрации в `AppServiceProvider`. Контроллеры, валидаторы, конвейер сохранения и модерации не зависят от вендора модели.
- **Промышленные адаптеры (СПЕЦИФИЦИРОВАНЫ АРХИТЕКТУРНО):** Архитектурные спецификации подключения GigaChat (Сбер), OpenAI (GPT-4o) и YandexGPT подробно описаны в [`docs/llm-provider-integration.md`](docs/llm-provider-integration.md). Сетевая интеграция с реальными API не внедрялась во избежание фиктивной симуляции без действующих коммерческих контрактов и API-ключей заказчика.

---

## 4. Действующие API-операции (BE-009.1 Final Release)

В действующем API зафиксированы **58 операций** по **47 производственным путям**, строго синхронизированным с `docs/openapi.yaml` и `routes/api.php`:

### Системные и инфраструктурные операции
- `GET  /api/v1/health` — Системный healthcheck состояния PostgreSQL и Redis.
- `GET  /api/v1/system/status` — Проверка прав доступа системного администратора и куратора.

### Аутентификация и контекст пользователя
- `POST /api/v1/auth/login` — Вход педагога по паролю с сессионной кукой `laravel_session` и защитой от перебора (rate limiting).
- `POST /api/v1/auth/logout` — Инвалидация сессии и отзыв CSRF-токена.
- `GET  /api/v1/auth/me` — Профиль текущего пользователя и список доступных школ.
- `GET  /api/v1/tenant/current` — Получение контекста активной школы (проверка заголовка `X-School-Id`).

### Календарь событий
- `GET  /api/v1/calendar-events` — Каталог событий с фильтрацией по регионам и типам.
- `POST /api/v1/calendar-events` — Добавление школьного или регионального события.
- `GET  /api/v1/calendar-events/{id}` — Карточка события с проверенными первоисточниками и цитатами.

### Кураторская верификация и реестр первоисточников
- `GET  /api/v1/catalog/review-queue` — Очередь источников на рецензирование куратором (с региональной фильтрацией).
- `GET  /api/v1/event-sources/{id}` — Детальная карточка первоисточника с историей рецензий.
- `PATCH /api/v1/event-sources/{id}` — Редактирование библиографических реквизитов первоисточника.
- `GET  /api/v1/event-sources/{id}/reviews` — Хронологический аудит-лог экспертных рецензий.
- `POST /api/v1/event-sources/{id}/reviews` — Вынесение решения куратора (`approved`, `rejected`, `requires_amendment`).

### Культурные утверждения и доказательная база (Evidence Binding)
- `GET  /api/v1/cultural-claims/{id}` — Карточка утверждения со списком прикрепленных источников.
- `POST /api/v1/cultural-claims/{id}/evidence` — Прикрепление источника к утверждению с точным цитатным логатором.
- `DELETE /api/v1/cultural-claims/{id}/evidence/{sourceId}` — Открепление источника от утверждения.

### Профиль школы и управление традициями
- `GET  /api/v1/schools/{id}` — Карточка школы с агрегированными показателями (педагоги, традиции).
- `PATCH /api/v1/schools/{id}` — Обновление контактных и организационных реквизитов школы.
- `GET  /api/v1/schools/{schoolId}/traditions` — Список традиций школы с активной версией и историей.
- `POST /api/v1/schools/{schoolId}/traditions` — Создание новой многолетней традиции.
- `GET  /api/v1/traditions/{id}` — Карточка традиции с полной историей ежегодных версий.
- `PATCH /api/v1/traditions/{id}` — Обновление названия и описания традиции (исторические версии неизменяемы).
- `POST /api/v1/traditions/{id}/archive` — Идемпотентный перевод традиции в архив.
- `POST /api/v1/traditions/{id}/versions` — Фиксация версии традиции на новый учебный год.

### Сводная аналитика школы (Дашборд)
- `GET  /api/v1/dashboard/summary` — Сводные метрики: ближайшие события, последние сценарии, счетчики статусов.

### Конструктор и асинхронная генерация
- `POST /api/v1/scenario-requests` — Сохранение параметров мероприятия с поддержкой регионального кода и снимка контекста.
- `GET  /api/v1/scenario-requests/{id}` — Получение параметров запроса сценария и контекста.
- `POST /api/v1/scenario-requests/{id}/generate` — Запуск асинхронной генерации сценария с обязательным заголовком `Idempotency-Key`.
- `GET  /api/v1/generation-runs/{id}` — Опрос статуса генерации задачи (`queued` / `completed` / `failed`).

### Управление сценариями и редактор ревизий
- `GET  /api/v1/scenarios` — Список сценариев школы с поиском по заголовку (`q`), фильтрами статусов и архивным режимом.
- `GET  /api/v1/scenarios/{id}` — Карточка сценария с текущей ревизией, этапами и статусом модерации.
- `POST /api/v1/scenarios/{id}/archive` — Идемпотентное архивирование сценария.
- `POST /api/v1/scenarios/{id}/restore` — Восстановление сценария из архива.
- `DELETE /api/v1/scenarios/{id}` — Удаление сценария (soft delete; при наличии TeacherSignoff блокируется с кодом 409).
- `GET  /api/v1/scenarios/{id}/revisions/{version}` — Детальный конверт ревизии сценария (`ScenarioRevisionEnvelope`).
- `PATCH /api/v1/scenarios/{id}/revisions/{version}` — Редактирование ревизии с созданием ревизии N+1 и блокировкой `If-Match`.

### Модерация и культурная безопасность
- `GET  /api/v1/scenarios/{id}/revisions/{version}/verifications` — Журнал находок автоматической модерации.
- `POST /api/v1/scenarios/{id}/revisions/{version}/verify` — Принудительный перезапуск проверки модерации.

### Согласование педагогом (Human Sign-Off)
- `POST /api/v1/scenarios/{id}/revisions/{version}/signoff` — Фиксация согласования чек-листа педагогом (безопасность, хронометраж, культурный контекст) с обязательным шлюзом актуальности модерации (`MODERATION_REVERIFICATION_REQUIRED`).

### Экспорт материалов и презентаций (PDF, DOCX, PPTX, Print Pack)
- `POST /api/v1/scenarios/{id}/revisions/{version}/exports` — Постановка в очередь экспорта в одном из четырёх поддерживаемых форматов (`pdf`, `docx`, `pptx`, `print_pack_pdf`) с заголовком `Idempotency-Key` и строгой проверкой Provenance Gate (блокировка legacy NULL фингерпринтов, несовпадения модерации и отозванных источников).
- `GET  /api/v1/scenarios/{id}/revisions/{version}/exports` — История задач экспорта для ревизии со статусами, метаданными скомпилированных файлов и динамическим флагом `is_downloadable`.
- `GET  /api/v1/export-jobs/{id}` — Опрос статуса сборки экспортируемого документа (`queued`, `processing`, `ready`, `failed`).
- `GET  /api/v1/export-jobs/{id}/download` — Скачивание скомпилированного документа с fail-closed проверкой актуальности модерации, фингерпринтов и статусов первоисточников.

### A/B-сравнение сценариев (BE-008)
- `GET  /api/v1/scenario-comparisons` — Список сессий A/B-сравнения текущей школы с метаданными вариантов.
- `POST /api/v1/scenario-comparisons` — Создание сессии сравнения (`universal_vs_personalized` или `cultural_context`) с асинхронной генерацией двух полнотекстовых вариантов.
- `GET  /api/v1/scenario-comparisons/{id}` — Карточка сессии A/B-сравнения с привязкой сценариев и статусом выбора.
- `GET  /api/v1/scenario-comparisons/{id}/variants` — Структурированное сопоставление вариантов A и B (концепция, методика, этапы, роли, различия).
- `POST /api/v1/scenario-comparisons/{id}/selection` — Неизменяемая фиксация предпочтения педагога (защита от повторного выбора с кодом 409 Conflict).

### Пилотное исследование с 20 педагогами и аналитика (BE-008 / BE-008.1)
- `GET  /api/v1/pilot/participants` — Реестр псевдонимизированных педагогов-участников с метаданными токенов (только `school_admin`, `catalog_curator`, `system_admin`; педагогам 403).
- `POST /api/v1/pilot/participants` — Приглашение педагога с выдачей одноразового токена (в БД сохраняется только SHA-256 хэш).
- `POST /api/v1/pilot/participants/{id}/reissue` — Перевыпуск одноразового пригласительного токена.
- `POST /api/v1/pilot/participants/{id}/revoke` — Отзыв приглашения участника исследования.
- `POST /api/v1/pilot/responses` — Атомарная отправка анкеты педагогом по токену с расчетом относительной экономии времени `(baseline - ai) / baseline`.
- `GET  /api/v1/pilot/responses/{id}` — Детальная карточка ответа с экспертными заключениями (только администраторам школы и кураторам).
- `POST /api/v1/pilot/responses/{id}/reviews` — Экспертное заключение этнографа/куратора о наличии критических культурных ошибок (строгий RBAC: межшкольный аудит заблокирован 403; append-only версионирование `finding_version`, `is_effective`).
- `GET  /api/v1/pilot/metrics` — Сводные количественные метрики пилота с лестницей состояний (`no_data`, `active_pilot`, `completed`), строгим учетом покрытия аудита (критерий 0 ошибок требует 100% проверки всей выборки) и разделением реальных/синтетических данных (`is_synthetic`).
- `GET  /api/v1/pilot/export` — Полная выгрузка сводных метрик и структуры пилотного исследования (только `school_admin`, `catalog_curator`, `system_admin`).

---

## 5. Раздел будущих возможностей (Future Roadmap)

Эндпоинты, зарезервированные в целевой спецификации для следующих этапов и вынесенные в `x-future-endpoints`:
- `POST /api/v1/auth/forgot-password` — Сброс пароля по электронной почте.

---

## 6. Автоматизированное тестирование

### 6.1. Полный тестовый набор PHPUnit
Тестовый комплект покрывает авторизацию, мультиарендность, конструктор, генерацию, модерацию, sign-off, многоформатный экспорт (PDF, DOCX, PPTX, Print Pack), A/B-сравнение, пилотное исследование и контракт маршрутов:
```bash
docker compose exec app vendor/bin/phpunit
```
**Текущий статус:** `286 тестов, 1427 проверок (100% OK, 0 failures, 0 errors)`.

### 6.2. Автоматическая проверка ссылок матрицы покрытия дизайна
Скрипт проверяет, что 100% ссылок на тесты в матрице покрытия соответствуют реально существующим методам PHPUnit:
```bash
python scripts/verify_matrix_test_references.py
```

### 6.3. Автоматическая проверка соответствия маршрутов и OpenAPI
Скрипт проверяет строгое соответствие между зарегистрированными маршрутами Laravel (58 операций, 47 путей) и спецификацией OpenAPI 3.1.0:
```bash
python scripts/verify_route_contract.py
```

### 6.4. Проверка чистоты релиза и отсутствия секретов
```bash
python scripts/verify_archive_cleanliness.py
```

### 6.5. Валидация JSON Schema и семантики
```bash
python docs/validation/validate_schemas.py
```

### 6.6. Сквозной HTTP Gateway & Pilot Analytics аудит (Изолированный контур)

Для проведения сквозного HTTP-аудита и нагрузочных тестов используется **строго изолированный тестовый контур** (порты `8001` и тестовая БД `school_events_test`).

#### Обязательная подготовка тестового окружения (.env.testing):

1. **Создайте файл `.env.testing` из эталонного шаблона:**
   ```bash
   cp .env.testing.example .env.testing
   ```

2. **Сгенерируйте независимый криптографический ключ приложения (`APP_KEY`) для тестового контура:**
   ```bash
   docker compose exec app php artisan key:generate --env=testing --force
   ```
   *(Убедитесь, что в `.env.testing` записан валидный независимый `APP_KEY=base64:...`).*

3. **Настройте единый секрет доступа к тестовой базе данных:**
   - В `.env.testing` задайте:
     ```env
     DB_PASSWORD=your_secure_test_password
     ```
   - В основном `.env` (или переменных окружения хоста) передайте:
     ```env
     DB_TEST_PASSWORD=your_secure_test_password
     ```
   *`school_test_user` создаётся с тем же паролем, который сконфигурирован для `app-test`. Случайные рассинхронизированные пароли исключены.*

4. **Ключевые параметры изоляции в `.env.testing`:**
   - `APP_ENV=testing`
   - `APP_URL=http://localhost:8001`
   - `DB_DATABASE=school_events_test`
   - `DB_USERNAME=school_test_user`
   - `SESSION_DRIVER=redis`
   - `SESSION_COOKIE=school_events_test_session` (исключает коллизии сессионных кук с основным приложением)
   - `CACHE_PREFIX=school_events_test_cache_` (изоляция пространства кэша)
   - `REDIS_PREFIX=school_events_test_redis_` (изоляция пространства ключей Redis)
   - `REDIS_DB=1`, `REDIS_CACHE_DB=2` (выделенные базы Redis; основной контур работает на DB 0)
   - `FILESYSTEM_PRIVATE_ROOT=storage/app/testing/private` (изолированное файловое хранилище)
   - `QUEUE_CONNECTION=sync` (синхронное исполнение задач в тестовом цикле)

5. **Инициализация роли тестового пользователя в PostgreSQL:**
   ```bash
   bash scripts/setup_test_db_user.sh
   ```
   Скрипт идемпотентно создаёт базу данных `school_events_test`, заводит роль `school_test_user` с паролем из `.env.testing`, выдаёт полные права на тестовую базу и **гарантированно отзывает** права на подключение к рабочей базе данных `school_events`.

6. **Запуск тестового контура:**
   ```bash
   docker compose --profile testing up -d app-test nginx-test
   ```
   **Барьеры безопасности (Fail-Closed Guards):**
   - **Отсутствие `.env.testing`:** Контейнер `app-test` немедленно завершает работу с кодом `1` (`[FATAL FAIL-CLOSED] .env.testing is missing!`), предотвращая ошибочный запуск на конфигурации основного контура (`.env`).
   - **Отсутствие секрета `DB_TEST_PASSWORD`:** Основной контур (`app`, `postgres`, `redis`, `queue`, `nginx`) продолжает работать штатно, но `app-test` отказывается от запуска до безопасной настройки пароля.
   - **PHP Runtime Guard:** На уровне `AppServiceProvider` любой HTTP-запрос в `testing`-окружении блокируется с кодом `500`, если `.env.testing` отсутствует, пароль пуст или подключение направлено не в `school_events_test`.

7. **Запуск проверок безопасности и сквозного аудита:**
   ```bash
   # Проверка барьеров защиты (Negative Security Checks: отказ при отсутствии флага, отказ при атаке на school_events, отказ при расхождении среды)
   python scripts/test_http_audit_be008.py --verify-safety-guards

   # Полный живой аудит (20 шагов сквозной верификации)
   python scripts/test_http_audit_be008.py --allow-test-run --use-test-db
   ```

---

## 7. Справка по статусу проверки культурных источников (Provenance)

- **Проведённая нормативная верификация (Verified Authentic):**
  - *День народного единства* (4 ноября): нормативно подтверждён Федеральным законом от 29.12.2004 № 201-ФЗ (ст. 112 ТК РФ). Текст проверен по государственной эталонной базе Pravo.gov.ru. Зафиксирована утверждённая рецензия куратора. Статус: `verified_authentic`.
  - *День российской науки* (8 февраля): нормативно подтверждён Указом Президента РФ от 07.06.1999 № 717. Текст проверен по государственной базе Pravo.gov.ru. Зафиксирована утверждённая рецензия куратора. Статус: `verified_authentic`.
- **Демонстрационные региональные данные (Unverified):**
  - *День народов Среднего Урала* (Свердловская область, код 66): этнографические сведения о традициях Среднего Урала внесены как демонстрационные материалы. Академическая архивная и библиографическая экспертиза для них не завершена. Статус строго зафиксирован как `unverified` с явной пометкой «демонстрационные / ожидают проверки». В API `GET /api/v1/calendar-events/{id}` возвращается как `unverified`.
