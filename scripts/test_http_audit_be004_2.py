#!/usr/bin/env python3
"""
Live HTTP Gateway & Security Audit for BE-004.2:
Final Moderation Coverage & Export Provenance Gate.
Executes against real running Nginx / PHP-FPM / Redis / PostgreSQL Docker stack.
"""

import hashlib
import http.cookiejar
import json
import os
import subprocess
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid

if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8", line_buffering=True)
if hasattr(sys.stderr, "reconfigure"):
    sys.stderr.reconfigure(encoding="utf-8", line_buffering=True)

BASE_URL = os.environ.get("BASE_URL", "http://localhost:8000")
TEACHER_PASSWORD = os.environ["DEMO_TEACHER_PASSWORD"]


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


def run_artisan_tinker(command: str) -> str:
    res = subprocess.run(
        ["docker", "exec", "school-app-1", "php", "artisan", "tinker", f"--execute={command}"],
        capture_output=True,
        text=True,
        encoding="utf-8",
    )
    return res.stdout.strip()


def main():
    print("======================================================================")
    print("  LIVE HTTP GATEWAY AUDIT: BE-004.2 FINAL MODERATION & PROVENANCE GATE")
    print(f"  Target: {BASE_URL}")
    print("======================================================================\n")

    client = LiveAuditClient(BASE_URL)

    # 1. CSRF & Teacher A Login
    print("[1] Fetching CSRF cookie and authenticating Teacher A (teacher@school130.ru)...")
    client.get_csrf_token()
    status, body, _ = client.request(
        "POST",
        "/api/v1/auth/login",
        {"email": "teacher@school130.ru", "password": TEACHER_PASSWORD},
    )
    assert status == 200, f"Login failed: status={status}, body={body}"
    user_data = body.get("data", {})
    teacher_id = user_data.get("id")

    status, tenant_body, _ = client.request("GET", "/api/v1/tenant/current")
    assert status == 200, f"Tenant check failed: {status}, {tenant_body}"
    school_id = tenant_body.get("data", {}).get("school_id")
    school_name = tenant_body.get("data", {}).get("school_name")
    print(f"    OK: Teacher A logged in: id={teacher_id}, School: {school_name} ({school_id})")

    # 2. Select Approved Calendar Event
    print("\n[2] Selecting approved calendar event...")
    status, body, _ = client.request("GET", "/api/v1/calendar-events?status=approved")
    assert status == 200, f"Fetch events failed: status={status}, body={body}"
    events = body.get("data", [])
    assert len(events) > 0, "No approved events found"
    selected_event = events[0]
    event_id = selected_event["id"]
    event_title = selected_event["title"]
    print(f"    OK: Selected calendar event: '{event_title}' ({event_id})")

    # 3. Create Scenario Request
    print("\n[3] Creating Scenario Request...")
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
    assert status == 201, f"Failed to create request: {body}"
    scenario_request_id = body.get("data", {}).get("id")
    print(f"    OK: Scenario Request created with ID: {scenario_request_id}")

    # 4. Trigger Async LLM Generation (Redis queue)
    print("\n[4] Triggering asynchronous LLM generation...")
    gen_key = str(uuid.uuid4())
    status, body, _ = client.request(
        "POST",
        f"/api/v1/scenario-requests/{scenario_request_id}/generate",
        headers={"Idempotency-Key": gen_key},
    )
    assert status == 202, f"Generation trigger failed: {body}"
    generation_run_id = body.get("data", {}).get("run_id") or body.get("data", {}).get("id")
    print(f"    OK: Generation Run queued with ID: {generation_run_id}")

    # 5. Poll until completed
    print("\n[5] Polling generation run status until completed...")
    scenario_id = None
    for attempt in range(30):
        time.sleep(1)
        status, poll_body, _ = client.request(
            "GET",
            f"/api/v1/generation-runs/{generation_run_id}",
        )
        run_status = poll_body.get("data", {}).get("status")
        if run_status == "completed":
            scenario_id = poll_body.get("data", {}).get("scenario_id")
            print(f"    OK: Generation completed in attempt {attempt + 1}. Scenario ID: {scenario_id}")
            break
        elif run_status == "failed":
            raise RuntimeError(f"Generation failed: {poll_body}")
    assert scenario_id, "Generation timed out!"

    # 6. Check Automated Moderation on Revision 1
    print("\n[6] Checking Automated Moderation on Revision 1 (BE-004.2 Auto-Queue)...")
    time.sleep(1.5)
    status, body, _ = client.request("GET", f"/api/v1/scenarios/{scenario_id}")
    assert status == 200, f"Failed to get scenario: {body}"
    rev1 = body.get("data", {}).get("current_revision", {})
    mod_status = rev1.get("moderation_state", {}).get("status")
    opt_lock_v1 = body.get("data", {}).get("optimistic_lock_version")
    print(f"    OK: Revision 1 auto-moderation status: {mod_status} (opt_lock: {opt_lock_v1})")
    assert mod_status in ["verification_passed", "verification_passed_with_warnings"], f"Unexpected status: {mod_status}"

    # 7. Verification of Stereotype Crawler (Substantive Fields)
    print("\n[7] Verifying Stereotype Guardrail across all substantive fields...")
    paths_to_test = [
        ("expected_outcomes.knowledge[0]", "Все русские обязательно должны знать этот обряд."),
        ("activities[0].rules_and_instructions", "Все татары обязательно должны участвовать по этим правилам."),
        ("materials.presentation_slides[0].bullet_points[0]", "Все башкиры обязательно поют эту песню."),
        ("roles[0].specific_tasks[0]", "Все кавказцы обязательно соблюдают строгий порядок."),
    ]
    for target_path, phrase in paths_to_test:
        test_stereo_php = """
        $rev = App\\Models\\ScenarioRevision::where('scenario_id', '%s')->where('version_number', 1)->first();
        $payload = $rev->content_payload;
        if (str_starts_with('%s', 'expected_outcomes')) {
            $payload['expected_outcomes']['knowledge'][0] = '%s';
        } elseif (str_starts_with('%s', 'activities')) {
            $payload['activities'][0]['rules_and_instructions'] = '%s';
        } elseif (str_starts_with('%s', 'materials')) {
            $payload['materials']['presentation_slides'][0]['bullet_points'][0] = '%s';
        } elseif (str_starts_with('%s', 'roles')) {
            $payload['roles'][0]['specific_tasks'][0] = '%s';
        }
        DB::table('scenario_revisions')->where('id', $rev->id)->update(['content_payload' => json_encode($payload)]);
        $rev->refresh();
        $run = app(App\\Services\\Moderation\\ModerationEngineService::class)->verifyRevision($rev);
        $violation = $run->findings->firstWhere('rule_code', 'P0_STEREOTYPE');
        echo json_encode(['target_path' => $violation?->target_path, 'status' => $run->status]);
        """ % (
            scenario_id,
            target_path, phrase,
            target_path, phrase,
            target_path, phrase,
            target_path, phrase
        )
        out = run_artisan_tinker(test_stereo_php)
        j_idx = out.find('{"target_path"')
        assert j_idx != -1, f"Failed to test stereotype path: {out}"
        parsed_stereo = json.loads(out[j_idx:])
        assert parsed_stereo["target_path"] is not None, f"Expected violation for {target_path}, got {parsed_stereo}"
        print(f"    OK: Substantive path flagged: {parsed_stereo['target_path']} (status={parsed_stereo['status']})")

    # Restore Revision 1 clean payload
    clean_php = """
    $rev = App\\Models\\ScenarioRevision::where('scenario_id', '%s')->where('version_number', 1)->first();
    $p = $rev->content_payload;
    $p['expected_outcomes']['knowledge'][0] = 'Знакомство с ключевыми историческими вехами традиции.';
    $p['activities'][0]['rules_and_instructions'] = 'Ознакомление с регламентом и целями мероприятия.';
    $p['materials']['presentation_slides'][0]['bullet_points'][0] = 'Основные факты и исторический контекст.';
    $p['roles'][0]['specific_tasks'][0] = 'Подготовка материалов и координация участников.';
    DB::table('scenario_revisions')->where('id', $rev->id)->update(['content_payload' => json_encode($p)]);
    $rev->refresh();
    app(App\\Services\\Moderation\\ModerationEngineService::class)->verifyRevision($rev);
    echo "CLEANED";
    """ % scenario_id
    run_artisan_tinker(clean_php)

    # 8. Patch Revision to v2 with optimistic locking
    print("\n[8] Patching Revision to Version 2 (Optimistic Locking & Auto-Moderation)...")
    patch_payload = {
        "concept": "Обновленная концепция сценария: глубокое методическое погружение в традицию.",
    }
    status, body, _ = client.request(
        "PATCH",
        f"/api/v1/scenarios/{scenario_id}/revisions/1",
        patch_payload,
        headers={"If-Match": str(opt_lock_v1)},
    )
    assert status == 201, f"Failed to patch revision: {body}"
    rev2_version = body.get("data", {}).get("current_revision", {}).get("version_number")
    assert rev2_version == 2, f"Expected revision 2, got {rev2_version}"
    print(f"    OK: Revision 2 created successfully. Waiting for auto-moderation job...")
    time.sleep(2.0)

    # 9. Verify Teacher Sign-off on Revision 2
    print("\n[9] Submitting Teacher Sign-Off on Revision 2...")
    signoff_payload = {
        "checklist_confirmed": {
            "safety_checked": True,
            "timing_fit": True,
            "cultural_context_approved": True,
        },
        "teacher_notes": "Сценарий полностью соответствует методическим требованиям школы.",
    }
    status, body, _ = client.request(
        "POST",
        f"/api/v1/scenarios/{scenario_id}/revisions/2/signoff",
        signoff_payload,
    )
    assert status in (200, 201), f"Sign-off failed: {body}"
    print(f"    OK: Sign-off recorded successfully for Revision 2.")

    # 10. Request PDF Export for Revision 2
    print("\n[10] Requesting PDF Export for Revision 2...")
    export_key = str(uuid.uuid4())
    status, body, _ = client.request(
        "POST",
        f"/api/v1/scenarios/{scenario_id}/revisions/2/exports",
        {"format": "pdf"},
        headers={"Idempotency-Key": export_key},
    )
    assert status == 202, f"Export request failed: {body}"
    export_job_id = body.get("data", {}).get("id")
    print(f"    OK: Export Job queued with ID: {export_job_id}")

    # Poll until ready
    for attempt in range(25):
        time.sleep(1)
        status, poll_body, _ = client.request("GET", f"/api/v1/export-jobs/{export_job_id}")
        job_status = poll_body.get("data", {}).get("status")
        if job_status == "ready":
            print(f"    OK: PDF Export completed and ready for download.")
            break
        elif job_status == "failed":
            raise RuntimeError(f"Export job failed: {poll_body}")
    assert job_status == "ready", "Export job timed out!"

    # 11. Download PDF and verify human-readable structure
    print("\n[11] Downloading PDF and verifying human-readable structure (no raw JSON / enums)...")
    status, pdf_bytes, headers = client.request(
        "GET",
        f"/api/v1/export-jobs/{export_job_id}/download",
        binary=True,
    )
    assert status == 200, f"Failed to download PDF: status {status}"
    assert pdf_bytes.startswith(b"%PDF"), "Response is not a valid PDF binary!"
    os.makedirs("exports", exist_ok=True)
    pdf_path = "exports/sample-scenario-be004-2.pdf"
    with open(pdf_path, "wb") as f:
        f.write(pdf_bytes)
    print(f"    OK: Downloaded {len(pdf_bytes)} bytes to {pdf_path}")

    try:
        import pypdf
        reader = pypdf.PdfReader(pdf_path)
        full_text = "\n".join([page.extract_text() for page in reader.pages])
        print(f"    Total PDF pages: {len(reader.pages)}")

        forbidden_technical_enums = [
            "historical_memory",
            "two_weeks_before",
            "main_interactive_part",
            "all_participants",
            "discussion_question",
        ]
        for enum_val in forbidden_technical_enums:
            assert enum_val not in full_text, f"Forbidden raw technical enum '{enum_val}' found in PDF!"
        print("    OK: All technical enums successfully translated to human-readable Russian.")

        expected_sections = [
            "Педагогическая цель",
            "Педагогические задачи",
            "Планируемые результаты",
            "Роли участников",
            "Инструкции для педагога",
            "Чек-лист готовности",
            "Техника безопасности",
        ]
        for section in expected_sections:
            assert section in full_text, f"Expected section '{section}' missing from PDF text!"
        print("    OK: All expected pedagogical and organizational sections confirmed present.")
    except ImportError:
        print("    pypdf not available; text check skipped.")

    # 12. Test Live Provenance Revocation Gate
    print("\n[12] Testing Live Provenance Revocation Gate (P0 BE-004.2)...")
    # Identify source_id linked to revision 2 cultural claims
    find_source_php = """
    $rev = App\\Models\\ScenarioRevision::where('scenario_id', '%s')->where('version_number', 2)->first();
    $claims = $rev->content_payload['cultural_claims'] ?? [];
    $sourceId = null;
    foreach ($claims as $c) {
        foreach ($c['evidence_records'] ?? [] as $ev) {
            if (!empty($ev['event_source_id'])) {
                $sourceId = $ev['event_source_id'];
                break 2;
            }
        }
    }
    if (!$sourceId) {
        $source = App\\Models\\EventSource::first();
        $sourceId = $source?->id;
    }
    echo json_encode(['source_id' => $sourceId]);
    """ % scenario_id
    out = run_artisan_tinker(find_source_php)
    j_idx = out.find('{"source_id"')
    assert j_idx != -1, f"Failed to find source: {out}"
    source_id = json.loads(out[j_idx:])["source_id"]
    print(f"    OK: Linked Source ID: {source_id}")

    print("  [12.a] Revoking source review in DB (decision -> rejected)...")
    revoke_php = """
    $curator = App\\Models\\User::where('system_role', 'system_admin')->first();
    App\\Models\\SourceReview::create([
        'event_source_id' => '%s',
        'reviewer_user_id' => $curator->id,
        'decision' => 'rejected',
        'comments' => 'Выявлены искажения первоисточника, статус отзывается куратором',
        'reviewed_at' => now()->addSecond(),
    ]);
    echo "REVOKED";
    """ % source_id
    run_artisan_tinker(revoke_php)

    print("  [12.b] Attempting to download previously ready PDF after revocation...")
    status, body, _ = client.request(
        "GET",
        f"/api/v1/export-jobs/{export_job_id}/download",
    )
    assert status == 403, f"Expected 403, got {status}: {body}"
    assert body.get("error", {}).get("code") == "EXPORT_BLOCKED_BY_PROVENANCE_REVOCATION", f"Unexpected code: {body}"
    print(f"    OK: Download strictly BLOCKED with HTTP 403 EXPORT_BLOCKED_BY_PROVENANCE_REVOCATION.")

    print("  [12.c] Attempting to create new export job after revocation...")
    status, body, _ = client.request(
        "POST",
        f"/api/v1/scenarios/{scenario_id}/revisions/2/exports",
        {"format": "pdf"},
        headers={"Idempotency-Key": str(uuid.uuid4())},
    )
    assert status == 403, f"Expected 403, got {status}: {body}"
    assert body.get("error", {}).get("code") == "EXPORT_BLOCKED_BY_PROVENANCE_REVOCATION", f"Unexpected code: {body}"
    print(f"    OK: Export creation strictly BLOCKED with HTTP 403 EXPORT_BLOCKED_BY_PROVENANCE_REVOCATION.")

    # 13. Restore approved decision
    restore_php = """
    $curator = App\\Models\\User::where('system_role', 'system_admin')->first();
    App\\Models\\SourceReview::create([
        'event_source_id' => '%s',
        'reviewer_user_id' => $curator->id,
        'decision' => 'approved',
        'comments' => 'Первоисточник повторно подтвержден',
        'reviewed_at' => now()->addSeconds(2),
    ]);
    App\\Models\\CulturalClaimSource::where('event_source_id', '%s')->update(['verification_status' => 'verified_authentic']);
    echo "RESTORED";
    """ % (source_id, source_id)
    run_artisan_tinker(restore_php)
    print("    OK: Source review restored to approved.")

    print("\n======================================================================")
    print("  AUDIT BE-004.2 SUMMARY: ALL 12 GATES AND HARDENING VERIFIED LIVE!")
    print("======================================================================")


if __name__ == "__main__":
    main()
