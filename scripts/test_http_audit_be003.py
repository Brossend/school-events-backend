#!/usr/bin/env python3
"""
Live HTTP Gateway & Security Audit for BE-003.1:
Scenario Constructor, Mock LLM & Asynchronous Generation.
Executes against real running Nginx / PHP-FPM / Redis / PostgreSQL Docker stack.
"""

import http.cookiejar
import json
import os
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid

BASE_URL = os.environ.get("BASE_URL", "http://localhost:8000")
TEACHER_PASSWORD = os.environ.get("DEMO_TEACHER_PASSWORD")
MULTI_PASSWORD = os.environ.get("DEMO_MULTI_PASSWORD")
if not TEACHER_PASSWORD or not MULTI_PASSWORD:
    raise RuntimeError(
        "DEMO_TEACHER_PASSWORD and DEMO_MULTI_PASSWORD environment variables are required and must be set."
    )


class LiveAuditClient:
    def __init__(self, base_url: str):
        self.base_url = base_url.rstrip("/")
        self.cookie_jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(
            urllib.request.HTTPCookieProcessor(self.cookie_jar)
        )
        self.csrf_token = None

    def get_csrf_token(self):
        url = f"{self.base_url}/sanctum/csrf-cookie"
        req = urllib.request.Request(
            url,
            headers={
                "Origin": self.base_url,
                "Referer": f"{self.base_url}/",
            },
            method="GET",
        )
        self.opener.open(req)
        for cookie in self.cookie_jar:
            if cookie.name == "XSRF-TOKEN":
                self.csrf_token = urllib.parse.unquote(cookie.value)
                return self.csrf_token
        raise RuntimeError("Failed to retrieve XSRF-TOKEN cookie.")

    def request(self, method: str, path: str, data=None, headers=None):
        url = f"{self.base_url}{path}"
        req_headers = {
            "Accept": "application/json",
            "Content-Type": "application/json",
            "Origin": self.base_url,
            "Referer": f"{self.base_url}/",
        }
        for cookie in self.cookie_jar:
            if cookie.name == "XSRF-TOKEN":
                self.csrf_token = urllib.parse.unquote(cookie.value)
        if self.csrf_token:
            req_headers["X-XSRF-TOKEN"] = self.csrf_token
        if headers:
            req_headers.update(headers)

        body_bytes = None
        if data is not None:
            body_bytes = json.dumps(data).encode("utf-8")

        req = urllib.request.Request(
            url, data=body_bytes, headers=req_headers, method=method
        )
        try:
            with self.opener.open(req) as resp:
                status_code = resp.status
                resp_body = resp.read().decode("utf-8")
                return (
                    status_code,
                    json.loads(resp_body) if resp_body else {},
                    resp.headers,
                )
        except urllib.error.HTTPError as e:
            status_code = e.code
            resp_body = e.read().decode("utf-8")
            try:
                parsed = json.loads(resp_body)
            except Exception:
                parsed = {"raw": resp_body}
            return status_code, parsed, e.headers


def main():
    print("=== LIVE HTTP AUDIT BE-003.2 START ===")
    client = LiveAuditClient(BASE_URL)

    # 1. Sanctum CSRF Cookie
    print("\n[Step 1] Fetching CSRF cookie...")
    csrf = client.get_csrf_token()
    print(f"  [OK] CSRF Token obtained: {csrf[:16]}...")

    # 2. Login as School 1 Teacher
    print("\n[Step 2] Authenticating as Teacher A (teacher@school130.ru)...")
    status, body, _ = client.request(
        "POST",
        "/api/v1/auth/login",
        {
            "email": "teacher@school130.ru",
            "password": TEACHER_PASSWORD,
        },
    )
    assert status == 200, f"Login failed: status {status}, body {body}"
    print(f"  [OK] Login successful: User {body.get('data', {}).get('name')}")

    # 3. Verify Active Tenant Context
    print("\n[Step 3] Checking Tenant Context...")
    status, body, _ = client.request("GET", "/api/v1/tenant/current")
    assert status == 200, f"Tenant check failed: {status}, {body}"
    school_1_id = body.get("data", {}).get("school_id")
    school_1_name = body.get("data", {}).get("school_name")
    print(f"  [OK] Active school: {school_1_name} ({school_1_id})")

    # 4. Tenant Injection Attack Detection
    print("\n[Step 4] Testing tenant injection protection (school_id in body)...")
    status, body, _ = client.request(
        "POST",
        "/api/v1/scenario-requests",
        {
            "school_id": "00000000-0000-0000-0000-000000000000",
            "custom_event_title": "Атака подменой школы",
            "scale": "class_level",
            "format_type": "class_hour",
            "target_grades": [5],
            "participants_count": 25,
            "duration_minutes": 45,
            "value_direction": "family",
            "family_involvement": False,
        },
    )
    assert status == 422, f"Expected 422, got {status}: {body}"
    assert body.get("error", {}).get("code") == "TENANT_INJECTION_FORBIDDEN", (
        f"Unexpected error code: {body}"
    )
    print("  [OK] Tenant injection strictly blocked with 422 TENANT_INJECTION_FORBIDDEN")

    # 5. Create Valid Scenario Request (participants = 500 boundary test)
    print("\n[Step 5] Creating valid scenario request with participants=500 boundary...")
    req_payload = {
        "custom_event_title": "Общешкольный день исследователей Урала",
        "scale": "all_school",
        "format_type": "festival",
        "target_grades": [1, 2, 5, 11],
        "participants_count": 500,
        "duration_minutes": 120,
        "value_direction": "labor_and_creation",
        "family_involvement": True,
        "available_resources": ["Актовый зал", "Мультимедиа"],
        "pedagogical_constraints": "Обеспечить разделение на возрастные потоки",
    }
    status, body, _ = client.request(
        "POST", "/api/v1/scenario-requests", req_payload
    )
    assert status == 201, f"Create scenario request failed: {status}, {body}"
    req_id = body.get("data", {}).get("id")
    snapshot = body.get("data", {}).get("context_snapshot")
    assert req_id is not None
    assert snapshot is not None
    print(f"  [OK] Scenario Request created: {req_id}")
    print(
        f"  [OK] Context snapshot verified: occasion '{snapshot.get('occasion', {}).get('title')}'"
    )

    # 6. Verify Idempotency-Key validation on POST generate
    print("\n[Step 6] Testing Idempotency-Key validation (missing & invalid key)...")
    # 6a. Missing key -> 422
    status, body, _ = client.request(
        "POST",
        f"/api/v1/scenario-requests/{req_id}/generate",
        data={},
    )
    assert status == 422, f"Expected 422 for missing key, got {status}: {body}"
    assert body.get("error", {}).get("code") == "IDEMPOTENCY_KEY_REQUIRED"
    print("  [OK] Missing Idempotency-Key strictly rejected with 422 IDEMPOTENCY_KEY_REQUIRED")

    # 6b. Non-UUID key -> 422
    status, body, _ = client.request(
        "POST",
        f"/api/v1/scenario-requests/{req_id}/generate",
        data={},
        headers={"Idempotency-Key": "non-uuid-random-key"},
    )
    assert status == 422, f"Expected 422 for non-UUID key, got {status}: {body}"
    assert body.get("error", {}).get("code") == "IDEMPOTENCY_KEY_INVALID"
    print("  [OK] Non-UUID Idempotency-Key strictly rejected with 422 IDEMPOTENCY_KEY_INVALID")

    # 7. Trigger Asynchronous Generation with valid UUID Idempotency Key
    idempotency_key = str(uuid.uuid4())
    print(
        f"\n[Step 7] Triggering generation run with UUID Idempotency-Key: {idempotency_key}..."
    )
    status, body, _ = client.request(
        "POST",
        f"/api/v1/scenario-requests/{req_id}/generate",
        data={},
        headers={"Idempotency-Key": idempotency_key},
    )
    assert status == 202, f"Generation dispatch failed: {status}, {body}"
    gen_data = body.get("data", {})
    run_id = gen_data.get("generation_run_id") or gen_data.get("run_id")
    run_status = gen_data.get("status")
    poll_url = gen_data.get("poll_url")
    est_wait = gen_data.get("estimated_wait_seconds")
    assert run_id is not None, "Missing generation_run_id in response"
    assert poll_url is not None, "Missing poll_url in response"
    assert est_wait is not None, "Missing estimated_wait_seconds in response"
    print(f"  [OK] Generation accepted (202): run_id={run_id}, status={run_status}, poll_url={poll_url}")

    # 8. Poll Generation Run Status until completed
    print("\n[Step 8] Polling Redis queue execution for run completion...")
    scenario_id = None
    for attempt in range(20):
        time.sleep(1)
        status, body, _ = client.request(
            "GET", f"/api/v1/generation-runs/{run_id}"
        )
        assert status == 200, f"Run check failed: {status}, {body}"
        run_data = body.get("data", {})
        current_status = run_data.get("status")
        print(f"  Attempt {attempt + 1}: status = {current_status}")
        if current_status == "completed":
            scenario_id = run_data.get("scenario_id")
            revision_id = run_data.get("revision_id")
            latency = run_data.get("latency_ms")
            tokens = run_data.get("completion_tokens")
            assert scenario_id is not None, "Missing scenario_id in completed run"
            assert revision_id is not None, "Missing revision_id in completed run"
            print(
                f"  [OK] Run completed successfully! Scenario ID: {scenario_id}, Revision ID: {revision_id}, Latency: {latency}ms, Tokens: {tokens}"
            )
            break
        elif current_status == "failed":
            raise RuntimeError(f"Generation run failed: {run_data}")

    assert scenario_id is not None, "Generation run did not complete in time"

    # 9. Inspect Created Scenario via REST API
    print(f"\n[Step 9] Inspecting generated scenario: {scenario_id}...")
    status, body, _ = client.request("GET", f"/api/v1/scenarios/{scenario_id}")
    assert status == 200, f"Fetch scenario failed: {status}, {body}"
    scenario_data = body.get("data", {})
    rev = scenario_data.get("current_revision", {})
    assert rev.get("version_number") == 1
    stages = rev.get("stages", [])
    materials = rev.get("materials", [])
    assert scenario_data.get("moderation_status") == "pending_verification"
    assert rev.get("moderation_status") == "pending_verification"
    assert rev.get("content_payload") is not None
    print(f"  [OK] Scenario Title: {scenario_data.get('title')}")
    print(f"  [OK] Revision Version: {rev.get('version_number')}")
    print(f"  [OK] Relational Stages count: {len(stages)}")
    print(f"  [OK] Relational Materials count: {len(materials)}")
    print(f"  [OK] Moderation state: {scenario_data.get('moderation_status')}")

    # Verify stage_identifier and material content
    for st in stages:
        assert "stage_identifier" in st, f"Missing stage_identifier in stage {st}"
        assert st["stage_identifier"].startswith("stage_")
    for mat in materials:
        assert "content" in mat, f"Missing content in material {mat}"
        assert mat["content"] is not None

    # Check participants_count_max did not exceed 500 in content_payload
    target_aud = rev["content_payload"]["target_audience"]
    assert target_aud["participants_count_max"] <= 500, f"Exceeded max participants: {target_aud['participants_count_max']}"
    assert target_aud["age_group"] == "mixed", f"Expected mixed age_group for grades 1-11, got {target_aud['age_group']}"
    print(f"  [OK] Verified age_group='{target_aud['age_group']}' and participants_count_max={target_aud['participants_count_max']} <= 500")

    # 10. Verify Idempotency Replay with same key
    print("\n[Step 10] Testing Idempotency Replay with same key...")
    status, body, _ = client.request(
        "POST",
        f"/api/v1/scenario-requests/{req_id}/generate",
        data={},
        headers={"Idempotency-Key": idempotency_key},
    )
    assert status == 202, f"Expected 202, got {status}: {body}"
    replayed_run_id = body.get("data", {}).get("generation_run_id") or body.get("data", {}).get("run_id")
    assert replayed_run_id == run_id, f"Expected replayed run_id {run_id}, got {replayed_run_id}"
    print(f"  [OK] Idempotency response accurately replayed without duplicating job (run_id: {replayed_run_id})")

    # 11. Verify Rejection of Repeated Generation with a NEW key after Scenario v1 is generated
    print("\n[Step 11] Testing rejection of repeated generation with a NEW key after completion...")
    new_key = str(uuid.uuid4())
    status, body, _ = client.request(
        "POST",
        f"/api/v1/scenario-requests/{req_id}/generate",
        data={},
        headers={"Idempotency-Key": new_key},
    )
    assert status == 409, f"Expected 409 for repeated generation with new key, got {status}: {body}"
    assert body.get("error", {}).get("code") == "SCENARIO_ALREADY_GENERATED"
    print("  [OK] Repeated generation with new key strictly rejected with 409 SCENARIO_ALREADY_GENERATED")

    # 12. Cross-Tenant Isolation Test with multi-school teacher
    print("\n[Step 12] Testing Cross-Tenant Isolation with School 2...")
    client2 = LiveAuditClient(BASE_URL)
    client2.get_csrf_token()

    status, body, _ = client2.request(
        "POST",
        "/api/v1/auth/login",
        {
            "email": "multi@schools.ru",
            "password": MULTI_PASSWORD,
        },
    )
    assert status == 200, f"Login multi failed: {status}"

    # Get multi user's schools from profile
    status, body, _ = client2.request("GET", "/api/v1/auth/me")
    assert status == 200, f"Get /api/v1/auth/me failed: {status}, {body}"
    schools = body.get("data", {}).get("schools", [])
    school_2_id = None
    for s in schools:
        if s.get("school_id") != school_1_id:
            school_2_id = s.get("school_id")
            break

    if not school_2_id:
        raise RuntimeError(
            f"Multi-school user must possess a valid secondary membership distinct from {school_1_id}."
        )

    print(f"  Attempting cross-tenant access to School 1 scenario from School 2 ({school_2_id})...")
    status, body, _ = client2.request(
        "GET",
        f"/api/v1/scenarios/{scenario_id}",
        headers={"X-School-Id": school_2_id},
    )
    assert status in (403, 404), f"Expected 403/404 for foreign tenant scenario access, got {status}: {body}"
    print(f"  [OK] Cross-tenant access strictly prevented with status {status}")

    print("\n=== LIVE HTTP AUDIT BE-003.2 PASSED 100% SUCCESSFULLY ===")


if __name__ == "__main__":
    main()
