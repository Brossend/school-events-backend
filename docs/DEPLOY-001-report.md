# DEPLOY-001 — отчёт

Дата: 8 ноября 2026. Статус: **DEPLOYED — DNS/TLS PENDING**. Серверное развёртывание и повторный deploy выполнены; публичный DNS/TLS заблокирован отсутствием делегирования в .ru.

## Зафиксированные версии и CI

- Backend: `ea1a51b634a0e5b3c4acea7360e3d19ff2256b73`, [pipeline 3041864605](https://gitlab.com/Brossend2335/school-events-backend/-/pipelines/3041864605), 408 tests / 1902 assertions и Laravel build прошли; container build повторён после сетевых ошибок загрузки Composer.
- Frontend: `9aee989fb0cb040bb4855d7cfc11a612fecae396`, [pipeline 3041847464](https://gitlab.com/Brossend2335/school-events-frontend/-/pipelines/3041847464), все 7 jobs прошли, включая tests, container image и GitHub mirror.
- Registry: `registry.gitlab.com/brossend2335/school-events-backend:<backend SHA>` и `registry.gitlab.com/brossend2335/school-events-frontend:<frontend SHA>`.
- Пара совместимых версий задаётся backend commit и `docker/production/frontend-sha.txt`.
- Первый реальный [deploy job 17841694471](https://gitlab.com/Brossend2335/school-events-backend/-/jobs/17841694471) завершён успешно в 12:24:24 UTC. Backend image digest: `sha256:74529ff99e2146a51e7ab7653afb5109cdc869a8ba504124bfbe93cac6a9150d`.
- Повторный deploy той же пары через GitLab: [job 17841892485](https://gitlab.com/Brossend2335/school-events-backend/-/jobs/17841892485), успешно в 12:27:42 UTC. Автоматически создан backup `20261108T122723Z`, миграций к применению не осталось. После deploy повторно проверены вход, существующая Revision 2, синтетическое A/B и checksum скачивания всех трёх экспортов. `current.env` и `previous.env` фиксируют эту проверенную пару.
- Frontend image digest: `sha256:4ba1850a1f7999e39d43644de19b097a6f80fc489c3115eaf768a84e2fa25e45`. Документация коммитится отдельно после проверок; её commit не означает автоматическую замену развёрнутого image SHA.

## VPS

`87.242.87.93`, SSH владельца `user1`, sudo доступен. Фактическая ОС Ubuntu 22.04.5 LTS (jammy), а не указанные в задании 24.04; обновление major ОС не выполнялось. 2 CPU, RAM 3911 MiB, диск около 30 GB, добавлен swap 2 GiB. Установлены Docker Engine 29.9.3, Compose 5.7 и security updates. UFW разрешает SSH/HTTP/HTTPS; firewall провайдера из панели не проверен. До начала работ приложение и другие контейнерные службы отсутствовали.

Доступ CI: отдельный пользователь `deploy`, отдельный ed25519 key, forced SSH command принимает только `deploy <40hex BE> <40hex FE>`; sudo разрешён только проверяющему аргументы deployment script. Host key проверяется строго. Registry Deploy Tokens имеют только read_registry и срок до 08.11.2027.

Реально запущены `school-events-app-1`, `worker-1`, `frontend-1`, `nginx-1`, `postgres-1`, `redis-1`, `proxy-1` (все с префиксом `school-events-`). Шесть сервисов имеют успешные health checks; Caddy работает, маршрутизация проверена HTTP-запросами. PostgreSQL, Redis и PHP-FPM не опубликованы на host. Снаружи слушаются 22/80/443; диагностический 8080 привязан только к 127.0.0.1. После запуска: около 442 MiB RAM занято, 3114 MiB доступно, swap не используется; на диске около 20 GB свободно.

## DNS / HTTPS

Проверено с VPS: `.ru` authority `a.dns.ripn.net` возвращает NXDOMAIN. `ns1.reg.ru` и `ns2.reg.ru` отвечают авторитативно, A apex и www равны `87.242.87.93`, ошибочных AAAA нет. Cloudflare 1.1.1.1 и Google 8.8.8.8 возвращают NXDOMAIN. Зона есть, делегирования в родительской зоне нет. Действующий публичный TLS пока не подтверждён.

Владелец: REG.RU → домен school-events.ru → проверить завершение регистрации/оплату/статус домена и включить делегирование на ns1.reg.ru, ns2.reg.ru. Если интерфейс уже показывает делегирование, обратиться в поддержку REG.RU с фактом NXDOMAIN от a.dns.ripn.net и корректными ответами обоих REG.RU NS. Записи A менять не требуется.

## Проверки

Локально production Docker image backend собран; production demo seeder: 2 tests, 5 assertions прошли; Pint прошёл. Frontend build/typecheck/lint и CI tests прошли.

На реальном VPS через зашифрованный SSH и серверный loopback выполнены:

1. `/login` возвращает серверный HTML с формой; `/api/v1/health` подтверждает подключение PostgreSQL/Redis. SSR проверен для auth-страницы, кабинет сохраняет существующий CSR.
2. ProductionDemoSeeder создал две синтетические школы, пять пользователей, события, традиции и непроверенную культурную фикстуру.
3. Вход обоих педагогов, выбор школы, календарь, Secure/HttpOnly/SameSite=Lax session cookies; вход без CSRF отклонён 419. Доступ педагога к system status и чужой школе отклонён 403. Чужие сценарии/экспорты недоступны.
4. Constructor → ScenarioRequest → очередь Redis → MockProvider → Scenario и Revision 1. Редактор создал Revision 2. Автоматическая проверка прошла, P0=0; тестовый Sign-Off подтверждён с явной пометкой, что это не педагогическая экспертиза.
5. PDF (1296386 bytes), DOCX (11880 bytes), PPTX (22289 bytes) созданы worker и скачаны; сигнатуры форматов и SHA-256 совпадают с заголовком ответа.
6. Синтетическое A/B завершено, доступны два варианта. Культурный B с непроверенными источниками помечен недоступным, попытка выбора отклонена 409 CONFLICT.
7. После `docker compose restart` количества записей всех 37 public tables и checksum всех шести созданных экспортных файлов сохранились.
8. Backup `/opt/school-events/backups/20261108T122632Z` прошёл checksum verification. Восстановлен в отдельные `school_restore_20261108122633` и Docker volume `school_events_restore_20261108122633`: совпали количества записей 37 таблиц и checksum шести файлов. Production БД/volume не заменялись.
9. Ежедневный `school-backup.timer` активен: 02:15 UTC (05:15 в часовой зоне VPS).

Серверные результаты: `/opt/school-events/smoke-result.json` и `/opt/school-events/persistence-result.json` (root-only). При уточнении smoke-скрипта исправлены его предположения о кодах API и кодировка передачи; приложение и защитные gates не ослаблялись. Остались только синтетические тестовые записи.

LLM_PROVIDER=mock. Production app/worker находятся во внутренней Docker network без прямого выхода в интернет. Внешние LLM вызовы не выполнялись. Создаются только явно обозначенные синтетические данные.

## Доступ и дальнейшая эксплуатация

Плановый адрес: `https://school-events.ru/`; сейчас публичная доступность и валидный TLS не подтверждены. Диагностический адрес доступен только на VPS: `http://127.0.0.1:8080`, обращаться через SSH. Авторизацию по публичному IP HTTP не использовать.

Обновление и rollback описаны в [runbook](DEPLOY-001-runbook.md), ограничения — в [known limitations](DEPLOY-001-known-limitations.md). Production env хранится в `/opt/school-events/secrets/backend.env`, DB owner credentials — в `postgres.env` рядом; права 600, каталог 700/root. Локальные защищённые копии: `C:/Users/bross/.codex/deploy001-ci/`, вне Git. Значения секретов не включены в репозитории и отчёт.

В GitLab уже добавлены deployment variables и registry access; переносить полный production env в CI не требуется. Владелец должен исправить делегирование в REG.RU, затем проверить публичный TLS и основной маршрут в браузере; также организовать внешнюю защищённую копию backup.
