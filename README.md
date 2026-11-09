# Школьные события

Платформа подготовки школьных мероприятий: календарь → конструктор → проект сценария → ревизии и проверка → подтверждение педагогом → PDF/DOCX/PPTX. Есть школьные традиции, A/B-сравнения и проверка культурных источников с изоляцией доступа между школами.

**Работающий сайт:** <https://school-events.ru/>. **DEPLOY-001 CLOSED — PUBLIC HTTPS VERIFIED**: [публичная приёмка](docs/DEPLOY-001.1-final-acceptance.md). Техническая готовность не означает педагогическую экспертизу: production использует MockLlmProvider, материалы требуют доработки и проверки.

## Репозитории и передача

- Основные: [GitLab backend](https://gitlab.com/Brossend2335/school-events-backend), [GitLab frontend](https://gitlab.com/Brossend2335/school-events-frontend).
- Зеркала: [GitHub backend](https://github.com/Brossend/school-events-backend), [GitHub frontend](https://github.com/Brossend/school-events-frontend). Репозитории закрыты; доступ к ним выдаёт владелец отдельно.
- [Демонстрация](docs/HANDOFF-001-demo-guide.md), [архитектура](docs/HANDOFF-001-architecture.md), [ограничения](docs/HANDOFF-001-limitations.md), [итоговый отчёт](docs/HANDOFF-001-final-report.md).
- [Файл для УрФУ ИМО](УрФУ%20ИМО%20—%20Школьные%20события%20—%20промежуточные%20результаты.txt). Каждый участник заполняет состав команды и загружает файл самостоятельно.

## Технологии

Backend: Laravel 13 / PHP 8.4, Sanctum sessions, PostgreSQL 16, Redis 7, Nginx. Frontend: Nuxt 4 / Vue 3 / TypeScript / Pinia; SSR страниц входа, клиентский кабинет. Production: Docker Compose, Caddy HTTPS, GitLab Registry и manual deployment из protected main. Полные image SHA фиксируются парой backend/frontend.

Production backend: `ea1a51b634a0e5b3c4acea7360e3d19ff2256b73`; frontend: `9aee989fb0cb040bb4855d7cfc11a612fecae396`. Последующие коммиты документации не заменяют эти образы автоматически.

## Локальный запуск backend

Для чистой практики UX-001 используйте отдельное окружение без demo-seeder:

```powershell
python scripts/ux001-local.py
python scripts/ux001-local.py --start
docker exec school-ux001-app-1 php artisan practice:calendar
docker exec school-ux001-app-1 php artisan practice:calendar --apply --no-interaction
docker exec -it school-ux001-app-1 php artisan practice:bootstrap --email=admin@example.test --school="Моя школа"
docker exec school-ux001-app-1 php artisan practice:inspect --dry-run
```

API доступен на `http://localhost:8020`; frontend запускается отдельно на localhost:3030 с этим API. Первый пароль вводится скрыто при bootstrap, последующие педагоги регистрируются по одноразовым приглашениям. Приватный env хранится вне репозитория в `~/.codex/ux001/practice.env`. Команды ограничены отдельной local/testing PostgreSQL БД; существующие базы и volumes не очищаются. Календарь содержит 36 проверенных дат, а не обязательный план конкретной школы. См. [отчёт UX-001](docs/UX-001-final-report.md).

Следующий исторический запуск предназначен для синтетического demo, а не для чистой практики UX-001.

Нужны Docker Engine/Desktop и Compose v2. Команды выполняются в этом репозитории:

```bash
cp .env.example .env
# В .env задайте собственные DB_PASSWORD, DB_TEST_PASSWORD и DEMO_ADMIN_PASSWORD,
# DEMO_TEACHER_PASSWORD, DEMO_DIRECTOR_PASSWORD (demo secrets минимум 24 символа).
# Оставьте APP_ENV=local и LLM_PROVIDER=mock.
docker compose up -d --build
docker compose exec app composer install --no-interaction --prefer-dist
docker compose exec app php artisan key:generate --force --no-interaction
docker compose exec app php artisan migrate --force --no-interaction
docker compose exec app php artisan db:seed --class=ProductionDemoSeeder --force --no-interaction
curl http://localhost:8000/api/v1/health
```

В PowerShell используйте `Copy-Item .env.example .env`. ProductionDemoSeeder создаёт только синтетические школы alpha/beta; он идемпотентен и не сбрасывает изменённые пароли. Worker запускается Compose. Старый DatabaseSeeder предназначен для local/testing и не запускается на production. Frontend запускается отдельно по [его README](https://gitlab.com/Brossend2335/school-events-frontend/-/blob/main/README.md).

## Проверки и эксплуатация

Не запускайте тесты на production: для них предусмотрена отдельная `school_events_test` и testing profile. [Запуск и изоляция](https://gitlab.com/Brossend2335/school-events-frontend/-/blob/main/docs/FE-012-launch.md), [OpenAPI](docs/openapi.yaml). Последний production backend pipeline: [3041864605](https://gitlab.com/Brossend2335/school-events-backend/-/pipelines/3041864605), 408 tests / 1902 assertions. В HANDOFF полная регрессия не повторялась.

[Runbook](docs/DEPLOY-001-runbook.md) описывает обновления через GitLab, backup и rollback. Секреты находятся вне Git; пароли проверяющему передаются приватно владельцем. Встроенная кнопка демовхода не заменяет выданный аккаунт. [Реальная LiteLLM-интеграция отдельно проверена](docs/LLM-001.3-live-result.md), в production не включена.
