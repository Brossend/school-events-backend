# LLM-001: LiteLLM integration

Статус: PENDING INDEPENDENT REVIEW. Реализованы `mock` и `litellm`. Другие провайдеры не реализованы. Полный отчёт: `LLM-001-review.md`.

## Конвейер

ScenarioRequest → GenerationRun → Redis ExecuteGenerationRunJob → LlmProviderInterface → GenerationOutputValidator → ScenarioSemanticValidator → RequestedScenarioValidator / ScenarioQualityValidator (LiteLLM) → ServerNormalizationService → атомарные Scenario + ScenarioRevision v1 → автоматическая модерация. Существующие схемы и Provenance Gate сохранены.

`LiteLlmProvider` использует Laravel HTTP Client, HTTPS с обычной проверкой сертификата, запрет redirects, Bearer только в заголовке. Выполняется `POST {base_url}/chat/completions`, читаются реальный model, choices[0].message.content, finish_reason и usage. При отсутствии usage сохраняется null. Стоимость не вычисляется. Никакого fallback на mock: ошибка ключа, модели или конфигурации явная; переключение pending live-задания на mock блокируется.

## Настройка

Runtime secrets находятся только в локальном .env / .env.testing или secret storage. Примеры не содержат ключа.

```
LLM_PROVIDER=mock
LITELLM_BASE_URL=https://litellmru.ds-hub-cf.ru/v1
LITELLM_API_KEY=
LITELLM_MODEL=GigaChat-2-Max
LITELLM_RESPONSE_MODE=json_schema
LITELLM_DISABLE_THINKING=false
LITELLM_TEMPERATURE=0.3
LITELLM_MAX_TOKENS=8000
LITELLM_CONNECT_TIMEOUT=10
LITELLM_TIMEOUT=120
LITELLM_TOTAL_TIMEOUT=240
LITELLM_REPAIR_PASSES=1
LITELLM_MAX_ATTEMPTS=2
LITELLM_MAX_RETRY_WAIT=5
REDIS_QUEUE_RETRY_AFTER=900
```

`LLM_PROVIDER=litellm` включался только на app-test. В рабочем контуре оставлен mock. Docker services app/queue явно получают свой .env через env_file; app-test получает отдельный .env.testing, DB/role остаются принудительно тестовыми. После обычного изменения config в deployment выполните config:cache и перезапустите PHP-FPM/queue worker. Не используйте общий config cache для разных контуров одного bind mount: изолированный acceptance использует APP_CONFIG_CACHE=/tmp/llm001-config.php. Сам файл cache содержит secrets и в архив не входит.

## Структурированный вывод и repair

Полная действующая generation-output.schema.json передаётся модели, собственной упрощённой схемы нет. Выбран явный json_schema. Gateway принимает этот параметр, но диагностика GigaChat-3 показала, что decoder не гарантирует всех minItems/required; серверные проверки обязательны. json_object поддержан только как явная конфигурация, автоматического переключения нет. Для Qwen отдельно проверен chat_template_kwargs.enable_thinking=false; полноценные ответы этой модели не уложились в пробные лимиты. В выбранной GigaChat-2-Max этот параметр не отправляется.

Промпты версионированы в resources/prompts. Действующая версия school-methodist-1.3.1. Внешние названия/ограничения/библиография передаются как JSON-данные, отдельно от доверенной системной инструкции. Имена и email пользователя не передаются. Только живые выбранные verified evidence входят в культурный контекст; нормализация и модерация повторно проверяют привязку claim/source.

После синтаксического JSON выполняются схема, семантика, соответствие параметрам запроса и готовность материалов. При структурных/семантических ошибках возможен ровно один исправляющий запрос с реальными ошибками валидатора и прежним JSON. Новый ответ проходит все проверки заново; сервер не дописывает карточки или факты вместо модели. Два успешных usage суммируются. При нераскрытом usage общий счётчик null, известные части остаются в sanitized_prompt audit. Невалидный/обрезанный JSON не чинится скрытым mock. Материалы не проходят gate при коротких заглушках, отсутствии двух карточек, трёх слайдов или готового раздаточного примера. Этот gate не заменяет экспертную оценку педагогической уместности.

## Ошибки

401/403 → LLM_AUTHENTICATION_FAILED; 400/422 и другие отказы → LLM_REQUEST_REJECTED; 429 → LLM_RATE_LIMITED; 5xx → LLM_UPSTREAM_UNAVAILABLE. Неоднозначная ошибка сети/timeout не повторяется автоматически, поскольку запрос мог быть оплачен. Для 429/5xx максимум две попытки, Retry-After учитывается без раннего повтора; если задержка больше пяти секунд или бюджета, задача завершается явной ошибкой. Empty, invalid JSON, truncated, content filter и validation имеют отдельные безопасные коды. Тела upstream errors, Authorization и stack traces пользователю не передаются.

## A/B

ComparisonGenerationService использует VariantLlmProviderInterface: universal, personalized, without_cultural_context, with_cultural_context. Инструкции различаются; все результаты проходят общий строгий конвейер. Для LiteLLM POST возвращает 201 pending, дальше GET до completed/failed. Внешний HTTP запрещён при открытой DB transaction; подготовка run identity и финальная запись — отдельные короткие транзакции. Половина пары не публикуется как completed. Для каждого платного варианта есть собственный GenerationRun; частичный usage сохраняется даже при отказе пары. Без provenance культурный B не генерируется.

Необязательный UUID Idempotency-Key повторяет ту же пару в рамках пользователя/школы; конфликт параметров даёт 409. Без ключа повтор pending переиспользует ожидающую пару. Nuxt передаёт ключ, читает pending без преждевременного запроса variants, опрашивает GET и показывает terminal error. Выбор остаётся immutable, RBAC/tenant и live revoke сохраняются.

## Проверки и воспроизведение

Детерминированные PHPUnit-тесты всегда начинают с mock/sync, даже если test-контейнер обслуживает live acceptance. Специальные LiteLLM tests устанавливают fake HTTP явно; случайные внешние запросы запрещены. Настоящий live прогон запускается отдельно через frontend/tests/llm001.live.mjs, LLM001_LIVE=1, только localhost8001/school_events_test/school_test_user и отдельный Redis prefix. Guarded worker — scripts/llm001-worker.php. Версии payload/usage и реальные HTTP status сохраняются без заголовков. См. queues-llm001.md и итоговый отчёт.

Реальная проверка выявила ограничения качества и незаявленные исторические факты в свободном тексте. См. LLM-001-review.md; declared-claim provenance не удостоверяет весь документ. Production rollout не выполнен.

LLM-001.1: после JSON parsing разрешена только узкая lossless-коррекция переносов печатных полей с raw audit. Затем исходные validators и дополнительный PedagogicalQualityGate. Качество и полнота cultural coverage остаются requires_review. См. quality-gate-llm001-1.md.
