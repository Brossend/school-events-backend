# LLM-001.1 — Pedagogical Quality & Cultural Claim Coverage

**Статус: PENDING INDEPENDENT REVIEW. Live-приёмка НЕ ПРОЙДЕНА. Педагогическая пригодность НЕ подтверждена. Production LLM не включён.**

Дата: 30 октября 2026 (Asia/Yekaterinburg; timestamps gateway в UTC). Основа — LLM-001:338 backend /605 frontend tests. Работа выполнена в существующих Laravel/Nuxt. Figma, рабочая school_events и исходные schema/semantic/normalization/provenance не изменялись.

## Итог пяти новых live случаев

Все пять действительно запускались заново с GigaChat-2-Max и одной финальной версией **school-methodist-1.3.1**. Результаты LLM-001/1.2.x не подставлялись. Пять подробных quality review находятся в llm001-1/quality-reviews; реальные IDs/usage/latency — case-outcomes.json. Технический completed не означает экспертную приёмку.

| Случай | Генерация | Содержательная оценка |
|---|---|---|
| class_level,45 минут | completed | Арифметика ресурсной задачи верна; нужны раздельные данные по ролям и проверка педагогом |
| all_school,60 минут,150 участников | failed, в том числе после одной явной дополнительной попытки | Сначала missing personal в schema; затем отсутствует конкретный порядок потоков/расписание; полноценная логистика и материалы не готовы |
| family_community,90 минут | completed | Формальная сумма90 верна, но семейное взаимодействие и альтернатива без родственника недостаточны; содержательно не принято |
| universal_vs_personalized | failed, в том числе после одной явной дополнительной попытки | Карточки не проходят прежнюю проверку готовности; атомарная пара не создана |
| cultural_context | completed | Поддержана только точная дата; трактовка модели требует review. После отрицательного теста evidence отозвано, B недоступен |

Следовательно, только3 из5 случаев completed; в БД4 сценария/4 ревизии (культурная пара даёт два документа). Ни один результат не получил независимую экспертную приёмку или Teacher Sign-Off. Это поставка кода, защиты и доказательств для ревью, а не объявление готового педагогического генератора.

## Реализованные изменения

- Отдельный PedagogicalQualityGate: сумма минут, ссылки активности/этапа, наличие ролей и материалов, маркеры/форматирование, явные числовые равенства, ограниченный solver двух покупок с бюджетом, конфликт времени, известная ошибка яблок/корзин, признаки возрастной примитивности, логистики100+ и семейной инклюзии. Обнаружимые ошибки блокируют; неопределённость остаётся requires_review.
- CulturalTextCoverage обходит все строковые листья: концепцию, этапы, реплики, роли, активности, ответы, handouts, слайды, speaker_notes и рекомендации. Только точный trusted claim с живыми evidence получает узкий exact_supported_claim. Нет whole-document verified: status=requires_review, complete=false, whole_document_verified=false всегда.
- Каждый finding содержит code/path/text/message/severity. Известные неподтверждённые годы/имена блокируют генерацию; другие признаки требуют ручной проверки. Пустой cultural_claims не является доказательством отсутствия истории или ошибок.
- Fresh quality_review возвращается в API сценария/ревизии/A/B; snapshot генерации хранится в GenerationRun.sanitized_prompt. Старые raw outputs и metadata не переписываются. Известные blocking findings дополнительно запрещают Sign-Off/export/download и культурный B у настоящих LiteLLM descendants. Mock сохранён для прежних тестовых/demo flows, не используется fallback при отказах.
- Nuxt отдельно показывает модерацию и необходимость содержательной проверки, не превращает отсутствующий report в PASS. Подтверждение источника в карточке явно ограничено выделенным claim; трактовка модели помечена требующей проверки. Пустой список claims больше не подписан как отсутствие исторических сведений во всём документе.
- PedagogicalTaskBrief передаёт проверенные исходные данные/решения и ограничения. Это не готовый сценарий и не Mock completion: полный JSON и педагогические тексты по-прежнему создаёт реальная модель. Данные помогают избежать произвольных арифметических сюжетов, но не гарантируют выполнение инструкций моделью.
- PrintableTextFormatter узко и lossless переводит буквальные слеш-n/слеш-r только в печатных materials в символы переноса. Raw payload и before/after сохраняются в audit; условия, ответы, числа и разделы не удаляются/не дописываются. Затем выполняются все прежние валидаторы и новый gate.

Полный протокол и ограничения: quality-gate-llm001-1.md, cultural-claim-coverage-llm001-1.md. Новых миграций или зависимостей нет. Additive OpenAPI1.7.0 / quality_review;59 production API operations сохранены.

## До/после и оставшиеся проблемы

Сохранённые реальные проблемные LLM-001 outputs перенесены в tests/Fixtures/llm0011 с origin/run_id/prompt_version. Новые правила обнаруживают неверный максимум покупок, конфликт135/120 минут, противоречие корзин, незаполненные карточки и историческую вставку1612/Минина/Пожарского в десяти разных разделах. Solver вычисляет максимум7 для120/15/30 и9 для100/10/20; это не hardcode ответа7. Исправленная COPY старого примера принята соответствующими числовыми правилами и ясно помечена как deterministic regression, не live LLM или изменение старой ревизии. Evidence: quality-regressions.json.

Новые live задания используют18 листов/12 карандашей, три комплекта5/3 и остаток3/3. Вычисления верны; старые задачи про покупки/примитивные фрукты не используются в этих новых завершённых ответах. Однако:

- В классном результате первая карточка раскрывает всю информацию, хотя роли описаны как владеющие неполными данными. Требуется методическая правка, а не переименование ролей.
- В общешкольном raw ответе есть ссылка «согласно расписанию», но нет самого расписания/конкретного порядка. Для пяти зон только две карточки. Пять ролей по5 не объясняют распределение15 участников без явной ротации. Gate не был ослаблен ради completed.
- Семейный результат содержит одну45-минутную активность, все роли students и не содержит явной безопасной альтернативы без родственника. FAMILY_INCLUSION/FAMILY_CONTENT_REVIEW сохранены, экспертной пригодности нет.
- В универсальном retry оборот карточки содержит только короткую строку о запасах, короче40 символов и без полноценного решения. Никакого искусственного padding или удаления карточек ради приёмки нет.
- Культурный B сохранил точный date-only claim. Его claimed_meaning — «государственный праздник, отмечаемый...» — не равен trusted claim и получил warning с путём. Инструкция «объяснить значение праздника» требует ограничения переданным фактом либо новых проверенных claims. Отсутствие1612 в просмотренных финальных слайдах не является гарантией полноты исторической проверки.

Числовые/лексические правила не доказывают полную согласованность ролей, задач, логистики или всех фактов естественного языка. Даже без конкретного finding документ остаётся requires_review. Отдельный LLM-detector не использован: он также не гарантировал бы полноту и увеличил бы стоимость испытаний.

## Реальный E2E, бюджет экспериментов и аудит

Только localhost:8001 → app-test → school_events_test / school_test_user. Синтетические школы/учётные записи, отдельная queue llm0011-8cc15da6b6 и Redis prefix. Ключ только в ignored runtime .env.testing; Authorization/cookies/passwords в evidence и ZIP не включены.

Ранняя1.3.0 дала три failed outputs и6 chat вызовов: буквальные переносы и недостоверное наполнение. Её данные сохранены в discovery-v130 и не считаются финальными результатами. После детерминированных тестов начат новый отдельный run8cc15da6b6 с1.3.1. Основной прогон и ограниченное исправление двух failed случаев дали16 chat вызовов; включая discovery —22. Все recorded upstream chat responses —200/stop, но schema/quality могли отказать. Максимум один прежний repair-pass на вариант сохранён; дополнительный ручной retry — по одному для all_school и universal comparison. После исчерпания бюджета дальнейших платных экспериментов нет.

Два дефекта harness/проверки сохранены открыто: сначала server verified_at был ошибочно принят за историю (исправлено только для точного ISO timestamp по точному служебному path; проза там по-прежнему ловится), затем snapshot превысил стандартный1MiB spawnSync buffer (увеличен до32MiB). Исходные failed receipts сохранены: live-http-primary-v131.json и live-http-reconcile-size-error.json. Fresh реальный GET после исправления подтвердил cultural technical_checks=passed; генерации и metadata не переписывались.

live-http.json.completed=false — честный конечный verdict. Failed attempts и их usage/latency сохранены. В cancelled atomic comparison технически полученный A может иметь общий failed status/ошибку B; это не доказательство, что каждый raw A был невалиден. Не опубликованная пара не выдаётся за completed.

Настоящие CSRF/login/auth-me/tenant, idempotency replay, polling и cross-school403 выполнены. live-provenance-selection.json: до отзыва cultural availability=true и supported_assertions непусты; после DELETE evidence availability=false и supported_assertions=[]; выбор B —409. Затем выбран некультурный A —200 и замена выбора —409; logout204/auth-me401. Выбор A — тест программного immutable selection, не педагогический Sign-Off.

Redelivery: повторно доставлены9 GenerationRun jobs +3 Comparison jobs в свою очередь, drain exit0. До/после:16 gateway calls,4 scenarios,4 revisions,9 runs,3 comparisons. Новых платных вызовов/дублей нет. Тест не является chaos-test аварии посреди платного HTTP. Все gateway audit records имеют transaction_level=0.

## Регрессия, окружение и поставка

Финальный полный PHPUnit:361 PASS /1781 assertions (baseline338). Vitest:611 PASS /22 files (baseline605). Typecheck/ESLint/production build, scoped Pint, JSON Schema/OpenAPI validator — PASS. Эти результаты не заменяют failed live cases. Raw logs/exit и отдельные отрицательные receipts приложены; не все файлы *.exit намеренно равны0.

UI проверен после действительного входа при1440×1000 и390×844: отдельный warning о содержании, scoped source matching, unsafe missing-report fallback, корректное состояние после revoke. Screenshots и browser-checks.json приложены; Figma не менялась. В ходе проверки исправлено размещение warning вне трёхколоночного scenario layout.

app-test возвращён к mock/sync; frontend dev снова направлен на localhost:8000. Production LLM не включён. Рабочие данные не мигрировались/не заполнялись. Ключ остался только локально. Runtime-restored.json и production-smoke.json фиксируют восстановление и проверку сборки.

Изменённые файлы: llm001-1/backend-changed-files.json / frontend-changed-files.json. Неизменность core подтверждена core-contract-hashes.json. ZIP содержит исходники, tests/fixtures, UI-kit, документы и evidence. Исключены реальные env/secrets, vendor/node_modules, .git/.nuxt/.output и config/build caches. Manifest, CRC, secret scan и отдельный read-only verifier проверяют поставку; успешная проверка ZIP не означает успешной педагогической приёмки. SHA-256 архивов во внешних .zip.sha256 и release-verification.json, вне включённого report.

Воспроизведение: соседние каталоги school и schoolFrontend, composer install /npm ci, локальные env из example. Изолированный compose override — tests/backend/llm0011-compose.override.yml; установить LLM001_MODEL=GigaChat-2-Max, свой LLM001_QUEUE/LLM001_REDIS_PREFIX и config:cache внутри app-test с отдельным APP_CONFIG_CACHE. Guarded fixtures и worker требуют test DB/role; worker — scripts/llm0011-worker.php с LLM0011_RUN. Live scripts требуют LLM0011_LIVE=1 и выполняют платные вызовы; повторять их без нового bounded плана не нужно. Промпт/worker после изменения требуют restart.

**Блокеры и незавершённые условия приёмки:** стабильная генерация общешкольной логистики и полных A/B карточек, содержательная семейная инклюзия/90 минут, действительное разделение информации по ролям, независимая педагогическая оценка и окончательный Teacher Sign-Off. LLM-001.1 не объявляется CLOSED или production-ready.
