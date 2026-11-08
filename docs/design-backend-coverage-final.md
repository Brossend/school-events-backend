# Матрица соответствия и полноты серверной реализации утверждённому дизайну (Design v1.0)

**Проект:** «Школьные события», Сбер × УрФУ  
**Версия поставки:** BE-009.1 Final Release Evidence & Security Corrections  
**Статус:** ACCEPTED & FULLY IMPLEMENTED (0 MISSING, 0 PARTIAL)  
**Утверждённый дизайн:** Design v1.0 (211 экранов, 15 секций, 60/60 проверенных маршрутов, детальный реестр: [figma-flows-coverage.md](figma-flows-coverage.md), 53 READY + 7 FRONTEND_ONLY)  
**Технологический стек:** Laravel 13, PHP 8.4, PostgreSQL 16, Redis 7 (Queues + Cache), Docker Compose

---

## 1. Сводка приёмки по разделам дизайна

| № | Раздел дизайна (Design v1.0 Section) | Реализовано действий | Статус покрытия |
|---|---|:---:|:---:|
| 1 | Авторизация, сессия, профиль и роли | 6 | READY |
| 2 | Дашборд школы, сводка и счётчики активности | 1 | READY |
| 3 | Календарь воспитательных событий и поиск | 3 | READY |
| 4 | Карточка события, источники и доказательства | 4 | READY |
| 5 | Кураторская верификация первоисточников и утверждений | 5 | READY |
| 6 | Многолетние школьные традиции и ежегодные версии | 6 | READY |
| 7 | Конструктор сценариев (3 типа: классные, общешкольные, семейные) | 3 | READY |
| 8 | Асинхронная генерация на 3 масштаба и опрос статуса | 2 | READY |
| 9 | Редактор сценария, оптимистичные блокировки и ревизии | 4 | READY |
| 10 | Предвыдачная модерация (P0/P1) и подтверждение педагогом (Human Sign-Off) | 3 | READY |
| 11 | Мои мероприятия: фильтрация, архивация, удаление | 5 | READY |
| 12 | Экспорт полного пакета материалов (PDF, DOCX, PPTX, Print Pack PDF) | 4 | READY |
| 13 | A/B-сравнение сценариев (универсальный vs персонализированный, культурный) | 5 | READY |
| 14 | Пилотное исследование с 20 педагогами, анкеты и аналитика | 9 | READY |
| 15 | Профиль образовательной организации и настройки | 2 | READY |
| **ИТОГО** | **15 функциональных разделов** | **58 производственных операций** | **100% ПОКРЫТИЕ (0 PARTIAL, 0 MISSING)** |

---

## 2. Детальная матрица приёмки (Endpoint × Экран × RBAC × Тест)

### Раздел 1: Авторизация, сессия, профиль и роли (Figma Section 1)
*Канонические экраны: Вход `59:456`, Профиль пользователя `61:1200`*

| № | Действие / Экран | Метод и Endpoint | Контроллер / Сервис | RBAC | Тест | Статус |
|---|---|---|---|---|---|:---:|
| 1.1 | Вход по логину и паролю (Sanctum SPA Session, CSRF) | `POST /api/v1/auth/login` | `AuthController::login` | Публичный (Rate Limit 5/мин) | AuthTest::test_successful_login | **READY** |
| 1.2 | Завершение сеанса пользователя | `POST /api/v1/auth/logout` | `AuthController::logout` | Авторизованный | AuthTest::test_logout | **READY** |
| 1.3 | Получение профиля текущего пользователя и членств в школах | `GET /api/v1/auth/me` | `AuthController::me` | Авторизованный | AuthTest::test_auth_me_with_active_session | **READY** |
| 1.4 | Получение контекста активной школы (переключение тенанта) | `GET /api/v1/tenant/current` | `IdentifyTenantScope` / `TenantResolver` | Член школы (`teacher`, `school_admin`) | TenantIsolationTest::test_multi_school_user_switches_school_via_header | **READY** |
| 1.5 | Системный healthcheck сервиса, БД PostgreSQL и Redis | `GET /api/v1/health` | `HealthController::check` | Публичный | AuthTest::test_healthcheck_returns_ok | **READY** |
| 1.6 | Проверка статуса системного доступа для администратора/куратора | `GET /api/v1/system/status` | `routes/api.php` | `system_admin`, `catalog_curator` | TenantIsolationTest::test_system_user_allowed_system_route | **READY** |

---

### Раздел 2: Дашборд школы, сводка и счётчики активности (Figma Section 2)
*Канонические экраны: Главный дашборд `1:2580`*

| № | Действие / Экран | Метод и Endpoint | Контроллер / Сервис | RBAC | Тест | Статус |
|---|---|---|---|---|---|:---:|
| 2.1 | Сводка дашборда: ближайшие события, последние сценарии, счётчики | `GET /api/v1/dashboard/summary` | `DashboardController::summary` | Пользователи школы (`tenant`) | DashboardSummaryTest::test_dashboard_summary_returns_aggregated_data | **READY** |

---

### Раздел 3: Календарь воспитательных событий и поиск (Figma Section 3)
*Канонические экраны: Календарь событий `1:2146`*

| № | Действие / Экран | Метод и Endpoint | Контроллер / Сервис | RBAC | Тест | Статус |
|---|---|---|---|---|---|:---:|
| 3.1 | Список событий с фильтрацией (регион, даты, тип, поиск `q`) | `GET /api/v1/calendar-events` | `CalendarEventController::index` | Авторизованный пользователь | CalendarTest::test_calendar_search_query | **READY** |
| 3.2 | Просмотр детальной карточки календарного события | `GET /api/v1/calendar-events/{id}` | `CalendarEventController::show` | Авторизованный пользователь | CalendarTest::test_teacher_of_region_66_cannot_view_region_77_event_directly | **READY** |
| 3.3 | Создание школьного/регионального события в календаре | `POST /api/v1/calendar-events` | `CalendarEventController::store` | `school_admin` (school_custom), `catalog_curator` | CalendarTest::test_school_admin_can_create_school_custom_event | **READY** |

---

### Раздел 4: Карточка события, источники и доказательства (Figma Section 4)
*Канонические экраны: Журнал источников `112:1444`, Карточка источника `112:1890`*

| № | Действие / Экран | Метод и Endpoint | Контроллер / Сервис | RBAC | Тест | Статус |
|---|---|---|---|---|---|:---:|
| 4.1 | Просмотр детальной информации об этнографическом первоисточнике | `GET /api/v1/event-sources/{id}` | `EventSourceController::show` | Авторизованный пользователь | CatalogReviewAndEvidenceTest::test_get_event_source_details | **READY** |
| 4.2 | Редактирование реквизитов источника (название, автор, DOI/ISBN) | `PATCH /api/v1/event-sources/{id}` | `EventSourceController::update` | `catalog_curator` (по региону), `system_admin` | CatalogReviewAndEvidenceTest::test_patch_event_source_bibliographic_details | **READY** |
| 4.3 | Просмотр хронологии рецензий экспертов по источнику | `GET /api/v1/event-sources/{id}/reviews` | `EventSourceController::listReviews` | `catalog_curator`, `system_admin` | CatalogReviewAndEvidenceTest::test_list_event_source_reviews | **READY** |
| 4.4 | Просмотр карточки культурного утверждения и привязанных свидетельств | `GET /api/v1/cultural-claims/{id}` | `CulturalClaimController::show` | Авторизованный пользователь | CatalogReviewAndEvidenceTest::test_get_cultural_claim_details | **READY** |

---

### Раздел 5: Кураторская верификация первоисточников и утверждений (Figma Section 5)
*Канонические экраны: Очередь проверки `114:1001`, Форма экспертизы `114:1450`*

| № | Действие / Экран | Метод и Endpoint | Контроллер / Сервис | RBAC | Тест | Статус |
|---|---|---|---|---|---|:---:|
| 5.1 | Очередь первоисточников на экспертизу (`review-queue`) | `GET /api/v1/catalog/review-queue` | `CatalogReviewController::index` | `catalog_curator`, `system_admin` | CatalogReviewAndEvidenceTest::test_curator_can_view_review_queue_with_regional_scoping | **READY** |
| 5.2 | Вынесение экспертного решения по первоисточнику (approve/reject) | `POST /api/v1/event-sources/{id}/reviews` | `EventSourceController::storeReview` | `catalog_curator`, `system_admin` | CatalogReviewAndEvidenceTest::test_post_review_approved_updates_source_and_claim_status | **READY** |
| 5.3 | Прикрепление цитаты-доказательства первоисточника к утверждению | `POST /api/v1/cultural-claims/{id}/evidence` | `CulturalClaimController::attachEvidence` | `catalog_curator`, `system_admin` | CatalogReviewAndEvidenceTest::test_attach_evidence_to_cultural_claim | **READY** |
| 5.4 | Отзыв доказательства первоисточника с аудируемым логированием | `DELETE /api/v1/cultural-claims/{id}/evidence/{sourceId}` | `CulturalClaimController::detachEvidence` | `catalog_curator`, `system_admin` | CatalogReviewAndEvidenceTest::test_detach_evidence_record | **READY** |
| 5.5 | Защита от межсобытийного связывания первоисточников (Provenance Integrity) | `POST /api/v1/cultural-claims/{id}/evidence` | `CulturalClaimController::attachEvidence` | `catalog_curator` | CatalogReviewAndEvidenceTest::test_attach_evidence_cross_event_injection_blocked | **READY** |

---

### Раздел 6: Многолетние школьные традиции и ежегодные версии (Figma Section 6)
*Канонические экраны: Традиции школы `97:1007`, Создание ежегодной версии `97:1540`*

| № | Действие / Экран | Метод и Endpoint | Контроллер / Сервис | RBAC | Тест | Статус |
|---|---|---|---|---|---|:---:|
| 6.1 | Список традиций образовательной организации | `GET /api/v1/schools/{schoolId}/traditions` | `SchoolTraditionController::listBySchool` | Педагоги и администрация школы | TraditionsTest::test_teacher_can_list_school_traditions | **READY** |
| 6.2 | Создание новой традиции школы | `POST /api/v1/schools/{schoolId}/traditions` | `SchoolTraditionController::store` | `teacher`, `school_admin` | TraditionsTest::test_teacher_can_create_tradition | **READY** |
| 6.3 | Просмотр традиции со списком всех ежегодных версий | `GET /api/v1/traditions/{id}` | `SchoolTraditionController::show` | Пользователи школы | SchoolProfileAndTraditionsTest::test_get_tradition_details | **READY** |
| 6.4 | Редактирование метаданных традиции (название, описание, год) | `PATCH /api/v1/traditions/{id}` | `SchoolTraditionController::update` | Автор традиции, `school_admin` | SchoolProfileAndTraditionsTest::test_patch_tradition_metadata | **READY** |
| 6.5 | Создание новой версии традиции на очередной учебный год | `POST /api/v1/traditions/{id}/versions` | `SchoolTraditionController::createVersion` | `teacher`, `school_admin` | TraditionsTest::test_tradition_annual_versions_immutability | **READY** |
| 6.6 | Перевод традиции в архив без потери истории | `POST /api/v1/traditions/{id}/archive` | `SchoolTraditionController::archive` | Автор традиции, `school_admin` | SchoolProfileAndTraditionsTest::test_archive_tradition | **READY** |

---

### Раздел 7: Конструктор сценариев (3 типа событий) (Figma Section 7)
*Канонические экраны: Шаг 1 `13:175`, Шаг 2 `14:176`, Шаг 3 `14:468`, Шаг 4 `14:760`, Шаг 5 `15:179`*

| № | Действие / Экран | Метод и Endpoint | Контроллер / Сервис | RBAC | Тест | Статус |
|---|---|---|---|---|---|:---:|
| 7.1 | Создание запроса на сценарий (классное, общешкольное, семейное) | `POST /api/v1/scenario-requests` | `ScenarioRequestController::store` | `teacher` (текущей школы) | ScenarioConstructorTest::test_teacher_can_create_scenario_request_with_calendar_event | **READY** |
| 7.2 | Просмотр параметров сохранённого запроса со снимком контекста | `GET /api/v1/scenario-requests/{id}` | `ScenarioRequestController::show` | Автор запроса, `school_admin` | ScenarioConstructorTest::test_teacher_can_view_own_school_scenario_request | **READY** |
| 7.3 | Защита от инъекции чужого тенанта (`TENANT_INJECTION_FORBIDDEN`) | `POST /api/v1/scenario-requests` | `PreventTenantInjection` middleware | Все роли | ScenarioConstructorTest::test_school_id_in_body_is_strictly_rejected | **READY** |

---

### Раздел 8: Асинхронная генерация на 3 масштаба (Figma Section 8)
*Канонические экраны: Процесс генерации `1:794`*

| № | Действие / Экран | Метод и Endpoint | Контроллер / Сервис | RBAC | Тест | Статус |
|---|---|---|---|---|---|:---:|
| 8.1 | Запуск асинхронной генерации с поддержкой `Idempotency-Key` | `POST /api/v1/scenario-requests/{id}/generate` | `ScenarioRequestController::generate` | Автор запроса | IdempotencyTest::test_post_generate_returns_202_and_creates_run | **READY** |
| 8.2 | Опрос статуса выполнения генерации (`queued/running/completed`) | `GET /api/v1/generation-runs/{id}` | `GenerationRunController::show` | Автор запроса | LlmGenerationTest::test_generation_run_status_endpoint | **READY** |

---

### Раздел 9: Редактор сценария, блокировки и ревизии (Figma Section 9)
*Канонические экраны: Редактор сценария `1:1260`*

| № | Действие / Экран | Метод и Endpoint | Контроллер / Сервис | RBAC | Тест | Статус |
|---|---|---|---|---|---|:---:|
| 9.1 | Список сценариев школы («Мои мероприятия») | `GET /api/v1/scenarios` | `ScenarioController::index` | Пользователи школы | ScenarioLifecycleTest::test_scenarios_index_filters_and_search | **READY** |
| 9.2 | Карточка сценария с актуальной ревизией, этапами и материалами | `GET /api/v1/scenarios/{id}` | `ScenarioController::show` | Пользователи школы | TeacherSignoffTest::test_scenario_and_revision_endpoints_expose_signoff_and_moderation_state | **READY** |
| 9.3 | Просмотр детального конверта конкретной исторической ревизии | `GET /api/v1/scenarios/{id}/revisions/{version}` | `ScenarioRevisionController::show` | Пользователи школы | TeacherSignoffTest::test_scenario_and_revision_endpoints_expose_signoff_and_moderation_state | **READY** |
| 9.4 | Частичное сохранение правок с созданием новой ревизии (`If-Match`) | `PATCH /api/v1/scenarios/{id}/revisions/{version}` | `ScenarioRevisionController::update` | Автор сценария, `school_admin` | ScenarioRevisionEditorTest::test_patch_creates_revision_n_plus_one_and_advances_pointer | **READY** |

---

### Раздел 10: Предвыдачная модерация (P0/P1) и Human Sign-Off (Figma Section 10)
*Канонические экраны: Проверка `98:1009`, Блокировка P0 `98:1439`, Допуск `98:2307`*

| № | Действие / Экран | Метод и Endpoint | Контроллер / Сервис | RBAC | Тест | Статус |
|---|---|---|---|---|---|:---:|
| 10.1 | Просмотр журнала находок автоматической модерации ревизии | `GET /api/v1/scenarios/{id}/revisions/{version}/verifications` | `RevisionModerationController::getVerifications` | Пользователи школы | ModerationEngineTest::test_get_verifications_endpoint_returns_latest_run_and_findings | **READY** |
| 10.2 | Запуск повторной автоматической верификации ревизии | `POST /api/v1/scenarios/{id}/revisions/{version}/verify` | `RevisionModerationController::verify` | Автор сценария | ModerationIntegrityAndHardeningTest::test_http_post_verify_and_job_lifecycle_creates_single_run | **READY** |
| 10.3 | Подтверждение готовности педагогом (Human Sign-Off) по чек-листу | `POST /api/v1/scenarios/{id}/revisions/{version}/signoff` | `TeacherSignoffController::signoff` | `teacher` (текущей школы) | TeacherSignoffTest::test_successful_signoff_creates_historical_record_and_keeps_moderation_status | **READY** |

---

### Раздел 11: Мои мероприятия: фильтрация, архивация, удаление (Figma Section 11)
*Канонические экраны: Мои мероприятия `1:639`, Диалог удаления `1:710`*

| № | Действие / Экран | Метод и Endpoint | Контроллер / Сервис | RBAC | Тест | Статус |
|---|---|---|---|---|---|:---:|
| 11.1 | Фильтрация по статусам (`draft`, `completed`, `archived`) и датам | `GET /api/v1/scenarios` | `ScenarioController::index` | Пользователи школы | ScenarioLifecycleTest::test_scenarios_index_filters_and_search | **READY** |
| 11.2 | Перевод сценария в архив | `POST /api/v1/scenarios/{id}/archive` | `ScenarioController::archive` | Автор сценария, `school_admin` | ScenarioLifecycleTest::test_archive_scenario | **READY** |
| 11.3 | Восстановление сценария из архива | `POST /api/v1/scenarios/{id}/restore` | `ScenarioController::restore` | Автор сценария, `school_admin` | ScenarioLifecycleTest::test_restore_scenario | **READY** |
| 11.4 | Безопасное мягкое удаление сценария без допусков (`SoftDeletes`) | `DELETE /api/v1/scenarios/{id}` | `ScenarioController::destroy` | Автор сценария, `school_admin` | ScenarioLifecycleTest::test_destroy_scenario_without_signoff_soft_deletes | **READY** |
| 11.5 | P0 Блокировка удаления сценария, имеющего допуск педагога (`409 Conflict`) | `DELETE /api/v1/scenarios/{id}` | `ScenarioController::destroy` | Все роли | ScenarioLifecycleTest::test_destroy_scenario_with_teacher_signoff_returns_409_conflict | **READY** |

---

### Раздел 12: Экспорт полного пакета материалов (Figma Section 12)
*Канонические экраны: Окно экспорта `82:456`*

| № | Действие / Экран | Метод и Endpoint | Контроллер / Сервис | RBAC | Тест | Статус |
|---|---|---|---|---|---|:---:|
| 12.1 | История экспортных задач ревизии с Live Provenance Gate | `GET /api/v1/scenarios/{id}/revisions/{version}/exports` | `ScenarioExportController::listExports` | Пользователи школы | MultiFormatExportAndHistoryTest::test_export_history_endpoint_and_download_availability | **READY** |
| 12.2 | Заказ экспорта (форматы: `pdf`, `docx`, `pptx`, `print_pack_pdf`) | `POST /api/v1/scenarios/{id}/revisions/{version}/exports` | `ScenarioExportController::requestExport` | `teacher`, `school_admin` | MultiFormatExportAndHistoryTest::test_export_docx_success_and_valid_openxml_structure | **READY** |
| 12.3 | Проверка статуса выполнения компиляции документа | `GET /api/v1/export-jobs/{id}` | `ScenarioExportController::showJob` | Пользователи школы | ScenarioPdfExportTest::test_successful_pdf_export_and_download_flow | **READY** |
| 12.4 | Авторизованное скачивание файла с проверкой Live Provenance Gate | `GET /api/v1/export-jobs/{id}/download` | `ScenarioExportController::download` | Пользователи школы | ScenarioPdfExportTest::test_cross_tenant_download_forbidden | **READY** |

---

### Раздел 13: A/B-сравнение сценариев (Figma Section 13)
*Канонические экраны: Экран сравнения вариантов `104:1001`, Выбор варианта `104:1520`*

| № | Действие / Экран | Метод и Endpoint | Контроллер / Сервис | RBAC | Тест | Статус |
|---|---|---|---|---|---|:---:|
| 13.1 | Список сессий A/B-сравнения текущей школы | `GET /api/v1/scenario-comparisons` | `ScenarioComparisonController::index` | `teacher`, `school_admin` | ScenarioComparisonTest::test_can_create_universal_vs_personalized_comparison_and_retrieve_variants | **READY** |
| 13.2 | Создание сессии сравнения (универсальный vs персонализированный / культурный) | `POST /api/v1/scenario-comparisons` | `ScenarioComparisonController::store` | `teacher`, `school_admin` | ScenarioComparisonTest::test_can_create_universal_vs_personalized_comparison_and_retrieve_variants | **READY** |
| 13.3 | Просмотр детальных сведений сессии сравнения | `GET /api/v1/scenario-comparisons/{id}` | `ScenarioComparisonController::show` | Пользователи школы | ScenarioComparisonTest::test_can_create_universal_vs_personalized_comparison_and_retrieve_variants | **READY** |
| 13.4 | Структурированное сопоставление вариантов A и B для интерфейса | `GET /api/v1/scenario-comparisons/{id}/variants` | `ScenarioComparisonController::variants` | Пользователи школы | ScenarioComparisonTest::test_can_create_cultural_context_comparison_with_verified_provenance | **READY** |
| 13.5 | Неизменяемая фиксация выбора варианта педагогом (`409` при повторе) | `POST /api/v1/scenario-comparisons/{id}/selection` | `ScenarioComparisonController::selection` | `teacher`, `school_admin` | ScenarioComparisonTest::test_can_select_preferred_variant_and_cannot_double_record_selection | **READY** |

---

### Раздел 14: Пилотное исследование с 20 педагогами и аналитика (Figma Section 14)
*Канонические экраны: Форма тестирования педагога `105:1186`, Результаты пилота `105:1403`*

| № | Действие / Экран | Метод и Endpoint | Контроллер / Сервис | RBAC | Тест | Статус |
|---|---|---|---|---|---|:---:|
| 14.1 | Реестр псевдонимизированных участников пилота школы | `GET /api/v1/pilot/participants` | `PilotParticipantController::index` | `school_admin`, `catalog_curator`, `system_admin` | PilotStudyAndAnalyticsTest::test_teacher_cannot_manage_pilot_participants | **READY** |
| 14.2 | Регистрация участника с выдачей безопасного одноразового токена | `POST /api/v1/pilot/participants` | `PilotParticipantController::store` | `school_admin`, `catalog_curator`, `system_admin` | PilotStudyAndAnalyticsTest::test_can_create_pilot_participant_invite_and_verify_token_hashing | **READY** |
| 14.3 | Перевыпуск пригласительного токена участника | `POST /api/v1/pilot/participants/{id}/reissue` | `PilotParticipantController::reissue` | `school_admin`, `catalog_curator`, `system_admin` | PilotStudyAndAnalyticsTest::test_token_reissue_and_revocation_lifecycle | **READY** |
| 14.4 | Безотзывное аннулирование приглашения участника | `POST /api/v1/pilot/participants/{id}/revoke` | `PilotParticipantController::revoke` | `school_admin`, `catalog_curator`, `system_admin` | PilotStudyAndAnalyticsTest::test_token_reissue_and_revocation_lifecycle | **READY** |
| 14.5 | Отправка анкеты педагога по одноразовому пригласительному токену | `POST /api/v1/pilot/responses` | `PilotResponseController::store` | Публичный (по токену приглашения) | PilotStudyAndAnalyticsTest::test_can_submit_pilot_response_with_single_use_token_policy | **READY** |
| 14.6 | Просмотр детальных ответов анкеты педагога | `GET /api/v1/pilot/responses/{id}` | `PilotResponseController::show` | `school_admin`, `catalog_curator`, `system_admin` | PilotStudyAndAnalyticsTest::test_teacher_cannot_read_individual_responses_or_export | **READY** |
| 14.7 | Внесение экспертного заключения (версионируемое, tenant-isolated) | `POST /api/v1/pilot/responses/{id}/reviews` | `PilotResponseController::submitReview` | `catalog_curator` (по региону), `school_admin` (своей школы) | PilotStudyAndAnalyticsTest::test_pilot_review_finding_append_only_history | **READY** |
| 14.8 | Расчёт количественных метрик пилота (N=20, пороги, лестница состояний) | `GET /api/v1/pilot/metrics` | `PilotMetricsController::summary` | Пользователи школы, кураторы, админы | PilotStudyAndAnalyticsTest::test_synthetic_responses_excluded_by_default_and_included_with_flag | **READY** |
| 14.9 | Экспорт полного аналитического отчёта результатов исследования | `GET /api/v1/pilot/export` | `PilotMetricsController::export` | `school_admin`, `catalog_curator`, `system_admin` | PilotStudyAndAnalyticsTest::test_regional_curator_cannot_access_global_metrics_or_cross_region_schools | **READY** |

---

### Раздел 15: Профиль образовательной организации и настройки (Figma Section 15)
*Канонические экраны: Настройки школы `108:1200`*

| № | Действие / Экран | Метод и Endpoint | Контроллер / Сервис | RBAC | Тест | Статус |
|---|---|---|---|---|---|:---:|
| 15.1 | Просмотр профиля школы с реквизитами, контактами и счётчиками | `GET /api/v1/schools/{id}` | `SchoolProfileController::show` | Участники школы, `system_admin` | SchoolProfileAndTraditionsTest::test_get_school_profile_success | **READY** |
| 15.2 | Редактирование реквизитов школы и параметров оформления | `PATCH /api/v1/schools/{id}` | `SchoolProfileController::update` | `school_admin` этой школы, `system_admin` | SchoolProfileAndTraditionsTest::test_patch_school_profile_success_for_school_admin | **READY** |

---

## 3. Анализ неподтверждённых действий (Unconfirmed / Not In Design v1.0)

В соответствии с правилом строгой методологической честности, серверная часть **не изобретает фичи**, отсутствующие в утверждённом дизайне Design v1.0:

1. **Самостоятельное восстановление пароля (`POST /api/v1/auth/forgot-password`):**
   - *Статус:* `UNCONFIRMED` (Зарезервировано в `x-future-endpoints` спецификации OpenAPI).
   - *Обоснование:* В Design v1.0 (канонический экран авторизации `59:456`) предусмотрена форма входа с полями email и пароль. Отдельный сценарий сброса пароля через email в утверждённых 60 потоках прототипа отсутствует. В академическом пилоте учётные записи предоставляются координатором.
2. **CRUD пользователей школы внутри кабинета администратора:**
   - *Статус:* `UNCONFIRMED`.
   - *Обоснование:* В Design v1.0 (экраны `108:1200`) предусмотрено отображение счётчика активных педагогов (`active_teachers_count`), но экран добавления/удаления учителей отсутствует. Управление доступом выполняется на системном уровне либо через сидеры.
3. **Произвольное редактирование федерального/регионального календаря учителями:**
   - *Статус:* `UNCONFIRMED`.
   - *Обоснование:* В ТЗ и дизайне календарь является валидированным нормативным источником. Учителям разрешено добавление только мероприятий своей школы (`school_custom`). Федеральный и региональный реестры курируются исключительно региональными экспертами (`catalog_curator`) через очередь модерации `review-queue`.

---

## 4. Гарантии целостности и готовность к релизу

1. **Тестовое покрытие:**
   - Всего Feature/Integration тестов: **286**
   - Всего проверок (assertions): **1427**
   - Процент успешного прохождения: **100% OK** (0 failures, 0 errors).
   - Верификация ссылок в матрице: **62/62 (100% валидны)**, подтверждено scripts/verify_matrix_test_references.py.
2. **Согласованность API-контракта:**
   - Активных производственных операций Laravel: **58**
   - Активных операций OpenAPI 3.1.0: **58**
   - Расхождений контракта: **0** (100% agreement, подтверждено `scripts/verify_route_contract.py`).
3. **Изоляция контуров:**
   - Выделенный тестовый контейнер `app-test` и веб-сервер `nginx-test` (порт 8001, strictly `127.0.0.1`).
   - Изоляция профилем Docker Compose (`profiles: ["testing"]`).
   - Изоляция на уровне СУБД: тестовый пользователь `school_test_user` не имеет прав подключения (`REVOKE CONNECT`) к основной рабочей базе `school_events`.
   - Изоляция кэша, сессий и файлового хранилища: независимые префиксы Redis, отдельное имя сессионной куки, выделенная директория `storage/app/testing/private`.
4. **Готовность к внешним LLM:**
   - Абстракция `LlmProviderInterface` полностью отделена от бизнес-логики.
   - Подготовлена подробная спецификация подключения GigaChat, OpenAI и YandexGPT (`docs/llm-provider-integration.md`).
