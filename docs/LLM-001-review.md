# LLM-001 — Final technical review

**Статус: PENDING INDEPENDENT REVIEW.** Реальная интеграция подтверждена. Педагогическое качество не принято; ниже перечислены блокеры. Production rollout не выполнен, LLM-001 CLOSED не объявляется.

Дата: 30 октября 2026. Backend: ветка llm001-litellm от fe012-final-integration; baseline рабочей копии уже содержал незакоммиченные изменения FE-012. Frontend без Git. Исходные hashes и delta — llm001/*-baseline.json / *-changed-files.json. Прежние изменения сохранены и не приписываются LLM-001.

## Gateway и модель

Gateway https://litellmru.ds-hub-cf.ru/v1; действительные Swagger/OpenAPI исследованы. Авторизованный GET /v1/models — 200, без авторизации — 401. Ключ только в локальном backend .env.testing, исключённом из Git; headers/cookies/credentials не сохранялись в evidence.

Модели: EmbeddingsGigaR, GigaChat-2-Max, GigaChat-2-Pro, GigaChat-3-Pro, GigaChat-3-Lightning, frida-decisions, Qwen3.8-27B, GigaChat-2. Netology1 — имя доступа, не model_id.

Выбран GigaChat-2-Max: на длинных реальных ответах сохранил все три масштаба. Inventory Qwen раскрыл 262144 context/output, но полные ответы не уложились в первоначальный 90-секундный бюджет даже с thinking off. Короткие schema-пробы GigaChat-3-Pro/Lightning прошли, полные ответы нестабильны. Единичный ответ Pro за 22.6 с не считался доказательством устойчивости. Отклонённые discovery-v102 / discovery-v110-max / discovery-v110-pro сохранены и НЕ PASS.

Giga json_object вернул настоящий 400 с требованием json_schema. Выбран явный response_format=json_schema; gateway принимает полную исходную Draft 2020-12 schema, но не гарантирует required/minItems. Серверная проверка обязательна. JSON mode не переключается молча, fallback на mock отсутствует.

POST /v1/chat/completions: model=GigaChat-2-Max, messages(system/user), temperature=0.3, max_tokens=8000, полная response_format.json_schema. Connect=10 с, HTTP=120 с, общий бюджет варианта=240 с, repair=1, HTTP attempts<=2 только при 429/5xx, Retry-After<=5 с. Network/неоднозначный timeout автоматически не повторяются. Usage только фактический (включая repair), отсутствующий остаётся null. Cached prompt tokens не заменялись оценкой. Цена неизвестна, стоимость не вычислялась.

Реальные обезличенные messages и output — generationRuns.sanitized_prompt / sanitized_response в postgres-live.json. gateway-http.jsonl содержит status/model/finish_reason/usage/transaction_level без Authorization или полного upstream body. Ошибки 401/403/429/5xx/network/JSON/schema/truncation покрыты детерминированными HTTP fake tests; live 429/5xx намеренно не провоцировались.

## Архитектура

ScenarioRequest → GenerationRun → LiteLlmProvider → JSON Schema → semantic/request/material validation → normalization → atomic Scenario/Revision v1 → automatic moderation. Все 18 chat-записей текущего live run имеют transaction_level=0. Model/usage приходят из реального вызова. Исходные schema, GenerationOutputValidator, ScenarioSemanticValidator, ServerNormalizationService и ProvenanceVerificationService не изменены: core-contract-hashes.json. Новых миграций нет.

Промпт поставки school-methodist-1.2.1. Масштабные образцы и культурная пара получены 1.2.0; успешная универсальная пара — 1.2.1. Старые metadata не переписаны. Каркас создаётся из полной schema, задаёт числовые параметры и ссылки; модель заменяет все __LLM_WRITE_*__ содержательным текстом. Незаполненный каркас отвергается.

LiteLLM A/B: POST 201 pending → GET polling → completed/failed. Четыре режима имеют разные инструкции. Внешние запросы выполняются вне transaction; два варианта записываются атомарно после проверки. Отдельный GenerationRun для каждого варианта сохраняет и частичный usage. Mock остаётся синхронным. UUID Idempotency-Key ограничен user/school; terminal jobs не вызывают модель повторно. Сохранены RBAC, tenant isolation, immutable selection и отзыв evidence. LLM не назначает verified/approved.

## Live acceptance

Только localhost:8001 → app-test → school_events_test / school_test_user; синтетические школы/пользователи. Queue llm001-8ff5456b98 и отдельный Redis prefix. Production school_events не мигрировалась/не заполнялась. Реальные CSRF/login/auth-me/tenant/generate/idempotent replay/GET polling/moderation и cross-school 403 выполнены через Nginx.

| Масштаб | Минут | Генерация | Latency ms | Prompt / completion tokens | Автомодерация |
|---|---:|---|---:|---:|---|
| class_level | 45 | completed | 105574 | 10619 / 5274 | verification_passed |
| all_school | 60 | completed | 44748 | 7040 / 2537 | verification_passed |
| family_community | 90 | completed | 124145 | 12330 / 5081 | verification_passed_with_warnings |

Каждый создал настоящие Scenario и ScenarioRevision v1. Raw outputs повторно прошли текущие schema/semantic/request/material validators без HTTP: quality-samples.json, revalidate.exit=0. Human sign-off не выполнялся. API attempts — прикладной счётчик, не полное число chat HTTP; для учета вызовов использовать gateway audit.

A/B cultural_context: 01a18d15-e95d-73ea-b97c-b5156eaf5d36 — completed (1.2.0). A/B universal_vs_personalized: 01a18d28-6b6d-73ce-92e8-7392c57ce006 — completed (1.2.1).

Универсальная пара до успеха имела три failed attempts. Первый false positive: заполненная таблица без слова «пример». Gate теперь распознаёт две заполненные строки, продолжая отвергать пустые бланки; есть regression test. Первая корректирующая попытка использовала старый долгоживущий worker; вторая после restart дала действительно неполный пример. Промпт 1.2.1 уточнил требования, последняя пара прошла. Failed runs не удалялись/не переименовывались. live-http-initial.json и live-http-correction-*.json сохраняют историю; live-http.json — накопленный итог со ссылками. Успешные масштабы не регенерировались ради зелёного флага.

Культурный B привязан к настоящему публичному акту: Федеральный закон от 29.12.2004 №201-ФЗ, http://pravo.gov.ru/proxy/ips/?docbody=&nd=102090226. Локатор: «Статья 1: изменение статьи 112 ТК РФ, абзац о 4 ноября». Claim: «4 ноября — День народного единства в Российской Федерации». Reviewer синтетический: проверена программная привязка, не настоящая этнографическая экспертиза.

live-provenance-selection.json: выбор A — 200, смена на B — 409. До отзыва cultural availability=true; DELETE evidence — 200; затем availability=false и выбор B — 409. Это намеренно сохранённое конечное состояние пары. Logout — 204, auth/me после выхода — 401.

После restart повторно доставлены в свою очередь 12 terminal GenerationRun jobs и 5 Comparison jobs. Drain worker exit=0. До/после: 18 gateway calls, 7 scenarios, 7 revisions, 12 runs, 5 comparisons. Новых платных вызовов/дублей нет: redelivery-before/after/dispatched.json. Это replay терминальных jobs, не chaos-test убийства процесса посреди платного HTTP.

## Качество и Mock на тех же входах

Это чтение JSON исполнителем, не независимая педагогическая экспертиза. В quality-samples.json сохранены live outputs и локальный Mock на тех же ScenarioRequest; происхождение указано явно.

| Критерий | Class 45 | All school 60 | Family 90 |
|---|---|---|---|
| Русский | Связный, шаблонный местами | Связный | Связный |
| Возраст 5–6 | Инструкции понятны | Задания местами слишком просты | Яблоки/груши слишком просты |
| Хронометраж | Сумма 45 | Сумма 60 | Сумма 90 |
| Исполнимость | Механизм взаимозависимости слабый | 150 человек требуют конкретной логистики | Семейный формат заявлен, задания в основном математические |
| Материалы | 2 карточки, 3 слайда, пример | 2 карточки, 3 слайда, пример | 2 карточки, 3 слайда, пример |
| Ресурсы | Бумага/карандаши/доска | Базовые; уточнить потоки/роли | Проверить альтернативу без родственников |
| Cultural claims | Пусто | Пусто | Пусто |
| Качество принято | Нет | Нет | Нет |

Обнаруженные дефекты, пропущенные автоматической модерацией:

- Class: открытки 15, сувениры 30, бюджет 120 и хотя бы один каждого вида. Модель объявила максимум 6 товаров (4+2), правильный максимум 7 (6+1). Задача про 2 часа требует 135 минут; ответ не объясняет невозможность уложиться в 120.
- All school: объяснение яблок/корзин внутренне противоречиво. Деление 12 конфет между 4 друзьями не даёт достаточной взаимозависимости для этого возраста.
- Family: x=2 и остаток фруктов верны, но задания тривиальны; «один решает левую, другой правую часть» методически спорно. Для 90 минут семейного workshop наполнения недостаточно.
- В handouts встречаются буквальные слеш-n, иногда удвоенные, остатки форматирования. JSON валиден, печатный материал требует редактуры.
- **Культурный блокер:** B добавил в слайд/speaker_notes историю 1612 года, Минина и Пожарского, хотя trusted claim подтверждал только дату праздника. Existing gate проверяет declared claims, но не обнаруживает все незаявленные исторические утверждения свободного текста. Нельзя выдавать весь B за подтверждённый источниками или заявлять отсутствие культурных галлюцинаций. Программная привязка и live revoke прошли; полнота исторической проверки — нет.

Реальная модель дала более подробные две карточки с ответами и заполненный пример; Mock содержит одну общую заготовку. Mock детерминирован и лучше различает организационные каркасы масштабов (станции/маршруты/семейная лаборатория), но его семейная личная история не подходит заданному ограничению. Ни Mock, ни длина текста не являются эталоном методического качества. JSON/HTTP success не означают полной приёмки генератора.

## Проверки и UI

Backend baseline 292 tests /1485 assertions; финальный PHPUnit 338 PASS /1694 assertions. OpenAPI route contract (59 routes) входит в полный прогон. До исправления factory один прогон упал на коллизии Faker email с накопленными данными; теперь UUID@example.test. Тестовые данные не удалялись.

Frontend baseline 601 → 605 Vitest PASS, 21 files. Typecheck, ESLint, production build, scoped Pint, JSON Schema/OpenAPI validator — PASS. Финальные логи/exit — llm001/*.log и *.exit. Discovery/initial failed logs не являются финальными PASS.

Nuxt точечно поддерживает async pending/failed/completed, UUID idempotency, фактическую модель; variants до completed не запрашиваются. Сообщение failure безопасное для пользователя; исправлены переводы сроков. Реальные три результата и обе пары просмотрены после входа при 1440×1000 и 390×844; в измеренных состояниях горизонтального overflow нет (browser-checks.json). Pending подтверждён HTTP trace и frontend test; достоверный screenshot pending не получен, ошибочно названный файл переименован как failure. Figma не менялась, интерфейс не переписывался.

## Воспроизведение и поставка

См. llm-provider-integration.md и queues-llm001.md. Установить vendor через composer.lock, frontend через npm ci. Создать локальные env из example; DB/Redis/ключ задать в runtime secrets. После config:cache или изменения prompt перезапустить PHP-FPM/worker. PHPUnit использует mock/sync и отдельный APP_CONFIG_CACHE. Три масштаба не повторялись на 1.2.1: их доказательства относятся к 1.2.0. Малый набор не даёт статистической гарантии устойчивости.

Два ZIP содержат исходники, schema/OpenAPI, prompts, тесты, UI-kit, документы и обезличенные live/mock образцы. Исключены настоящие env, config caches, secrets, vendor, node_modules, .git, .nuxt, .output. В каждом LLM001-MANIFEST.json — hashes файлов. CRC/secret scan/hash verification выполняются при сборке и отдельной read-only проверкой. SHA-256 ZIP вынесены во внешние .zip.sha256 / release-verification.json, чтобы не создавать циклический hash внутри отчёта.

Оставшиеся условия приёмки: независимая педагогическая проверка, исправление заданий и логистики масштабов, выявление незаявленных исторических фактов во всём тексте, редактура печатного форматирования. Production LLM не включён. Поставка предназначена для независимого ревью с перечисленными блокерами.


## Итоговое состояние окружения и проверка архивов

app-test пересоздан обычным docker-compose.yml без live override: testing / school_events_test / school_test_user / provider=mock / queue=sync (runtime-restored.json). Свои live workers остановлены, drain завершён. Frontend dev снова использует API localhost:8000. Ключ остался только локально в ignored .env.testing. Production-конфигурация не переключалась. Production server smoke на 127.0.0.1:3100/login дал HTTP200, __nuxt root и runtime API localhost:8000; временный smoke server остановлен.

Воспроизведение live harness предполагает соседние каталоги `school` (backend) и `schoolFrontend` (frontend); после распаковки используйте эти имена или скорректируйте относительные пути команд. Полный live harness создаёт новые платные запросы и требует явного LLM001_LIVE=1; для чтения сохранённых результатов запуск не нужен. Исходные fixture IDs относятся к локальной test DB, которой нет в source ZIP. Для нового контура сначала выполните guarded fixtures script и используйте новый runId/свою очередь. Не переносите локальные passwords или ключ в архив.

Сборка: `python scripts/package-llm001.py` из frontend; дополнительная проверка уже сформированных ZIP: `python scripts/verify-llm001.py`. Обе загружают локальные secrets только в память для поиска, не выводят их. Сканируются известные env secrets и HMAC fixture passwords, private key markers, invitation tokens, а также вложенные Office ZIP. Это проверка известных шаблонов, не математическое доказательство отсутствия любых неизвестных секретов.

Весь независимый приёмочный статус остаётся PENDING INDEPENDENT REVIEW. Отдельный read-only verifier написан исполнителем и не заменяет независимого ревьюера. Финальный verdict по педагогическому качеству — не принято. Из поставки исключены два сырых диагностических PHPUnit лога с SQL/synthetic credential hashes; диагноз коллизии сохранён выше, исходные логи оставлены локально. Model failures и genuine live HTTP evidence сохранены. test-fixtures.json — начальные IDs, его provisioning marker не используется как acceptance verdict; итог определяется live-http.json и дополнительными evidence.
