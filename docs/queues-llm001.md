# LLM-001: очереди и безопасный запуск

В поставке provider по умолчанию mock. Реальный ключ задаётся только в локальном runtime secret storage; `.env.testing` исключён из Git. Не копировать config cache в ZIP.

Для реального запуска нужны HTTPS gateway, точный LITELLM_MODEL из `/v1/models`, LLM_PROVIDER=litellm, LITELLM_RESPONSE_MODE=json_schema, Redis и отдельный worker. После изменения config выполнить config:cache и перезапустить PHP-FPM/worker. Изменение файла промпта также требует перезапуска долгоживущего worker. Рабочий контур при проверке не переключался.

Проверенный изолированный контур: localhost:8001 → app-test → school_events_test (school_test_user), queue `llm001-8ff5456b98`, prefix `school_llm001_8ff5456b98_`. Override находится в frontend/tests/backend/llm001-compose.override.yml. До запуска задать LLM001_MODEL=GigaChat-2-Max, LLM001_QUEUE и LLM001_REDIS_PREFIX. Запуск compose из backend с `-f docker-compose.yml -f ../schoolFrontend/tests/backend/llm001-compose.override.yml up -d --no-deps app-test`; затем config:cache внутри app-test. Используется APP_CONFIG_CACHE=/tmp/llm001-config.php; не общий bootstrap/cache/config.php двух окружений одного bind mount.

Guarded worker: `docker compose exec -T -e LLM001_RUN=8ff5456b98 app-test php scripts/llm001-worker.php`. Он отказывается стартовать при неверных DB, role, queue, environment, provider или retry_after. `--drain` завершает процесс после опустошения своей очереди. Не запускать этот acceptance worker на production DB.

| Предел | Секунд |
|---|---:|
| Connect timeout | 10 |
| HTTP request | 120 |
| Общий бюджет варианта, включая repair/retry | 240 |
| ExecuteGenerationRunJob | 300 |
| GenerateComparisonJob / queue worker | 600 |
| Redis retry_after | 900 |

Одна repair-попытка заново проходит всю схему, семантику и проверки материалов. До двух HTTP-попыток разрешены только для 429/5xx в рамках общего бюджета. Retry-After ограничен 5 секундами. Неоднозначный network/timeout не повторяется автоматически, чтобы не удваивать оплату. Повтор job терминального run/comparison ничего не генерирует. Частичный usage сохраняется при отказе пары; completed требует атомарной записи двух вариантов.

Idempotency-Key (UUID) повторяет прежний результат в пределах user/school. Новый ключ означает явно новый запрос. При незавершённом/неизвестном результате сначала перечитать GET. Failed не превращается в completed вручную. Удалять сохранённые отказавшие runs для зелёного отчёта запрещено.

PHPUnit использует mock/sync и изолированный APP_CONFIG_CACHE=/tmp/llm001-phpunit-unused.php. HTTP fakes применяются только в детерминированных тестах. Live harness отдельно требует LLM001_LIVE=1, проверяет test probe и сохраняет evidence без cookies/credentials.

После acceptance остановить только свой worker, пересоздать app-test обычным docker-compose.yml без override, перезагрузить nginx-test; вернуть frontend API origin на localhost:8000. Тестовые записи остаются для аудита. Production migration/seed не выполняются.

LLM-001.1, 31 октября: после повторных фактических LLM_TIMEOUT на120с разрешён конфигурируемый HTTP timeout до180с. В контрольном retry app-test установлен180; default остаётся120. Общий бюджет варианта240, job300, comparison/worker600, Redis retry_after900 не увеличены. На repair остаётся остаток общего бюджета. Неоднозначный timeout по-прежнему не повторяется автоматически. Только terminal failed случаи повторяются явным новым Idempotency-Key один раз в bounded corrective harness; исходные HTTP receipts, failed runs и unknown usage сохраняются. Эта настройка не включает production LLM.


LLM-001.1, окончательный профиль1 ноября: HTTP240с/total360с; generation job420с, comparison и worker840с, Redis lease1200с. Running recovery480с не конфликтует с420с job. Ранние120/240 и180/240 описывают предыдущие попытки; их receipts сохранены. Default HTTP120/total240/repair1; acceptance явно repair2, максимум3 completions на вариант внутри одного total budget. Ключ и config cache не входят в поставку. Production provider остаётся mock.
