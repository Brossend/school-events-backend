# REPO-001 — GitLab primary, GitHub mirror

Дата: 8 ноября 2026. **REPO-001 COMPLETED**: все четыре приватных репозитория опубликованы, реальные GitLab jobs и синхронизация GitHub проверены. Развёртывание DEPLOY-001 не выполняется.

| Проект | Основной GitLab (`origin`) | GitHub (`github`) | Видимость |
|---|---|---|---|
| Backend | https://gitlab.com/Brossend2335/school-events-backend | https://github.com/Brossend/school-events-backend | Private / Private |
| Frontend | https://gitlab.com/Brossend2335/school-events-frontend | https://github.com/Brossend/school-events-frontend | Private / Private |

Основная ветка обоих проектов — `main`. Backend сохраняет два исходных коммита и прежние локальные ветки; frontend инициализирован отдельно. Вложенных Git-репозиториев не обнаружено. Старый неиспользуемый каталог backend `frontend/` оставлен локально и исключён из backend Git: актуальный frontend публикуется своим проектом.

## Секреты и история

В двух старых backend-коммитах обнаружены DEMO-пароли из прежнего локального окружения: `database/seeders/DatabaseSeeder.php`, `tests/Feature/AuthTest.php`, `scripts/test_http_audit.py`. После явного решения пользователя история сохранена, а действующие локальные секреты заменены до публикации. Старые значения остаются только частью истории и больше не являются рабочими паролями локальных demo-пользователей.

Заменены два APP_KEY, пароли двух PostgreSQL-ролей и demo-пользователей обоих контуров; пароли 186 синтетических fixture-аккаунтов пересчитаны под новый тестовый ключ. Старые восемь паролей основного demo-контура проверены как недействующие. Существующие данные и история сценариев сохранены. Контейнеры приложений, очереди и PostgreSQL пересозданы с новыми значениями; старые ключи не сохраняются через APP_PREVIOUS_KEYS.

LiteLLM API-ключ очищен из локального `.env.testing`; оба runtime работают с MockProvider. **Отзыв/перевыпуск внешнего LiteLLM-ключа владельцем шлюза не подтверждён.** Удаление значения из файла не отзывает ключ у провайдера. Redis и почтовый пароль не были настроены, их значений для ротации нет. Внешних Chat Completion-запросов: **0**.

`.env`, `.env.testing`, временные credentials, vendor/node_modules, сборки, кэши, ZIP, screenshots/downloads исключены из индекса. Сохранены только безопасные `.env.example` / `.env.testing.example`. Безопасные текстовые исследовательские evidence сохранены.

Проверены Git history, индекс и точные совпадения с известными секретами. Gitleaks 8.30.1 не нашёл типовых токенов в двух старых коммитах, но точное сравнение с локальным окружением выявило указанные DEMO-пароли. В индексе эвристика generic-api-key дала 1523/1557 срабатываний: все классифицированы как SHA-256, idempotency UUID или идентификатор Figma-документа; неподтверждённых находок не осталось. Это проверка известных значений и правил, а не гарантия обнаружения любого неизвестного секрета.

## Фактические локальные проверки после ротации

- Backend PHPUnit: **406 passed, 1922 assertions**, 395.26 s.
- Frontend (Node **24.22.1**): typecheck, ESLint, **612 Vitest tests**, production build — passed.
- JSON Schema / OpenAPI structural validation — passed.
- Composer validate — passed с существующим предупреждением о wildcard constraint `laravel-dompdf`; зависимости не обновлялись.
- Laravel config/route/view cache build — passed в изолированных `/tmp/repo001-*` путях.

## GitLab CI/CD и зеркало

Добавлены `.gitlab-ci.yml` в оба проекта. Backend использует PHP 8.4 и composer.lock, проверяет PHP syntax, схемы/OpenAPI, запускает PostgreSQL 16 / Redis 7, создаёт ограниченную роль `school_test_user`, выполняет PHPUnit и сборку Laravel-кэшей. Backend — API; отдельный Nuxt production build находится в frontend pipeline. В корне backend нет npm lock-файла, поэтому фиктивный npm-ci job для Laravel skeleton не добавлен.

Frontend использует Node 24.22.1 и package-lock.json: npm ci → typecheck / ESLint / Vitest → production build. Все LLM-тесты работают с fake/mock; действующий LiteLLM-ключ в CI не передаётся.

После успешной основной сборки `github_mirror` делает обычный fast-forward push того же SHA `main` в GitHub, без force и без самостоятельного GitHub deployment. Переменная `GITHUB_MIRROR_TOKEN` должна быть masked/hidden и protected; без неё job доступен вручную и не считается выполненной синхронизацией. GitHub Actions для развёртывания не создаются.

Backend CI использует отдельный `CI_POSTGRES_PASSWORD`, не пароль локальной/будущей production БД. Переменная уже добавлена в backend GitLab как Masked / Hidden, доступна тестовым pipeline. `GITHUB_MIRROR_TOKEN` уже добавлен в оба проекта как Protected / Masked / Hidden. GitLab shared runners работают; результаты ниже получены из фактически завершённых jobs.

## Расположение новых секретов

Только на компьютере пользователя, вне Git: `C:/Users/bross/.codex/repo001-secrets.env` (ACL: пользователь, SYSTEM и чтение для локальной группы CodexSandboxUsers); действующие конфигурации — backend `.env` и `.env.testing`. В отдельном локальном файле ключи основного контура имеют префикс `APP_`, тестового — `TEST_`, отдельный пароль CI — `CI_POSTGRES_PASSWORD`. Значения не приводятся в отчёте. Временная авторизация публикации — `C:/Users/bross/.codex/repo001-auth.env`, также вне обоих репозиториев.

Для DEPLOY-001 потребуются целевой сервер, параметры подключения и отдельные защищённые CI/CD Variables для deployment. Реальный deploy job, SSH credentials и registry не выдумывались. Реальные APP_KEY/пароли для серверного runtime следует передавать как защищённые переменные либо секретный env-файл, а не коммитить.

## Файлы подготовки

В обоих проектах: `.gitignore`, `.gitlab-ci.yml`, README, этот отчёт. Локальная ротация затронула игнорируемые env-файлы и пароли существующих учётных записей, без изменения продуктовой логики. В коммит также включаются ранее незакоммиченные исходники, тесты и безопасные документы FE-012, LLM-001.3, DEMO-001.

## Подтверждённые удалённые результаты

| Проект | Проверенный commit SHA исходников (GitLab = GitHub) | GitLab pipeline | Результат |
|---|---|---|---|
| Backend | `fa9674e61d789cb2dc73a09b5bf990af6fe0d609` | [#3041759263](https://gitlab.com/Brossend2335/school-events-backend/-/pipelines/3041759263) | Все 5 jobs passed, включая github_mirror |
| Frontend | `2f0e7180fb14d66c04cd6aef3d638f533f14539c` | [#3041776589](https://gitlab.com/Brossend2335/school-events-frontend/-/pipelines/3041776589) | Все 6 jobs passed, включая github_mirror |

Backend CI: php_dependencies (Composer install/validate + PHP syntax), schema_contracts, phpunit (**406 passed, 1897 assertions**, 29.28 s), laravel_build, github_mirror.

Frontend CI: dependencies, typecheck, eslint, vitest (**22 files, 612 passed**, 13.68 s), production_build, github_mirror. Первый pipeline [#3041759547](https://gitlab.com/Brossend2335/school-events-frontend/-/pipelines/3041759547) упал на npm ci: отсутствовали @emnapi/core и @emnapi/runtime. Исправление package-lock.json включено в 2f0e718; версии существующих пакетов не изменялись. Чистая установка дополнительно прошла в node:24.22.1-bookworm-slim. В Vitest остаются предупреждения Vue о разрешении компонентов; тесты успешны.

Private visibility GitHub проверена через API, GitLab — в General Settings. В обоих GitLab-проектах default branch main защищена, Allow force push выключен. origin указывает на GitLab, github — на GitHub. Автоматическое зеркало проверено успешными jobs, GitHub Actions для deployment не добавлялись.

Повторный secret scan 8 ноября: точные значения текущих токенов и новых секретов из локальных repo001-auth.env / repo001-secrets.env не найдены во всех локальных Git refs (backend 735 объектов, frontend 1167 объектов на момент проверки). Отслеживаемых .env, vendor, node_modules и сборок нет. Это дополняет предыдущий Gitleaks/исторический аудит; старые отозванные DEMO-пароли сознательно сохранены в истории по решению пользователя. Утверждение об отсутствии действующих секретов ограничено выполненными проверками; внешний отзыв прежнего LiteLLM-ключа не подтверждён.

Оба локальных API (8000, 8001) отвечают health HTTP 200, PostgreSQL/Redis connected. Оба env используют mock, LITELLM_API_KEY пуст, APP_PREVIOUS_KEYS пуст. Повторных внешних LLM-вызовов не выполнялось.

Финальный коммит этого отчёта меняет только документацию и публикуется с [skip ci], после проверки исходников указанными pipeline. Он синхронизируется обычным push в оба remote; точный финальный SHA можно получить через git log -1 --format=%H -- docs/REPO-001-report.md. Самоссылочный SHA в содержимое коммита не записывается. История исходников и локальные ветки сохранены, force push не использовался.

Сохранённый GitLab fine-grained токен допускает Git fetch/push, но API управления проектами возвращает insufficient_granular_scope. Проверка visibility, защиты веток, переменных и jobs выполнена через авторизованный GitLab UI; замена токена для завершения REPO-001 не потребовалась.

## Следующий этап

DEPLOY-001 требует целевого сервера, домена и отдельных deployment credentials. Текущие CI_POSTGRES_PASSWORD и GITHUB_MIRROR_TOKEN уже настроены; runtime APP_KEY/DB/demo-пароли сейчас находятся только в локальных игнорируемых env и repo001-secrets.env. Их перенос на будущий сервер выполняется отдельно. Владельцу LiteLLM-шлюза следует подтвердить отзыв прежнего ключа; его значение не включается в Git или отчёт.
