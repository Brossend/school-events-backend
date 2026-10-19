# Школьные события — Laravel Backend (Сбер × УрФУ)

Модульный монолит на **Laravel 13**, **PHP 8.4-FPM**, **PostgreSQL 16**, **Redis 7** и **Nginx**, спроектированный под SPA-фронтенд на Nuxt 4 с сессионной авторизацией Laravel Sanctum, строгой изоляцией школ (multi-tenancy) и верифицируемым шлюзом культурных источников (Provenance Gate).

---

## 1. Системные требования

- **ОС:** Windows 10/11 с установленным **Docker Desktop** (WSL 2 backend) или Linux / macOS с Docker Engine 24+.
- **Docker Compose:** v2.20+.
- **Порты на хосте:**
  - `8000` — Nginx Web API (доступен на всех интерфейсах `0.0.0.0:8000`);
  - `5432` — PostgreSQL 16 (доступен **строго** на `127.0.0.1:5432` для безопасности);
  - Redis 7 **не публикует** портов наружу и изолирован внутри внутренней Docker-сети `school_network`.

---

## 2. Быстрый запуск из чистого ZIP (Clean Setup)

Для воспроизведения на новой машине без установленного локального PHP или Composer:

### Вариант A. Автоматический запуск с хоста

#### В терминале Windows PowerShell:
```powershell
# Без демо-данных (только миграции и тесты):
.\scripts\bootstrap.ps1

# С заполнением демонстрационными данными (требуется явный флаг -Seed):
.\scripts\bootstrap.ps1 -Seed
```

#### В терминале Linux / macOS (Bash):
```bash
# Сделать скрипт исполняемым:
chmod +x scripts/bootstrap.sh

# Без демо-данных:
./scripts/bootstrap.sh

# С заполнением демонстрационными данными (требуется явный флаг --seed):
./scripts/bootstrap.sh --seed
```

*(Скрипт автоматически создаёт `.env`, поднимает контейнеры Docker Compose, устанавливает Composer-пакеты внутри контейнера, генерирует `APP_KEY` при его отсутствии, применяет миграции к рабочей и тестовой базам, при наличии флага заполняет демоданные через Provenance Gate и выполняет 71 тест PHPUnit).*

---

### Вариант B. Пошаговые команды через Docker Compose

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
   docker compose exec -T app composer install --no-interaction --prefer-dist --optimize-autoloader
   ```

4. **Сгенерируйте ключ приложения (если отсутствует):**
   ```bash
   docker compose exec -T app php artisan key:generate --force
   ```

5. **Примените миграции к рабочей БД (`school_events`) и тестовой БД (`school_events_test`):**
   ```bash
   docker compose exec -T app php artisan migrate --force
   docker compose exec -T -e DB_DATABASE=school_events_test app php artisan migrate --force
   ```

6. **(Опционально) Наполните рабочую БД демонстрационными данными:**
   ```bash
   docker compose exec -T app php artisan db:seed --force
   ```
   *Демосидер категорически запрещён в production-окружении (`APP_ENV=production`). В local/demo пароли берутся из переменных `DEMO_*_PASSWORD` либо генерируются криптографически стойко.*

7. **Проверьте работоспособность сервиса:**
   ```bash
   curl -i http://localhost:8000/health
   ```

---

## 3. Запуск автоматических тестов

### 3.1. Тестовый набор PHPUnit (71 тест, 250 проверок)
Тесты выполняются внутри контейнера против изолированной тестовой базы `school_events_test`:
```bash
docker compose exec -T -e APP_ENV=testing app php artisan test
```

### 3.2. Исполняемый HTTP Gateway & Security Audit Suite (21 тест)
Реальные HTTP-проверки через сетевой шлюз Nginx (порт 8000), включающие CSRF, куки Sanctum, изоляцию школ, динамическую верификацию источников и rate limiting:
```bash
python scripts/test_http_audit.py
```

### 3.3. Валидация контрактов JSON Schema и OpenAPI 3.1
Проверка Draft 2020-12, семантических правил и целостности OpenAPI:
```bash
python docs/validation/validate_schemas.py
```

---

## 4. Справка по статусу проверки культурных источников (Provenance)

В соответствии с правилами культурной безопасности и академической достоверности:

- **Проведённая нормативная верификация (Verified Authentic):**
  - *День народного единства* (4 ноября): нормативно подтверждён Федеральным законом от 29.12.2004 № 201-ФЗ (ст. 112 ТК РФ). Текст проверен по государственной эталонной базе Pravo.gov.ru. Зафиксирована утверждённая рецензия куратора. Статус: `verified_authentic`.
  - *День российской науки* (8 февраля): нормативно подтверждён Указом Президента РФ от 07.06.1999 № 717. Текст проверен по государственной базе Pravo.gov.ru. Зафиксирована утверждённая рецензия куратора. Статус: `verified_authentic`.

- **Демонстрационные региональные данные (Unverified):**
  - *День народов Среднего Урала* (Свердловская область, код 66): этнографические сведения о традициях Среднего Урала внесены как демонстрационные материалы. Академическая архивная и библиографическая экспертиза для них не завершена. Статус строго зафиксирован как `unverified` с явной пометкой «демонстрационные / ожидают проверки». В API `GET /api/v1/calendar-events/{id}` возвращается как `unverified`.
