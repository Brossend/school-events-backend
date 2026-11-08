# DEPLOY-001 — эксплуатация

## Обновление

1. Изменения frontend отправить в GitLab main; дождаться зелёного pipeline и image с полным commit SHA.
2. Указать этот SHA в backend `docker/production/frontend-sha.txt`; проверить совместимость API. Отправить backend в protected main.
3. Дождаться tests/build/mirror. В backend pipeline вручную запустить `deploy_production`. `resource_group` и серверный flock запрещают параллельное развёртывание.
4. Deploy проверяет SSH host key, скачивает два конкретных SHA image, извлекает Compose/config из backend image, останавливает worker, делает backup предыдущего релиза, применяет `migrate --force`, запускает стек и health checks. Не использовать latest.
5. Проверить production smoke, pipeline log и `current.env` на VPS. При ошибке job проверить контейнеры и логи до повторного запуска.

## Диагностика

Подключение: `ssh user1@87.242.87.93`. Команды выполняются владельцем через sudo:

```bash
sudo -i
cd /opt/school-events
set -a; source current.env; set +a
docker compose --env-file /dev/null ps
docker compose --env-file /dev/null logs --tail 80 app worker nginx frontend
curl --fail http://127.0.0.1:8080/api/v1/health
systemctl status school-backup.timer
df -h /
```

Не выводить `docker compose config`, `docker inspect ...Config.Env` или содержимое secrets в общие логи. Loopback HTTP предназначен для диагностики по SSH; авторизацию через публичный IP HTTP не использовать.

## Секреты

В GitLab backend Settings → CI/CD → Variables уже сохранены `DEPLOY_SSH_KEY_B64` (protected/masked/hidden) и `DEPLOY_KNOWN_HOSTS` (protected, публичный host key). `GITHUB_MIRROR_TOKEN` хранится в обоих проектах; `CI_POSTGRES_PASSWORD` в backend — только для временной тестовой БД. Production env в CI не требуется: он находится на VPS.

- VPS `/opt/school-events/secrets/backend.env`: APP_KEY, DB application password, новые demo passwords, production URLs/session/Redis/mock.
- VPS `/opt/school-events/secrets/postgres.env`: отдельные DB owner credentials.
- VPS `/opt/school-events/secrets/registry-{backend,frontend}/config.json`: отдельные read_registry tokens.
- Локально вне репозиториев `C:/Users/bross/.codex/deploy001-ci/`: env copies, registry token JSON, dedicated CI key и known_hosts.
- Личные GH/GL credentials остаются вне Git в `C:/Users/bross/.codex/repo001-auth.env`.

Пароли не публиковать в docs/chat. Demo logins: `admin@demo.school-events.ru`, `teacher.alpha@demo.school-events.ru`, `teacher.beta@demo.school-events.ru`, `director.alpha@demo.school-events.ru`, `director.beta@demo.school-events.ru`. Соответствующие секреты: DEMO_ADMIN_PASSWORD / DEMO_TEACHER_PASSWORD / DEMO_DIRECTOR_PASSWORD.

## Демо

После первого deploy выполнить `docker compose --env-file /dev/null exec -T app php artisan db:seed --class=ProductionDemoSeeder --force --no-interaction`. Seeder идемпотентен и не сбрасывает изменённые пароли. Он откажется работать без сильных demo credentials и MockProvider. Обычный DatabaseSeeder production не разрешает. Сценарии, A/B и экспорты создаются штатным API на этих синтетических данных. Не маркировать культурные фикстуры как прошедшие экспертизу.

## Backup и rollback

`sudo /usr/local/sbin/school-backup` создаёт `/opt/school-events/backups/<UTC>/database.dump`, `storage.tar.gz`, `release.env`, checksums. systemd timer — ежедневно 02:15 UTC. Контролировать успешность `journalctl -u school-backup.service` и свободное место. Копировать backup за пределы VPS защищённым транспортом; хранить APP_KEY и production secrets отдельно в защищённом хранилище.

Для rollback прочитать `previous.env` и проверить совместимость текущей схемы с прежним backend. Вызвать `sudo /usr/local/sbin/school-deploy <проверенный BACKEND_SHA> <проверенный FRONTEND_SHA>`: будет создан новый backup, данные/volumes не удаляются. Не запускать migrate:rollback, compose down -v или восстановление поверх рабочей БД. После rollback выполнить smoke.

Восстановление проверять в отдельной базе и отдельном storage volume: `pg_restore --no-owner --no-acl` в новую БД, распаковать archive в новый volume, сравнить записи и checksum экспортов. Production database/volume не заменять без отдельного решения владельца.

8 ноября восстановление проверено: backup `20261108T122632Z`, БД `school_restore_20261108122633`, volume `school_events_restore_20261108122633`; совпали 37 table counts и 6 file checksums. Тестовые ресурсы оставлены для проверки, автоматическое удаление не выполнялось. До восстановления в новый стек создать DB role/права, подключить сохранённые production secrets и APP_KEY, выбрать image SHA из release.env; сами secrets в backup archive не включены.

Подтверждённая пара для повторного запуска: backend `ea1a51b634a0e5b3c4acea7360e3d19ff2256b73`, frontend `9aee989fb0cb040bb4855d7cfc11a612fecae396`. Выполнены два CI deploy и проверка сохранности данных. Отдельная более ранняя production-версия пока не проверялась; `previous.env` сейчас содержит ту же пару. Процедура rollback подготовлена, переход на другой код не тестировался.

## После исправления DNS

Проверить NS в .ru, оба A и отсутствие ошибочного AAAA через 1.1.1.1 / 8.8.8.8. Caddy автоматически выпустит сертификаты для school-events.ru и www.school-events.ru. Проверить сертификат без отключения TLS validation, HTTP→HTTPS и www→apex, вход в браузере, Secure/HttpOnly/SameSite cookies, CSRF, API и скачивание экспорта по домену. Пока это не подтверждено, полный production не объявлять.
