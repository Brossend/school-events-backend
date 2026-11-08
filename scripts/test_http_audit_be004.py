#!/usr/bin/env python3
"""
Live HTTP Gateway & Security Audit for BE-004:
Moderation Engine, Revision Editor, Human Sign-Off & Real PDF Export.
Executes against real running Nginx / PHP-FPM / Redis / PostgreSQL Docker stack.
"""

import hashlib
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
TEACHER_PASSWORD = os.environ["DEMO_TEACHER_PASSWORD"]
MULTI_PASSWORD = os.environ["DEMO_MULTI_PASSWORD"]


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

    def request(self, method: str, path: str, data=None, headers=None, binary=False):
        url = f"{self.base_url}{path}"
        req_headers = {
            "Accept": "application/json" if not binary else "*/*",
            "Origin": self.base_url,
            "Referer": f"{self.base_url}/",
        }
        if data is not None:
            req_headers["Content-Type"] = "application/json"

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
                resp_bytes = resp.read()
                if binary:
                    return status_code, resp_bytes, resp.headers
                resp_text = resp_bytes.decode("utf-8")
                return (
                    status_code,
                    json.loads(resp_text) if resp_text else {},
                    resp.headers,
                )
        except urllib.error.HTTPError as e:
            status_code = e.code
            resp_bytes = e.read()
            if binary:
                return status_code, resp_bytes, e.headers
            resp_text = resp_bytes.decode("utf-8")
            try:
                parsed = json.loads(resp_text)
            except Exception:
                parsed = {"raw": resp_text}
            return status_code, parsed, e.headers


def main():
    print("======================================================================")
    print("  LIVE HTTP AUDIT BE-004: Moderation, Revisions, Sign-off & PDF Export")
    print("======================================================================")
    client = LiveAuditClient(BASE_URL)

    # 1. CSRF & Teacher A Login
    print("\n[Step 1] Fetching CSRF cookie and authenticating Teacher A (Лицей № 130)...")
    client.get_csrf_token()
    status, body, _ = client.request(
        "POST",
        "/api/v1/auth/login",
        {"email": "teacher@school130.ru", "password": TEACHER_PASSWORD},
    )
    assert status == 200, f"Login failed: status={status}, body={body}"
    user_data = body.get("data", {})
    teacher_a_id = user_data.get("id")
    print(f"  OK: Teacher A logged in: id={teacher_a_id}, name={user_data.get('name')}")

    # Verify Active Tenant Context
    status, body, _ = client.request("GET", "/api/v1/tenant/current")
    assert status == 200, f"Tenant check failed: {status}, {body}"
    school_a_id = body.get("data", {}).get("school_id")
    print(f"  OK: Active school context: {body.get('data', {}).get('school_name')} ({school_a_id})")

    # 2. Select Approved Calendar Event
    print("\n[Step 2] Fetching approved calendar events for school...")
    status, body, _ = client.request("GET", "/api/v1/calendar-events?status=approved")
    assert status == 200, f"Fetch events failed: status={status}, body={body}"
    events = body.get("data", [])
    assert len(events) > 0, "No approved events found"
    selected_event = events[0]
    event_id = selected_event["id"]
    event_title = selected_event["title"]
    print(f"  OK: Selected calendar event: '{event_title}' ({event_id})")

    # 3. Create Scenario Request
    print("\n[Step 3] Submitting new scenario request...")
    req_payload = {
        "calendar_event_id": event_id,
        "scale": "class_level",
        "format_type": "class_hour",
        "target_grades": [7, 8],
        "participants_count": 30,
        "duration_minutes": 45,
        "value_direction": "historical_memory",
        "family_involvement": False,
        "available_resources": ["Проектор", "Интерактивная доска", "Раздаточный материал"],
        "pedagogical_constraints": "Фокус на культурной преемственности и исторической памяти",
    }
    status, body, _ = client.request("POST", "/api/v1/scenario-requests", req_payload)
    assert status == 201, f"Create scenario request failed: status={status}, body={body}"
    scenario_request_id = body["data"]["id"]
    print(f"  OK: Scenario request created: id={scenario_request_id}")

    # 4. Trigger Generation via Mock LLM
    print("\n[Step 4] Triggering asynchronous LLM generation...")
    gen_key = str(uuid.uuid4())
    status, body, _ = client.request(
        "POST",
        f"/api/v1/scenario-requests/{scenario_request_id}/generate",
        headers={"Idempotency-Key": gen_key},
    )
    assert status == 202, f"Trigger generation failed: status={status}, body={body}"
    run_id = body["data"]["run_id"]
    print(f"  OK: Generation queued: run_id={run_id}, status={body['data']['status']}")

    # 5. Poll Generation Run Until Completed
    print("\n[Step 5] Polling generation run status until completed...")
    scenario_id = None
    for attempt in range(30):
        status, body, _ = client.request("GET", f"/api/v1/generation-runs/{run_id}")
        assert status == 200, f"Poll run failed: status={status}, body={body}"
        run_status = body["data"]["status"]
        if run_status == "completed":
            scenario_id = body["data"]["scenario_id"]
            print(f"  OK: Generation completed in attempt {attempt + 1}: scenario_id={scenario_id}")
            break
        elif run_status == "failed":
            raise RuntimeError(f"Generation run failed: {body}")
        time.sleep(1.0)
    assert scenario_id is not None, "Timed out waiting for generation completion"

    # 6. Inspect Scenario v1
    print("\n[Step 6] Inspecting generated scenario Revision 1...")
    status, body, _ = client.request("GET", f"/api/v1/scenarios/{scenario_id}")
    assert status == 200, f"Get scenario failed: status={status}, body={body}"
    sc_data = body["data"]
    rev1 = sc_data["current_revision"]
    assert rev1["version_number"] == 1, f"Expected version 1, got {rev1['version_number']}"
    assert rev1["moderation_state"]["status"] == "pending_verification"
    assert len(rev1["stages"]) >= 3, f"Expected >= 3 stages, got {len(rev1['stages'])}"
    assert len(rev1["materials"]) >= 3, f"Expected >= 3 materials, got {len(rev1['materials'])}"
    opt_lock_v1 = sc_data["optimistic_lock_version"]
    print(f"  OK: Revision 1 loaded: stages={len(rev1['stages'])}, materials={len(rev1['materials'])}, status={rev1['moderation_state']['status']}, optimistic_lock_version={opt_lock_v1}")

    # 7. Automated Moderation on Revision 1
    print("\n[Step 7] Triggering automated moderation for Revision 1...")
    status, body, _ = client.request(
        "POST",
        f"/api/v1/scenarios/{scenario_id}/revisions/1/verify",
    )
    assert status in (200, 202), f"Verify revision 1 failed: status={status}, body={body}"
    print(f"  OK: Moderation started/completed: status={body['data']['status']}")

    # 8. Read Verification Findings on Revision 1
    print("\n[Step 8] Reading moderation verification findings for Revision 1...")
    verif_data = None
    for _ in range(20):
        status, body, _ = client.request(
            "GET",
            f"/api/v1/scenarios/{scenario_id}/revisions/1/verifications",
        )
        assert status == 200, f"Get verifications failed: status={status}, body={body}"
        verif_data = body["data"]
        if verif_data["status"] != "running":
            break
        time.sleep(0.5)

    p0_count = verif_data.get("p0_violations_count", 0)
    p1_count = verif_data.get("p1_violations_count", 0)
    mod_status = verif_data["status"]
    print(f"  OK: Moderation findings: status={mod_status}, P0 violations={p0_count}, P1 violations={p1_count}")
    assert p0_count == 0, f"Revision 1 has open P0 violations: {verif_data.get('findings')}"

    # 9. Revision Editor: Create Revision 2 (N+1 Immutable)
    print("\n[Step 9] Editing scenario: creating Revision 2 (N+1) with If-Match...")
    content_payload = rev1.get("content_payload", {})
    existing_stages = content_payload.get("stages", [])
    if existing_stages:
        stages_v2 = [dict(s) for s in existing_stages]
        stages_v2[0]["title"] = "Вводная историческая экспозиция (актуализированная)"
        stages_v2[0]["teacher_actions"] = "Педагог актуализирует исторический контекст и организует проблемный диалог с классом."
    else:
        stages_v2 = None

    patch_payload = {
        "concept": "Усовершенствованный концепт классного часа с усиленным акцентом на историческую память и работу с первоисточниками.",
        "pedagogical_goal": "Сформировать уважительное отношение к культурно-историческому наследию через активное исследование первоисточников.",
    }
    if stages_v2:
        patch_payload["stages"] = stages_v2

    status, body, _ = client.request(
        "PATCH",
        f"/api/v1/scenarios/{scenario_id}/revisions/1",
        data=patch_payload,
        headers={"If-Match": f'"{opt_lock_v1}"'},
    )
    assert status == 201, f"Revision edit failed: status={status}, body={body}"
    scenario_v2 = body["data"]
    rev2_data = scenario_v2["current_revision"]
    assert rev2_data["version_number"] == 2, f"Expected version 2, got {rev2_data['version_number']}"
    assert scenario_v2["optimistic_lock_version"] == opt_lock_v1 + 1
    print(f"  OK: Revision 2 created: version={rev2_data['version_number']}, id={rev2_data['id']}, moderation_status={rev2_data['moderation_status']}, optimistic_lock_version={scenario_v2['optimistic_lock_version']}")

    # 10. Verify Immutability of Revision 1
    print("\n[Step 10] Verifying Revision 1 immutability in database...")
    status, body, _ = client.request("GET", f"/api/v1/scenarios/{scenario_id}/revisions/1")
    assert status == 200, f"Get rev 1 failed: status={status}, body={body}"
    rev1_check = body["data"]
    assert rev1_check["version_number"] == 1
    assert rev1_check["is_current"] is False
    print(f"  OK: Revision 1 remains intact and immutable (is_current=False).")

    # 11. Run Moderation on Revision 2
    print("\n[Step 11] Running automated moderation on Revision 2...")
    status, body, _ = client.request("POST", f"/api/v1/scenarios/{scenario_id}/revisions/2/verify")
    assert status in (200, 202), f"Verify rev 2 failed: status={status}, body={body}"

    rev2_verif = None
    for _ in range(20):
        status, body, _ = client.request("GET", f"/api/v1/scenarios/{scenario_id}/revisions/2/verifications")
        assert status == 200
        rev2_verif = body["data"]
        if rev2_verif["status"] != "running":
            break
        time.sleep(0.5)

    assert rev2_verif.get("p0_violations_count", 0) == 0, f"Revision 2 has open P0 violations: {rev2_verif}"
    print(f"  OK: Revision 2 passed moderation without open P0 violations: status={rev2_verif['status']}")

    # 12. Human Teacher Sign-Off
    print("\n[Step 12] Performing Human Teacher Sign-Off on Revision 2...")
    signoff_payload = {
        "checklist_confirmed": {
            "safety_checked": True,
            "timing_fit": True,
            "cultural_context_approved": True,
        },
        "teacher_notes": "Сценарий проверен, тайминг сбалансирован, готов к проведению в 7-8 классах.",
    }
    status, body, _ = client.request(
        "POST",
        f"/api/v1/scenarios/{scenario_id}/revisions/2/signoff",
        signoff_payload,
    )
    assert status in (200, 201), f"Teacher sign-off failed: status={status}, body={body}"
    signoff_data = body["data"]
    assert signoff_data["is_confirmed"] is True
    assert signoff_data["confirmed_at"] is not None
    signoff_id = signoff_data.get("signoff_id") or signoff_data.get("id")
    print(f"  OK: Teacher Sign-Off recorded: id={signoff_id}, confirmed_at={signoff_data['confirmed_at']}")

    # 13. Request PDF Export
    print("\n[Step 13] Requesting PDF Export compilation...")
    export_key = str(uuid.uuid4())
    status, body, _ = client.request(
        "POST",
        f"/api/v1/scenarios/{scenario_id}/revisions/2/exports",
        {"format": "pdf"},
        headers={"Idempotency-Key": export_key},
    )
    assert status == 202, f"Export request failed: status={status}, body={body}"
    export_job_id = body["data"]["id"]
    print(f"  OK: Export job created: job_id={export_job_id}, status={body['data']['status']}")

    # 14. Poll Export Job Until Ready
    print("\n[Step 14] Polling export job status until ready...")
    job_data = None
    for attempt in range(30):
        status, body, _ = client.request("GET", f"/api/v1/export-jobs/{export_job_id}")
        assert status == 200, f"Poll export job failed: status={status}, body={body}"
        job_status = body["data"]["status"]
        if job_status == "ready":
            job_data = body["data"]
            print(f"  OK: Export job ready in attempt {attempt + 1}: file_name={job_data['file_name']}, size={job_data['file_size_bytes']} bytes")
            break
        elif job_status == "failed":
            raise RuntimeError(f"Export job failed: {body}")
        time.sleep(1.0)
    assert job_data is not None, "Timed out waiting for export job to become ready"

    # 15. Download Real Binary PDF and Verify Integrity
    print("\n[Step 15] Downloading real compiled binary PDF artifact...")
    status, pdf_bytes, headers = client.request(
        "GET",
        f"/api/v1/export-jobs/{export_job_id}/download",
        binary=True,
    )
    assert status == 200, f"PDF download failed: status={status}, body={pdf_bytes.decode('utf-8', errors='replace')}"
    content_type = headers.get("Content-Type", "")
    assert "application/pdf" in content_type, f"Unexpected Content-Type: {content_type}"

    # Verify real PDF magic bytes
    assert pdf_bytes.startswith(b"%PDF-"), f"Downloaded file is not a valid PDF! Starts with: {pdf_bytes[:16]}"

    # Verify SHA-256 header and matching physical content
    expected_hash = headers.get("X-Checksum-Sha256")
    actual_hash = hashlib.sha256(pdf_bytes).hexdigest()
    assert expected_hash == actual_hash, f"SHA-256 mismatch: header={expected_hash}, computed={actual_hash}"
    assert len(pdf_bytes) == job_data["file_size_bytes"], f"Size mismatch: job={job_data['file_size_bytes']}, actual={len(pdf_bytes)}"

    print(f"  OK: Valid PDF binary confirmed!")
    print(f"      Size: {len(pdf_bytes)} bytes")
    print(f"      Magic bytes: {pdf_bytes[:8].decode('latin1', errors='replace')}")
    print(f"      SHA-256: {actual_hash}")

    # 16. Negative Security & Business Rule Checks
    print("\n[Step 16] Executing Negative Security & Validation Gates...")

    # 16.a Cross-Tenant PDF Download Isolation
    print("  [16.a] Testing cross-tenant download isolation with Teacher B (Гимназия № 9)...")
    client_b = LiveAuditClient(BASE_URL)
    client_b.get_csrf_token()
    status, body, _ = client_b.request(
        "POST",
        "/api/v1/auth/login",
        {"email": "multi@schools.ru", "password": MULTI_PASSWORD},
    )
    assert status == 200, f"Teacher B login failed: status={status}"

    status, body, _ = client_b.request("GET", "/api/v1/auth/me")
    assert status == 200, f"Get /api/v1/auth/me failed: {status}, {body}"
    schools = body.get("data", {}).get("schools", [])
    school_2_id = None
    for s in schools:
        if s.get("school_id") != school_a_id:
            school_2_id = s.get("school_id")
            break

    headers_b = {"X-School-Id": school_2_id} if school_2_id else {}

    status, body, _ = client_b.request(
        "GET",
        f"/api/v1/export-jobs/{export_job_id}/download",
        headers=headers_b,
    )
    assert status == 403, f"Expected 403, got {status}: {body}"
    err_code = body.get("error", {}).get("code")
    assert err_code in ("FORBIDDEN_TENANT_ACCESS", "FORBIDDEN_AUTHOR_ACCESS"), f"Unexpected error code: {body}"
    print(f"    OK: Cross-tenant download strictly rejected with HTTP 403 {err_code}.")

    # 16.b Historical Export Forbidden
    print("  [16.b] Testing export on historical Revision 1...")
    status, body, _ = client.request(
        "POST",
        f"/api/v1/scenarios/{scenario_id}/revisions/1/exports",
        {"format": "pdf"},
        headers={"Idempotency-Key": str(uuid.uuid4())},
    )
    assert status == 409, f"Expected 409 CANNOT_EXPORT_HISTORICAL_REVISION, got {status}: {body}"
    assert body.get("error", {}).get("code") == "CANNOT_EXPORT_HISTORICAL_REVISION", f"Unexpected error code: {body}"
    print("    OK: Historical revision export strictly rejected with HTTP 409 CANNOT_EXPORT_HISTORICAL_REVISION.")

    # 16.c Sign-Off With Unchecked Checklist Forbidden
    print("  [16.c] Testing sign-off with incomplete checklist booleans...")
    incomplete_signoff = {
        "checklist_confirmed": {
            "safety_checked": True,
            "timing_fit": False,  # Not checked!
            "cultural_context_approved": True,
        },
    }
    status, body, _ = client.request(
        "POST",
        f"/api/v1/scenarios/{scenario_id}/revisions/2/signoff",
        incomplete_signoff,
    )
    assert status == 422, f"Expected 422 SIGNOFF_CHECKLIST_FLAGS_MUST_BE_TRUE, got {status}: {body}"
    assert body.get("error", {}).get("code") in ("SIGNOFF_CHECKLIST_FLAGS_MUST_BE_TRUE", "SIGNOFF_CHECKLIST_INCOMPLETE"), f"Unexpected error code: {body}"
    print(f"    OK: Incomplete checklist strictly rejected with HTTP 422 {body.get('error', {}).get('code')}.")

    # 16.d Idempotency Replay on PDF Export
    print("  [16.d] Testing Idempotency-Key replay on export...")
    status, body, _ = client.request(
        "POST",
        f"/api/v1/scenarios/{scenario_id}/revisions/2/exports",
        {"format": "pdf"},
        headers={"Idempotency-Key": export_key},
    )
    assert status == 202, f"Expected 202 on replay, got {status}: {body}"
    assert body.get("data", {}).get("id") == export_job_id, f"Expected same job {export_job_id}, got {body}"
    print("    OK: Idempotency replay successfully returned identical export job.")

    print("\n======================================================================")
    print("  AUDIT BE-004 SUMMARY: ALL 16 STEPS PASSED SUCCESSFULLY!")
    print("======================================================================")


if __name__ == "__main__":
    main()
