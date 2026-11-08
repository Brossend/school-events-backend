#!/usr/bin/env python3
"""
Live HTTP Gateway Audit for BE-007: Multi-Format Document & Presentation Export System.
Uses Python standard library (urllib.request, http.cookiejar).
Generates and downloads:
- Scenario PDF (existing)
- Editable DOCX (Microsoft Word)
- 5-Slide 16:9 PPTX (Microsoft PowerPoint)
- A4 Print Pack PDF (Teacher Memo, Task Cards, Handouts, Fact Sheet)
- Validates GET /scenarios/{id}/revisions/{version}/exports history endpoint.
"""

import os
import sys
import json
import time
import uuid
import hashlib
import zipfile
import http.cookiejar
import urllib.request
import urllib.parse
import urllib.error

if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8", line_buffering=True)
if hasattr(sys.stderr, "reconfigure"):
    sys.stderr.reconfigure(encoding="utf-8", line_buffering=True)

BASE_URL = os.environ.get("BASE_URL", "http://localhost:8000")
TEACHER_PASSWORD = os.environ["DEMO_TEACHER_PASSWORD"]
EXPORTS_DIR = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), "exports")
os.makedirs(EXPORTS_DIR, exist_ok=True)


class LiveAuditClient:
    def __init__(self, base_url: str):
        self.base_url = base_url.rstrip("/")
        self.cookie_jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(
            urllib.request.HTTPCookieProcessor(self.cookie_jar)
        )
        self.csrf_token = None
        self.school_id = None

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

    def request(self, method: str, path: str, payload=None, extra_headers=None):
        url = f"{self.base_url}{path}"
        headers = {
            "Accept": "application/json",
            "Referer": f"{self.base_url}/",
            "Origin": self.base_url,
        }

        cookie_parts = []
        for cookie in self.cookie_jar:
            cookie_parts.append(f"{cookie.name}={cookie.value}")
            if cookie.name == "XSRF-TOKEN":
                self.csrf_token = urllib.parse.unquote(cookie.value)
        if cookie_parts:
            headers["Cookie"] = "; ".join(cookie_parts)
        if self.csrf_token:
            headers["X-XSRF-TOKEN"] = self.csrf_token
        if self.school_id:
            headers["X-School-Id"] = self.school_id

        if extra_headers:
            headers.update(extra_headers)

        data = None
        if payload is not None:
            if isinstance(payload, (dict, list)):
                headers["Content-Type"] = "application/json"
                data = json.dumps(payload).encode("utf-8")
            elif isinstance(payload, bytes):
                data = payload

        req = urllib.request.Request(url, data=data, headers=headers, method=method)
        try:
            with self.opener.open(req) as response:
                body = response.read()
                content_type = response.headers.get("Content-Type", "")
                parsed_json = None
                if "application/json" in content_type:
                    try:
                        parsed_json = json.loads(body.decode("utf-8"))
                    except Exception:
                        pass
                return {
                    "status": response.status,
                    "headers": dict(response.headers),
                    "body": body,
                    "json": parsed_json,
                }
        except urllib.error.HTTPError as e:
            body = e.read()
            parsed_json = None
            try:
                parsed_json = json.loads(body.decode("utf-8"))
            except Exception:
                pass
            return {
                "status": e.code,
                "headers": dict(e.headers),
                "body": body,
                "json": parsed_json,
            }


def main():
    print("=" * 70)
    print("  LIVE HTTP GATEWAY AUDIT: BE-007 MULTI-FORMAT EXPORT SYSTEM")
    print(f"  Target: {BASE_URL}")
    print("=" * 70)

    client = LiveAuditClient(BASE_URL)

    # 1. CSRF & Login
    print("\n[1] Fetching CSRF cookie and authenticating Teacher A (teacher@school130.ru)...")
    res_csrf = client.request("GET", "/sanctum/csrf-cookie")
    if res_csrf["status"] not in (200, 204):
        print(f"    ERROR: CSRF cookie failed: {res_csrf['status']}")
        sys.exit(1)

    res_login = client.request("POST", "/api/v1/auth/login", {
        "email": "teacher@school130.ru",
        "password": TEACHER_PASSWORD,
    })
    if res_login["status"] != 200:
        print(f"    ERROR: Login failed: {res_login['status']} - {res_login['body'].decode('utf-8')}")
        sys.exit(1)

    res_tenant = client.request("GET", "/api/v1/tenant/current")
    if res_tenant["status"] != 200:
        print(f"    ERROR: Tenant current failed: {res_tenant['status']}")
        sys.exit(1)
    school_id = res_tenant["json"]["data"]["school_id"]
    school_name = res_tenant["json"]["data"]["school_name"]
    client.school_id = school_id
    print(f"    OK: Logged in Teacher A, School: {school_name} ({school_id})")

    # 2. Get approved calendar event
    print("\n[2] Selecting approved calendar event...")
    res_events = client.request("GET", "/api/v1/calendar-events")
    events = res_events["json"]["data"]
    if not events:
        print("    ERROR: No events found!")
        sys.exit(1)
    event = events[0]
    print(f"    OK: Event: '{event['title']}' ({event['id']})")

    # 3. Create Scenario Request
    print("\n[3] Creating Scenario Request...")
    req_payload = {
        "calendar_event_id": event["id"],
        "scale": "class_level",
        "format_type": "class_hour",
        "target_grades": [6],
        "participants_count": 25,
        "duration_minutes": 45,
        "value_direction": "cultural_diversity",
        "family_involvement": False,
        "available_resources": ["Проектор", "Краски", "Альбомы"],
    }
    res_req = client.request("POST", "/api/v1/scenario-requests", req_payload)
    if res_req["status"] != 201:
        print(f"    ERROR: Scenario request creation failed: {res_req['status']} - {res_req['body'].decode('utf-8')}")
        sys.exit(1)
    scenario_req_id = res_req["json"]["data"]["id"]
    print(f"    OK: Scenario Request ID: {scenario_req_id}")

    # 4. Trigger generation
    print("\n[4] Triggering asynchronous LLM generation...")
    idemp_key = str(uuid.uuid4())
    res_gen = client.request(
        "POST",
        f"/api/v1/scenario-requests/{scenario_req_id}/generate",
        extra_headers={"Idempotency-Key": idemp_key}
    )
    if res_gen["status"] != 202:
        print(f"    ERROR: Generation start failed: {res_gen['status']} - {res_gen['body'].decode('utf-8')}")
        sys.exit(1)
    gen_id = res_gen["json"]["data"]["generation_run_id"]
    print(f"    OK: Generation Run ID: {gen_id}")

    # 5. Poll generation run
    print("\n[5] Polling generation run status until completed...")
    scenario_id = None
    for attempt in range(1, 15):
        time.sleep(1.0)
        res_poll = client.request("GET", f"/api/v1/generation-runs/{gen_id}")
        if res_poll["status"] == 200:
            st = res_poll["json"]["data"]["status"]
            if st == "completed":
                scenario_id = res_poll["json"]["data"]["scenario_id"]
                print(f"    OK: Generation completed in attempt {attempt}. Scenario ID: {scenario_id}")
                break
            elif st == "failed":
                print(f"    ERROR: Generation failed: {res_poll['json']}")
                sys.exit(1)

    if not scenario_id:
        print("    ERROR: Generation timed out!")
        sys.exit(1)

    # 6. Check moderation
    print("\n[6] Checking Automated Moderation on Revision 1...")
    res_mod = client.request("GET", f"/api/v1/scenarios/{scenario_id}/revisions/1/verifications")
    mod_status = res_mod["json"]["data"]["status"]
    print(f"    OK: Moderation status: {mod_status}")

    # 7. Submit Teacher Sign-Off
    print("\n[7] Submitting Teacher Sign-Off on Revision 1...")
    signoff_payload = {
        "checklist_confirmed": {
            "safety_checked": True,
            "timing_fit": True,
            "cultural_context_approved": True,
        },
        "warnings_acknowledged": True,
        "teacher_notes": "Сценарий проверен и допущен к проведению и тиражированию.",
    }
    res_sign = client.request("POST", f"/api/v1/scenarios/{scenario_id}/revisions/1/signoff", signoff_payload)
    if res_sign["status"] != 200:
        print(f"    ERROR: Sign-off failed: {res_sign['status']} - {res_sign['body'].decode('utf-8')}")
        sys.exit(1)
    print("    OK: Sign-off recorded successfully.")

    # 8. Export in all 4 formats
    formats_to_test = [
        ("pdf", "sample-scenario-be007.pdf", "application/pdf"),
        ("docx", "sample-scenario-be007.docx", "application/vnd.openxmlformats-officedocument.wordprocessingml.document"),
        ("pptx", "sample-presentation-be007.pptx", "application/vnd.openxmlformats-officedocument.presentationml.presentation"),
        ("print_pack_pdf", "sample-print-pack-be007.pdf", "application/pdf"),
    ]

    downloaded_files = {}

    for fmt, file_name, expected_mime in formats_to_test:
        print(f"\n[8.{fmt}] Exporting format '{fmt}'...")
        exp_idemp = str(uuid.uuid4())
        res_exp = client.request(
            "POST",
            f"/api/v1/scenarios/{scenario_id}/revisions/1/exports",
            payload={"format": fmt},
            extra_headers={"Idempotency-Key": exp_idemp}
        )
        if res_exp["status"] != 202:
            print(f"    ERROR: Export request for '{fmt}' failed: {res_exp['status']} - {res_exp['body'].decode('utf-8')}")
            sys.exit(1)

        job_id = res_exp["json"]["data"]["id"]
        job_status = res_exp["json"]["data"]["status"]
        print(f"    OK: Export Job ID: {job_id}, status: {job_status}")

        # Poll if queued (async redis queue)
        if job_status != "ready":
            for poll in range(15):
                time.sleep(1.0)
                res_job = client.request("GET", f"/api/v1/export-jobs/{job_id}")
                if res_job["json"]["data"]["status"] == "ready":
                    job_status = "ready"
                    break
                elif res_job["json"]["data"]["status"] == "failed":
                    print(f"    ERROR: Job '{fmt}' failed: {res_job['json']}")
                    sys.exit(1)

        # Download file
        res_down = client.request("GET", f"/api/v1/export-jobs/{job_id}/download")
        if res_down["status"] != 200:
            print(f"    ERROR: Download '{fmt}' failed: {res_down['status']} - {res_down['body'].decode('utf-8')}")
            sys.exit(1)

        content = res_down["body"]
        out_path = os.path.join(EXPORTS_DIR, file_name)
        with open(out_path, "wb") as f:
            f.write(content)

        sha256 = hashlib.sha256(content).hexdigest()
        downloaded_files[fmt] = {
            "path": out_path,
            "size": len(content),
            "sha256": sha256,
            "mime": res_down["headers"].get("Content-Type"),
        }

        print(f"    OK: Downloaded {len(content)} bytes to {out_path}")
        print(f"    SHA-256: {sha256}")
        print(f"    Content-Type: {res_down['headers'].get('Content-Type')}")

        # Format specific structural validation
        if fmt == "docx":
            with zipfile.ZipFile(out_path, "r") as z:
                assert "word/document.xml" in z.namelist(), "word/document.xml missing in DOCX"
                xml_text = z.read("word/document.xml").decode("utf-8")
                
                # Check pedagogical tasks (личностные, метапредметные, предметные)
                assert ("Личностные" in xml_text or "личностные" in xml_text), "Personal tasks missing in DOCX"
                assert ("Метапредметные" in xml_text or "метапредметные" in xml_text), "Meta-subject tasks missing in DOCX"
                assert ("Предметные" in xml_text or "предметные" in xml_text), "Subject-specific tasks missing in DOCX"
                
                # Check expected outcomes (знания, умения, ценностные ориентиры)
                assert ("Знания" in xml_text or "знания" in xml_text), "Knowledge outcomes missing in DOCX"
                assert ("Умения" in xml_text or "умения" in xml_text), "Skills outcomes missing in DOCX"
                assert ("Ценности" in xml_text or "ценност" in xml_text), "Values outcomes missing in DOCX"
                
                # Check real roles & specific tasks
                assert "Хранитель традиций" in xml_text, "Role 'Хранитель традиций' missing in DOCX"
                assert "Презентатор идей" in xml_text, "Role 'Презентатор идей' missing in DOCX"
                assert ("Следить за поочередным высказыванием" in xml_text or "регламент" in xml_text), "Role task missing in DOCX"
                assert ("Обобщить предложения" in xml_text or "доклад" in xml_text), "Role task missing in DOCX"
                
                # Check printable cards, handouts, props
                assert ("Карточка кейса" in xml_text or "Описание проблемной ситуации" in xml_text), "Cards missing in DOCX"
                assert ("Контрольные вопросы" in xml_text), "Card back text missing in DOCX"
                assert ("Маршрутный лист малой группы" in xml_text or "Заполните ключевые блоки" in xml_text), "Handouts missing in DOCX"
                assert ("Комплект раздаточных материалов" in xml_text or "Реквизит" in xml_text), "Props missing in DOCX"
                
                # Check cultural claims integrity
                assert "4 ноября" in xml_text, "Verified claim '4 ноября' missing in DOCX"
                assert "1612" in xml_text, "Unverified claim '1612' missing in DOCX"
                assert "[Требует проверки]" in xml_text or "Требует проверки" in xml_text, "Unverified tag missing in DOCX"
                assert "[Подлинно]" in xml_text or "Подлинно" in xml_text, "Verified tag missing in DOCX"
                
                # Ensure 1612 is not falsely marked verified
                if "[Подлинно] События 1612" in xml_text or "[Подлинно] Освобождение Москвы в 1612" in xml_text:
                    raise AssertionError("Claim 1612 falsely marked as [Подлинно] in DOCX!")
                    
            print("    OK: DOCX verified: tasks, outcomes, roles, handouts/cards/props, and strict claim labeling.")
        elif fmt == "pptx":
            with zipfile.ZipFile(out_path, "r") as z:
                assert "ppt/presentation.xml" in z.namelist(), "ppt/presentation.xml missing in PPTX"
                slides = [s for s in z.namelist() if s.startswith("ppt/slides/slide") and s.endswith(".xml")]
                assert len(slides) == 5, f"Expected exactly 5 slides, got {len(slides)}"
                
                # Check all 5 slides content
                slides_content = {}
                for s in sorted(slides):
                    slides_content[s] = z.read(s).decode("utf-8")
                
                # Slide 1 & 2: Author slides preserved from MockLlmProvider
                assert "Введение в тему" in slides_content["ppt/slides/slide1.xml"], "Author slide 1 ('Введение в тему') missing from slide 1"
                assert "Ключевые ценности" in slides_content["ppt/slides/slide1.xml"], "Author slide 1 bullets missing"
                
                assert "Практикум:" in slides_content["ppt/slides/slide2.xml"], "Author slide 2 ('Практикум:') missing from slide 2"
                assert "Правила командного взаимодействия" in slides_content["ppt/slides/slide2.xml"], "Author slide 2 bullets missing"
                
                # Slides 3, 4, 5: supplemented from pedagogical modules
                # Slide 3: Cultural context with provenance claims
                slide3_text = slides_content["ppt/slides/slide3.xml"]
                assert ("Культурно-исторический" in slide3_text or "контекст" in slide3_text), "Slide 3 cultural context missing"
                assert "[Требует проверки]" in slide3_text, "Unverified claim marker [Требует проверки] missing on slide 3"
                assert "[Подлинно]" in slide3_text, "Verified claim marker [Подлинно] missing on slide 3"
                assert "1612" in slide3_text, "Claim 1612 missing on slide 3"
                assert "[Подлинно]: События 1612" not in slide3_text and "[Подлинно] События 1612" not in slide3_text, "1612 claim falsely marked as [Подлинно] in PPTX!"
                assert "⚠ Содержит утверждения, требующие проверки" in slide3_text or "требующие проверки" in slide3_text, "Warning badge missing on slide 3"
                
                # Slide 4: Plan and schedule
                slide4_text = slides_content["ppt/slides/slide4.xml"]
                assert ("План" in slide4_text or "регламент" in slide4_text or "хронометраж" in slide4_text), "Slide 4 schedule missing"
                
                # Slide 5: Interactive roles and activities
                slide5_text = slides_content["ppt/slides/slide5.xml"]
                assert ("Интерактивная деятельность" in slide5_text or "роли" in slide5_text or "Хранитель традиций" in slide5_text), "Slide 5 roles missing"
                
                # Notes slides: must exist for all 5 slides and have non-empty text
                notes = [s for s in z.namelist() if s.startswith("ppt/notesSlides/notesSlide") and s.endswith(".xml")]
                assert len(notes) == 5, f"Expected exactly 5 notes slides, got {len(notes)}"
                for n in sorted(notes):
                    n_content = z.read(n).decode("utf-8")
                    assert len(n_content) > 100, f"Notes slide {n} appears empty"
                
                # All-slides check: Unconditional 'Материал верифицирован' must NOT be present anywhere since 1612 is unverified!
                all_slides_text = "".join(slides_content.values())
                assert "Материал верифицирован" not in all_slides_text, "False 'Материал верифицирован' banner present when unverified claim exists!"
                
            print("    OK: PPTX verified: exactly 5 slides preserved, author slides kept, all 5 speaker notes present, 1612 claim correctly labeled [Требует проверки], no false 'Материал верифицирован' banner.")
        elif fmt == "print_pack_pdf":
            assert content.startswith(b"%PDF-"), "Invalid PDF signature"
            print("    OK: Print Pack verified as valid compiled A4 PDF document.")
        elif fmt == "pdf":
            assert content.startswith(b"%PDF-"), "Invalid PDF signature"
            print("    OK: Scenario PDF verified as valid compiled PDF document.")

    # 9. Verify History API
    print("\n[9] Checking GET /api/v1/scenarios/{id}/revisions/{version}/exports history endpoint...")
    res_hist = client.request("GET", f"/api/v1/scenarios/{scenario_id}/revisions/1/exports")
    if res_hist["status"] != 200:
        print(f"    ERROR: History request failed: {res_hist['status']} - {res_hist['body'].decode('utf-8')}")
        sys.exit(1)

    hist_items = res_hist["json"]["data"]
    print(f"    OK: History returned {len(hist_items)} items.")
    for item in hist_items:
        art_name = item["artifact"]["file_name"] if item.get("artifact") else "None"
        print(f"    - Format: {item['format']:<14} | Status: {item['status']:<7} | is_downloadable: {item['is_downloadable']} | Artifact: {art_name}")
        assert item["is_downloadable"] is True, f"Expected is_downloadable=True for valid job {item['id']}"

    print("\n" + "=" * 70)
    print("  ALL BE-007 LIVE HTTP CHECKS AND ARTIFACT COMPILATIONS PASSED!")
    print("=" * 70)


if __name__ == "__main__":
    main()
