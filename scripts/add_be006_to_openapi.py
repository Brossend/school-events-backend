#!/usr/bin/env python3
"""
Adds BE-006 routes and schemas to docs/openapi.yaml
"""
import yaml
from pathlib import Path

OPENAPI_PATH = Path("docs/openapi.yaml")

with open(OPENAPI_PATH, "r", encoding="utf-8") as f:
    spec = yaml.safe_load(f)

# 1. Update version and description
spec["info"]["version"] = "1.3.0"
spec["info"]["description"] = (
    "REST API v1 бэкенда образовательного сервиса «Школьные события» (Сбер × УрФУ). "
    "Полная реализация серверных возможностей Design v1.0 (BE-006: этнокультурная экспертиза, "
    "жизненный цикл сценариев, профиль школы, традиции, масштабируемый конструктор, дашборд)."
)

# 2. Add Conflict response if not present
if "Conflict" not in spec["components"]["responses"]:
    spec["components"]["responses"]["Conflict"] = {
        "description": "Конфликт состояния ресурса (например, попытка удаления сценария с подписанным педагогическим допуском)",
        "content": {
            "application/json": {
                "schema": {
                    "$ref": "#/components/schemas/ErrorResponse"
                }
            }
        }
    }

paths = spec["paths"]

# 3. New Paths
paths["/catalog/review-queue"] = {
    "get": {
        "summary": "Очередь первоисточников и утверждений для экспертной рецензии",
        "operationId": "getCatalogReviewQueue",
        "tags": ["Cultural Catalog"],
        "security": [{"cookieAuth": []}],
        "parameters": [
            {"name": "status", "in": "query", "required": False, "schema": {"type": "string", "enum": ["pending", "approved", "rejected", "requires_amendment", "all"], "default": "pending"}},
            {"name": "region_code", "in": "query", "required": False, "schema": {"type": "string"}},
            {"name": "event_type", "in": "query", "required": False, "schema": {"type": "string"}},
            {"name": "page", "in": "query", "required": False, "schema": {"type": "integer", "default": 1}},
            {"name": "per_page", "in": "query", "required": False, "schema": {"type": "integer", "default": 20}}
        ],
        "responses": {
            "200": {
                "description": "Список первоисточников в очереди рецензирования",
                "content": {"application/json": {"schema": {"$ref": "#/components/schemas/ReviewQueueResponse"}}}
            },
            "401": {"$ref": "#/components/responses/Unauthorized"},
            "403": {"$ref": "#/components/responses/Forbidden"}
        }
    }
}

paths["/event-sources/{id}"] = {
    "get": {
        "summary": "Детальная карточка первоисточника с историей рецензий и привязанными утверждениями",
        "operationId": "getEventSource",
        "tags": ["Cultural Catalog"],
        "security": [{"cookieAuth": []}],
        "parameters": [
            {"name": "id", "in": "path", "required": True, "schema": {"type": "string", "format": "uuid"}}
        ],
        "responses": {
            "200": {
                "description": "Данные первоисточника",
                "content": {"application/json": {"schema": {"$ref": "#/components/schemas/EventSourceEnvelope"}}}
            },
            "401": {"$ref": "#/components/responses/Unauthorized"},
            "404": {"$ref": "#/components/responses/NotFound"}
        }
    },
    "patch": {
        "summary": "Редактирование библиографических сведений первоисточника",
        "operationId": "updateEventSource",
        "tags": ["Cultural Catalog"],
        "security": [{"cookieAuth": []}],
        "parameters": [
            {"name": "id", "in": "path", "required": True, "schema": {"type": "string", "format": "uuid"}}
        ],
        "requestBody": {
            "required": True,
            "content": {
                "application/json": {
                    "schema": {
                        "type": "object",
                        "properties": {
                            "title": {"type": "string"},
                            "author": {"type": "string", "nullable": True},
                            "source_type": {"type": "string", "enum": ["normative_act", "scientific_publication", "ethnographic_archive", "encyclopedia"]},
                            "url": {"type": "string", "nullable": True},
                            "doi_or_isbn": {"type": "string", "nullable": True}
                        }
                    }
                }
            }
        },
        "responses": {
            "200": {
                "description": "Первоисточник успешно обновлен",
                "content": {"application/json": {"schema": {"$ref": "#/components/schemas/EventSourceEnvelope"}}}
            },
            "401": {"$ref": "#/components/responses/Unauthorized"},
            "403": {"$ref": "#/components/responses/Forbidden"},
            "404": {"$ref": "#/components/responses/NotFound"},
            "422": {"$ref": "#/components/responses/ValidationError"}
        }
    }
}

paths["/event-sources/{id}/reviews"] = {
    "get": {
        "summary": "Полный хронологический журнал экспертных рецензий первоисточника",
        "operationId": "listEventSourceReviews",
        "tags": ["Cultural Catalog"],
        "security": [{"cookieAuth": []}],
        "parameters": [
            {"name": "id", "in": "path", "required": True, "schema": {"type": "string", "format": "uuid"}}
        ],
        "responses": {
            "200": {
                "description": "Список экспертных рецензий",
                "content": {"application/json": {"schema": {"$ref": "#/components/schemas/SourceReviewListResponse"}}}
            },
            "401": {"$ref": "#/components/responses/Unauthorized"},
            "403": {"$ref": "#/components/responses/Forbidden"},
            "404": {"$ref": "#/components/responses/NotFound"}
        }
    },
    "post": {
        "summary": "Создание экспертной рецензии на первоисточник куратором или администратором",
        "operationId": "createEventSourceReview",
        "tags": ["Cultural Catalog"],
        "security": [{"cookieAuth": []}],
        "parameters": [
            {"name": "id", "in": "path", "required": True, "schema": {"type": "string", "format": "uuid"}}
        ],
        "requestBody": {
            "required": True,
            "content": {
                "application/json": {
                    "schema": {
                        "type": "object",
                        "required": ["decision", "comments"],
                        "properties": {
                            "decision": {"type": "string", "enum": ["approved", "rejected", "requires_amendment"]},
                            "comments": {"type": "string", "minLength": 5, "maxLength": 2000}
                        }
                    }
                }
            }
        },
        "responses": {
            "201": {
                "description": "Экспертная рецензия успешно зафиксирована",
                "content": {"application/json": {"schema": {"$ref": "#/components/schemas/SourceReviewEnvelope"}}}
            },
            "401": {"$ref": "#/components/responses/Unauthorized"},
            "403": {"$ref": "#/components/responses/Forbidden"},
            "404": {"$ref": "#/components/responses/NotFound"},
            "422": {"$ref": "#/components/responses/ValidationError"}
        }
    }
}

paths["/cultural-claims/{id}"] = {
    "get": {
        "summary": "Карточка культурного утверждения с привязанными свидетельствами первоисточников",
        "operationId": "getCulturalClaim",
        "tags": ["Cultural Catalog"],
        "security": [{"cookieAuth": []}],
        "parameters": [
            {"name": "id", "in": "path", "required": True, "schema": {"type": "string", "format": "uuid"}}
        ],
        "responses": {
            "200": {
                "description": "Данные культурного утверждения",
                "content": {"application/json": {"schema": {"$ref": "#/components/schemas/CulturalClaimEnvelope"}}}
            },
            "401": {"$ref": "#/components/responses/Unauthorized"},
            "404": {"$ref": "#/components/responses/NotFound"}
        }
    }
}

paths["/cultural-claims/{id}/evidence"] = {
    "post": {
        "summary": "Привязка или верификация свидетельства первоисточника для утверждения",
        "operationId": "attachCulturalClaimEvidence",
        "tags": ["Cultural Catalog"],
        "security": [{"cookieAuth": []}],
        "parameters": [
            {"name": "id", "in": "path", "required": True, "schema": {"type": "string", "format": "uuid"}}
        ],
        "requestBody": {
            "required": True,
            "content": {
                "application/json": {
                    "schema": {
                        "type": "object",
                        "required": ["event_source_id", "citation_locator", "citation_excerpt"],
                        "properties": {
                            "event_source_id": {"type": "string", "format": "uuid"},
                            "citation_locator": {"type": "string", "minLength": 3},
                            "citation_excerpt": {"type": "string", "minLength": 10},
                            "applicable_academic_year": {"type": "string", "nullable": True},
                            "notes": {"type": "string", "nullable": True},
                            "verify": {"type": "boolean", "default": True}
                        }
                    }
                }
            }
        },
        "responses": {
            "201": {
                "description": "Свидетельство успешно привязано/верифицировано",
                "content": {"application/json": {"schema": {"$ref": "#/components/schemas/CulturalClaimEvidenceEnvelope"}}}
            },
            "401": {"$ref": "#/components/responses/Unauthorized"},
            "403": {"$ref": "#/components/responses/Forbidden"},
            "404": {"$ref": "#/components/responses/NotFound"},
            "422": {"$ref": "#/components/responses/ValidationError"}
        }
    }
}

paths["/cultural-claims/{id}/evidence/{sourceId}"] = {
    "delete": {
        "summary": "Отвязка свидетельства первоисточника от культурного утверждения",
        "operationId": "detachCulturalClaimEvidence",
        "tags": ["Cultural Catalog"],
        "security": [{"cookieAuth": []}],
        "parameters": [
            {"name": "id", "in": "path", "required": True, "schema": {"type": "string", "format": "uuid"}},
            {"name": "sourceId", "in": "path", "required": True, "schema": {"type": "string", "format": "uuid"}}
        ],
        "responses": {
            "200": {
                "description": "Свидетельство успешно отвязано",
                "content": {
                    "application/json": {
                        "schema": {
                            "type": "object",
                            "required": ["data"],
                            "properties": {
                                "data": {
                                    "type": "object",
                                    "required": ["message"],
                                    "properties": {
                                        "message": {"type": "string"}
                                    }
                                }
                            }
                        }
                    }
                }
            },
            "401": {"$ref": "#/components/responses/Unauthorized"},
            "403": {"$ref": "#/components/responses/Forbidden"},
            "404": {"$ref": "#/components/responses/NotFound"}
        }
    }
}

paths["/schools/{id}"] = {
    "get": {
        "summary": "Профиль образовательной организации со сводной статистикой",
        "operationId": "getSchoolProfile",
        "tags": ["School Profile"],
        "security": [{"cookieAuth": []}],
        "parameters": [
            {"name": "id", "in": "path", "required": True, "schema": {"type": "string", "format": "uuid"}}
        ],
        "responses": {
            "200": {
                "description": "Данные профиля школы",
                "content": {"application/json": {"schema": {"$ref": "#/components/schemas/SchoolProfileEnvelope"}}}
            },
            "401": {"$ref": "#/components/responses/Unauthorized"},
            "403": {"$ref": "#/components/responses/Forbidden"},
            "404": {"$ref": "#/components/responses/NotFound"}
        }
    },
    "patch": {
        "summary": "Обновление сведений и настроек образовательной организации",
        "operationId": "updateSchoolProfile",
        "tags": ["School Profile"],
        "security": [{"cookieAuth": []}],
        "parameters": [
            {"name": "id", "in": "path", "required": True, "schema": {"type": "string", "format": "uuid"}}
        ],
        "requestBody": {
            "required": True,
            "content": {
                "application/json": {
                    "schema": {
                        "type": "object",
                        "properties": {
                            "name": {"type": "string"},
                            "short_name": {"type": "string", "nullable": True},
                            "city": {"type": "string"},
                            "inn": {"type": "string", "nullable": True},
                            "settings": {"type": "object"}
                        }
                    }
                }
            }
        },
        "responses": {
            "200": {
                "description": "Профиль школы успешно обновлен",
                "content": {"application/json": {"schema": {"$ref": "#/components/schemas/SchoolProfileEnvelope"}}}
            },
            "401": {"$ref": "#/components/responses/Unauthorized"},
            "403": {"$ref": "#/components/responses/Forbidden"},
            "404": {"$ref": "#/components/responses/NotFound"},
            "422": {"$ref": "#/components/responses/ValidationError"}
        }
    }
}

paths["/dashboard/summary"] = {
    "get": {
        "summary": "Агрегированная сводка главной страницы для активной школы",
        "operationId": "getDashboardSummary",
        "tags": ["Dashboard"],
        "security": [{"cookieAuth": []}],
        "responses": {
            "200": {
                "description": "Сводная информация главной панели",
                "content": {"application/json": {"schema": {"$ref": "#/components/schemas/DashboardSummaryEnvelope"}}}
            },
            "401": {"$ref": "#/components/responses/Unauthorized"},
            "403": {"$ref": "#/components/responses/Forbidden"}
        }
    }
}

paths["/traditions/{id}"] = {
    "get": {
        "summary": "Детальная карточка школьной традиции со всеми годовыми версиями",
        "operationId": "getTradition",
        "tags": ["Traditions"],
        "security": [{"cookieAuth": []}],
        "parameters": [
            {"name": "id", "in": "path", "required": True, "schema": {"type": "string", "format": "uuid"}}
        ],
        "responses": {
            "200": {
                "description": "Данные традиции с историей версий",
                "content": {"application/json": {"schema": {"$ref": "#/components/schemas/TraditionDetailEnvelope"}}}
            },
            "401": {"$ref": "#/components/responses/Unauthorized"},
            "403": {"$ref": "#/components/responses/Forbidden"},
            "404": {"$ref": "#/components/responses/NotFound"}
        }
    },
    "patch": {
        "summary": "Редактирование метаданных традиции (исторические версии неизменны)",
        "operationId": "updateTradition",
        "tags": ["Traditions"],
        "security": [{"cookieAuth": []}],
        "parameters": [
            {"name": "id", "in": "path", "required": True, "schema": {"type": "string", "format": "uuid"}}
        ],
        "requestBody": {
            "required": True,
            "content": {
                "application/json": {
                    "schema": {
                        "type": "object",
                        "properties": {
                            "title": {"type": "string"},
                            "description": {"type": "string"},
                            "established_year": {"type": "integer", "nullable": True}
                        }
                    }
                }
            }
        },
        "responses": {
            "200": {
                "description": "Традиция успешно обновлена",
                "content": {"application/json": {"schema": {"$ref": "#/components/schemas/TraditionEnvelope"}}}
            },
            "401": {"$ref": "#/components/responses/Unauthorized"},
            "403": {"$ref": "#/components/responses/Forbidden"},
            "404": {"$ref": "#/components/responses/NotFound"},
            "422": {"$ref": "#/components/responses/ValidationError"}
        }
    }
}

paths["/traditions/{id}/archive"] = {
    "post": {
        "summary": "Архивирование школьной традиции",
        "operationId": "archiveTradition",
        "tags": ["Traditions"],
        "security": [{"cookieAuth": []}],
        "parameters": [
            {"name": "id", "in": "path", "required": True, "schema": {"type": "string", "format": "uuid"}}
        ],
        "responses": {
            "200": {
                "description": "Традиция успешно архивирована",
                "content": {"application/json": {"schema": {"$ref": "#/components/schemas/TraditionEnvelope"}}}
            },
            "401": {"$ref": "#/components/responses/Unauthorized"},
            "403": {"$ref": "#/components/responses/Forbidden"},
            "404": {"$ref": "#/components/responses/NotFound"}
        }
    }
}

# Add delete to /scenarios/{id}
paths["/scenarios/{id}"]["delete"] = {
    "summary": "Безопасное soft-delete удаление сценария (блокируется при наличии sign-off)",
    "operationId": "deleteScenario",
    "tags": ["Scenarios"],
    "security": [{"cookieAuth": []}],
    "parameters": [
        {"name": "id", "in": "path", "required": True, "schema": {"type": "string", "format": "uuid"}}
    ],
    "responses": {
        "200": {
            "description": "Сценарий успешно удален",
            "content": {
                "application/json": {
                    "schema": {
                        "type": "object",
                        "required": ["data"],
                        "properties": {
                            "data": {
                                "type": "object",
                                "required": ["message"],
                                "properties": {
                                    "message": {"type": "string"}
                                }
                            }
                        }
                    }
                }
            }
        },
        "401": {"$ref": "#/components/responses/Unauthorized"},
        "403": {"$ref": "#/components/responses/Forbidden"},
        "404": {"$ref": "#/components/responses/NotFound"},
        "409": {"$ref": "#/components/responses/Conflict"}
    }
}

paths["/scenarios/{id}/archive"] = {
    "post": {
        "summary": "Архивирование сценария с сохранением всех ревизий и допусков",
        "operationId": "archiveScenario",
        "tags": ["Scenarios"],
        "security": [{"cookieAuth": []}],
        "parameters": [
            {"name": "id", "in": "path", "required": True, "schema": {"type": "string", "format": "uuid"}}
        ],
        "responses": {
            "200": {
                "description": "Сценарий успешно архивирован",
                "content": {"application/json": {"schema": {"$ref": "#/components/schemas/ScenarioLifecycleEnvelope"}}}
            },
            "401": {"$ref": "#/components/responses/Unauthorized"},
            "403": {"$ref": "#/components/responses/Forbidden"},
            "404": {"$ref": "#/components/responses/NotFound"}
        }
    }
}

paths["/scenarios/{id}/restore"] = {
    "post": {
        "summary": "Восстановление архивированного сценария в рабочий реестр",
        "operationId": "restoreScenario",
        "tags": ["Scenarios"],
        "security": [{"cookieAuth": []}],
        "parameters": [
            {"name": "id", "in": "path", "required": True, "schema": {"type": "string", "format": "uuid"}}
        ],
        "responses": {
            "200": {
                "description": "Сценарий успешно восстановлен",
                "content": {"application/json": {"schema": {"$ref": "#/components/schemas/ScenarioLifecycleEnvelope"}}}
            },
            "401": {"$ref": "#/components/responses/Unauthorized"},
            "403": {"$ref": "#/components/responses/Forbidden"},
            "404": {"$ref": "#/components/responses/NotFound"}
        }
    }
}

# 4. New Schemas in components.schemas
schemas = spec["components"]["schemas"]

schemas["ReviewQueueResponse"] = {
    "type": "object",
    "required": ["data", "meta"],
    "properties": {
        "data": {
            "type": "array",
            "items": {"$ref": "#/components/schemas/ReviewQueueItem"}
        },
        "meta": {"$ref": "#/components/schemas/PaginationMeta"}
    }
}

schemas["ReviewQueueItem"] = {
    "type": "object",
    "required": ["id", "calendar_event_id", "source_type", "title", "status", "created_at"],
    "properties": {
        "id": {"type": "string", "format": "uuid"},
        "calendar_event_id": {"type": "string", "format": "uuid"},
        "calendar_event": {
            "type": "object",
            "nullable": True,
            "properties": {
                "id": {"type": "string", "format": "uuid"},
                "title": {"type": "string"},
                "event_type": {"type": "string"},
                "region_code": {"type": "string", "nullable": True},
                "status": {"type": "string"}
            }
        },
        "source_type": {"type": "string"},
        "title": {"type": "string"},
        "author": {"type": "string", "nullable": True},
        "url": {"type": "string", "nullable": True},
        "doi_or_isbn": {"type": "string", "nullable": True},
        "claims_count": {"type": "integer"},
        "status": {"type": "string", "enum": ["pending", "approved", "rejected", "requires_amendment"]},
        "latest_review": {
            "type": "object",
            "nullable": True,
            "properties": {
                "id": {"type": "string", "format": "uuid"},
                "decision": {"type": "string"},
                "comments": {"type": "string"},
                "reviewer": {"type": "object", "nullable": True},
                "reviewed_at": {"type": "string", "format": "date-time"}
            }
        },
        "verified_by": {"type": "object", "nullable": True},
        "verification_date": {"type": "string", "nullable": True},
        "created_at": {"type": "string", "format": "date-time"}
    }
}

schemas["EventSourceEnvelope"] = {
    "type": "object",
    "required": ["data"],
    "properties": {
        "data": {"$ref": "#/components/schemas/EventSourceDetail"}
    }
}

schemas["EventSourceDetail"] = {
    "type": "object",
    "required": ["id", "calendar_event_id", "source_type", "title", "created_at"],
    "properties": {
        "id": {"type": "string", "format": "uuid"},
        "calendar_event_id": {"type": "string", "format": "uuid"},
        "calendar_event": {"type": "object", "nullable": True},
        "source_type": {"type": "string"},
        "title": {"type": "string"},
        "author": {"type": "string", "nullable": True},
        "url": {"type": "string", "nullable": True},
        "doi_or_isbn": {"type": "string", "nullable": True},
        "verified_by": {"type": "object", "nullable": True},
        "verification_date": {"type": "string", "nullable": True},
        "latest_review": {"type": "object", "nullable": True},
        "reviews_count": {"type": "integer"},
        "claims_count": {"type": "integer"},
        "claims": {"type": "array", "items": {"type": "object"}},
        "created_at": {"type": "string", "format": "date-time"}
    }
}

schemas["SourceReviewListResponse"] = {
    "type": "object",
    "required": ["data"],
    "properties": {
        "data": {
            "type": "array",
            "items": {"$ref": "#/components/schemas/SourceReviewItem"}
        }
    }
}

schemas["SourceReviewEnvelope"] = {
    "type": "object",
    "required": ["data"],
    "properties": {
        "data": {"$ref": "#/components/schemas/SourceReviewItem"}
    }
}

schemas["SourceReviewItem"] = {
    "type": "object",
    "required": ["id", "event_source_id", "decision", "comments", "reviewer", "reviewed_at"],
    "properties": {
        "id": {"type": "string", "format": "uuid"},
        "event_source_id": {"type": "string", "format": "uuid"},
        "decision": {"type": "string", "enum": ["approved", "rejected", "requires_amendment"]},
        "comments": {"type": "string"},
        "reviewer": {
            "type": "object",
            "required": ["id", "name", "system_role"],
            "properties": {
                "id": {"type": "string", "format": "uuid"},
                "name": {"type": "string"},
                "system_role": {"type": "string"},
                "curator_scope": {"type": "string", "nullable": True}
            }
        },
        "reviewed_at": {"type": "string", "format": "date-time"}
    }
}

schemas["CulturalClaimEnvelope"] = {
    "type": "object",
    "required": ["data"],
    "properties": {
        "data": {"$ref": "#/components/schemas/CulturalClaimDetail"}
    }
}

schemas["CulturalClaimDetail"] = {
    "type": "object",
    "required": ["id", "calendar_event_id", "claim_text", "culture_name", "evidence_records", "created_at"],
    "properties": {
        "id": {"type": "string", "format": "uuid"},
        "calendar_event_id": {"type": "string", "format": "uuid"},
        "calendar_event": {"type": "object", "nullable": True},
        "claim_text": {"type": "string"},
        "culture_name": {"type": "string"},
        "provenance_notes": {"type": "string", "nullable": True},
        "evidence_records": {
            "type": "array",
            "items": {"$ref": "#/components/schemas/CulturalClaimEvidenceItem"}
        },
        "created_at": {"type": "string", "format": "date-time"}
    }
}

schemas["CulturalClaimEvidenceEnvelope"] = {
    "type": "object",
    "required": ["data"],
    "properties": {
        "data": {"$ref": "#/components/schemas/CulturalClaimEvidenceItem"}
    }
}

schemas["CulturalClaimEvidenceItem"] = {
    "type": "object",
    "required": ["id", "cultural_claim_id", "event_source_id", "citation_locator", "citation_excerpt", "verification_status", "effective_status"],
    "properties": {
        "id": {"type": "string", "format": "uuid"},
        "cultural_claim_id": {"type": "string", "format": "uuid"},
        "event_source_id": {"type": "string", "format": "uuid"},
        "source": {"type": "object", "nullable": True},
        "citation_locator": {"type": "string"},
        "citation_excerpt": {"type": "string"},
        "verification_status": {"type": "string"},
        "effective_status": {"type": "string"},
        "verified_by": {"type": "object", "nullable": True},
        "verified_at": {"type": "string", "format": "date-time", "nullable": True},
        "applicable_academic_year": {"type": "string", "nullable": True},
        "notes": {"type": "string", "nullable": True},
        "created_at": {"type": "string", "format": "date-time"}
    }
}

schemas["SchoolProfileEnvelope"] = {
    "type": "object",
    "required": ["data"],
    "properties": {
        "data": {"$ref": "#/components/schemas/SchoolProfileDetail"}
    }
}

schemas["SchoolProfileDetail"] = {
    "type": "object",
    "required": ["id", "name", "region_code", "city", "traditions_count", "active_teachers_count"],
    "properties": {
        "id": {"type": "string", "format": "uuid"},
        "name": {"type": "string"},
        "short_name": {"type": "string", "nullable": True},
        "region_code": {"type": "string"},
        "city": {"type": "string"},
        "inn": {"type": "string", "nullable": True},
        "settings": {"type": "object"},
        "traditions_count": {"type": "integer"},
        "active_teachers_count": {"type": "integer"},
        "created_at": {"type": "string", "format": "date-time", "nullable": True},
        "updated_at": {"type": "string", "format": "date-time", "nullable": True}
    }
}

schemas["DashboardSummaryEnvelope"] = {
    "type": "object",
    "required": ["data"],
    "properties": {
        "data": {"$ref": "#/components/schemas/DashboardSummaryDetail"}
    }
}

schemas["DashboardSummaryDetail"] = {
    "type": "object",
    "required": ["school", "upcoming_events", "recent_scenarios", "counts"],
    "properties": {
        "school": {
            "type": "object",
            "required": ["id", "name", "region_code", "city"],
            "properties": {
                "id": {"type": "string", "format": "uuid"},
                "name": {"type": "string"},
                "short_name": {"type": "string", "nullable": True},
                "region_code": {"type": "string"},
                "city": {"type": "string"}
            }
        },
        "upcoming_events": {
            "type": "array",
            "items": {"type": "object"}
        },
        "recent_scenarios": {
            "type": "array",
            "items": {"type": "object"}
        },
        "counts": {
            "type": "object",
            "required": ["total_scenarios", "draft_scenarios", "completed_scenarios", "archived_scenarios", "traditions_count"],
            "properties": {
                "total_scenarios": {"type": "integer"},
                "draft_scenarios": {"type": "integer"},
                "completed_scenarios": {"type": "integer"},
                "archived_scenarios": {"type": "integer"},
                "traditions_count": {"type": "integer"}
            }
        }
    }
}

schemas["TraditionDetailEnvelope"] = {
    "type": "object",
    "required": ["data"],
    "properties": {
        "data": {"$ref": "#/components/schemas/TraditionDetail"}
    }
}

schemas["TraditionDetail"] = {
    "type": "object",
    "required": ["id", "school_id", "title", "description", "is_archived", "versions"],
    "properties": {
        "id": {"type": "string", "format": "uuid"},
        "school_id": {"type": "string", "format": "uuid"},
        "title": {"type": "string"},
        "description": {"type": "string"},
        "established_year": {"type": "integer", "nullable": True},
        "is_archived": {"type": "boolean"},
        "created_by_user_id": {"type": "string", "format": "uuid", "nullable": True},
        "created_at": {"type": "string", "format": "date-time", "nullable": True},
        "updated_at": {"type": "string", "format": "date-time", "nullable": True},
        "active_version": {"type": "object", "nullable": True},
        "versions": {
            "type": "array",
            "items": {"type": "object"}
        }
    }
}

schemas["TraditionEnvelope"] = {
    "type": "object",
    "required": ["data"],
    "properties": {
        "data": {"$ref": "#/components/schemas/TraditionSummary"}
    }
}

schemas["TraditionSummary"] = {
    "type": "object",
    "required": ["id", "title", "is_archived"],
    "properties": {
        "id": {"type": "string", "format": "uuid"},
        "title": {"type": "string"},
        "description": {"type": "string", "nullable": True},
        "established_year": {"type": "integer", "nullable": True},
        "is_archived": {"type": "boolean"},
        "updated_at": {"type": "string", "format": "date-time", "nullable": True}
    }
}

schemas["ScenarioLifecycleEnvelope"] = {
    "type": "object",
    "required": ["data"],
    "properties": {
        "data": {"$ref": "#/components/schemas/ScenarioLifecycleSummary"}
    }
}

schemas["ScenarioLifecycleSummary"] = {
    "type": "object",
    "required": ["id", "title", "status", "is_archived"],
    "properties": {
        "id": {"type": "string", "format": "uuid"},
        "title": {"type": "string"},
        "status": {"type": "string"},
        "is_archived": {"type": "boolean"},
        "updated_at": {"type": "string", "format": "date-time", "nullable": True}
    }
}

with open(OPENAPI_PATH, "w", encoding="utf-8") as f:
    yaml.dump(spec, f, allow_unicode=True, sort_keys=False)

print("[+] Successfully updated docs/openapi.yaml with BE-006 contracts!")
