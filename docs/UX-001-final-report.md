# UX-001 — backend handoff

Статус: **UX-001 IMPLEMENTED — PENDING INDEPENDENT REVIEW**. 9 ноября 2026 года.

Полный отчёт с матрицей всех 11 замечаний, источниками, screenshots, ограничениями и инструкцией приёмки: [frontend docs/UX-001-final-report.md](../../schoolFrontend/docs/UX-001-final-report.md). В локальном комплекте репозитории располагаются рядом. Этот файл фиксирует backend часть; CLOSED не присваивается.

В обоих репозиториях используется feature/ux-001-teacher-experience. Production, main, VPS, DNS, secrets и общие volumes не изменялись; push/merge/deploy не выполнялись.

## Изменения и доказательства

Реализованы teacher-only приглашения с SHA-256 кода, семидневным сроком, отзывом, транзакционным погашением, блокировками email/приглашения, RBAC школы, CSRF и ограничениями частоты. Изменены OpenAPI и login remember boolean. Используется штатный Laravel recaller на 30 дней. Logout завершает текущую сессию и меняет remember token: другие recaller cookies больше не восстановят вход, но уже открытые независимые сессии других устройств автоматически не прекращаются.

Миграции: 2026_11_09_093911_create_school_invitations_table.php; 2026_11_09_100127_add_official_publication_source_type.php. Откат official_publication отказывается удалять используемый тип. Fresh/upgrade миграции проверены с сохранением пользователя.

Новые команды: practice:calendar (dry-run по умолчанию, --apply для импорта), practice:bootstrap (первый администратор пустой изолированной БД, скрытый ввод пароля), practice:inspect (только чтение). Launcher scripts/ux001-local.py создаёт отдельный Docker project school-ux001, DB school_events_ux001, loopback API 8020, собственные storage/Postgres/Redis и config cache. Он не запускает demo-seeder и не удаляет существующие данные.

Manifest database/data/ux001-calendar-2026-2027.json содержит 36 дат: 35 федеральных из [календаря Минпросвещения 2026/2027, с. 41–44](https://fcrv.smena.org/wp-content/uploads/perechen-meropriyatij-kalendarnogo-plana-2026-2027.pdf) и региональный [День образования государственных архивов Свердловской области, 10 сентября](https://oblgazeta.ru/politics/governor/2026/09/163803/). Это выбранные даты, не обязательный план школы. Аудитория не выдумана, направления редакционные; культурная достоверность не подтверждается автоматически.

Чистая practice DB: 2 users (bootstrap admin + зарегистрированный педагог), 1 school, 36 calendar_events, 0 scenarios/comparisons/export_jobs/artifacts. [SQL counts](../../schoolFrontend/docs/ux001/evidence/clean-counts.txt), [inventory](../../schoolFrontend/docs/ux001/evidence/clean-practice.txt). Данные интеграционных сценариев отделены в testing DB.

| Проверка | Итог | Доказательство |
|---|---|---|
| Полная suite | 455 tests / 2242 assertions, 0 errors/failures/skipped | [log](ux001/evidence/backend-full-suite.log), [JUnit](ux001/evidence/backend-full-suite.xml) |
| Приглашения / Remember Me | 28 tests / 133 assertions | [log](ux001/evidence/backend-focused.log) |
| Календарь / bootstrap / isolation | 17 tests / 198 assertions | [log](ux001/evidence/backend-calendar.log) |
| Concurrent registration | Два реальных процесса; оба варианта гонки дают 201+409 | SchoolInvitationTest в focused/full suite |
| Pint workspace --dirty --format agent | PASS | [log](ux001/evidence/backend-pint-workspace-final.log) |

PHP 8.4.27, PHPUnit 12.5.40. Полная suite использовала school_events_test, отдельную схему ux001_backend_review_62a29e01362a и snapshot /tmp/ux001-review; API browser fixture в public сохранён. Семь прежних bootstrap failures устранены собственной временной схемой, а не ослаблением проверок. Старые неуспешные логи сохранены отдельно. [Сводка](ux001/evidence/backend-test-summary.json), [точное воспроизведение](ux001/evidence/backend-reproduce.md).

## Локальный запуск

~~~powershell
python scripts/ux001-local.py
python scripts/ux001-local.py --start
docker exec school-ux001-app-1 php artisan practice:calendar
docker exec school-ux001-app-1 php artisan practice:calendar --apply --no-interaction
docker exec -it school-ux001-app-1 php artisan practice:bootstrap --email=admin@example.test --school="Моя школа"
docker exec school-ux001-app-1 php artisan practice:inspect --dry-run
~~~

Первая команда только показывает dry-run. Frontend: NUXT_PUBLIC_API_ORIGIN=http://localhost:8020, npm run dev -- --port 3030. Приватный env вне репозитория: ~/.codex/ux001/practice.env. Повторный bootstrap не заменяет существующего администратора. Инструкции приглашения → регистрация → вход → календарь → конструктор → mock generation → редактор → проверки/подтверждение → экспорт находятся в полном отчёте.

Ограничения: ручная передача приглашений; email delivery и password reset отсутствуют; MockProvider требует проверки и доработки педагогом; реальный пилот и независимая педагогическая приёмка не проводились. Отдельный destructive cleanup не добавлен: используется новая изолированная практика и read-only inventory.

## Локальные implementation SHA

Backend: 36ffbf600800ddd128ea0c3afd880844f21b159e.

Frontend: 1c2b683e932ae7840df40363bf6702eb4ee0c8f3.

Отчёты и evidence входят в последующие документационные коммиты. Публикация не выполнялась.
