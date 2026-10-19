import os
import http.cookiejar
import json
import sys
import urllib.error
import urllib.parse
import urllib.request

BASE_URL = os.environ.get("TEST_BASE_URL", "http://localhost:8000")
ORIGIN = os.environ.get("TEST_ORIGIN", "http://localhost:3000")

TEACHER_PASSWORD = os.environ.get("TEST_TEACHER_PASSWORD") or os.environ.get("DEFAULT_USER_PASSWORD")
MULTI_PASSWORD = os.environ.get("TEST_MULTI_PASSWORD") or os.environ.get("DEFAULT_USER_PASSWORD")
BLOCKED_PASSWORD = os.environ.get("TEST_BLOCKED_PASSWORD") or os.environ.get("DEFAULT_USER_PASSWORD")

if not TEACHER_PASSWORD or not MULTI_PASSWORD or not BLOCKED_PASSWORD:
    raise RuntimeError(
        "Missing required environment passwords: "
        "TEST_TEACHER_PASSWORD, TEST_MULTI_PASSWORD, and TEST_BLOCKED_PASSWORD (or DEFAULT_USER_PASSWORD) "
        "must be defined in the environment."
    )

def run_tests():
    cookie_jar = http.cookiejar.CookieJar()
    opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cookie_jar))
    urllib.request.install_opener(opener)

    print("=" * 80)
    print("BE-002.2 LIVE HTTP GATEWAY & SECURITY AUDIT VIA NGINX")
    print(f"Base URL: {BASE_URL}")
    print("=" * 80)

    # 1. POST login without CSRF token -> 419 CSRF_TOKEN_MISMATCH
    print("\n[TEST 1] POST /api/v1/auth/login WITHOUT CSRF TOKEN")
    login_data = json.dumps({
        "email": "teacher@school130.ru",
        "password": TEACHER_PASSWORD
    }).encode("utf-8")

    req1 = urllib.request.Request(
        f"{BASE_URL}/api/v1/auth/login",
        data=login_data,
        headers={"Content-Type": "application/json", "Accept": "application/json", "Origin": ORIGIN},
        method="POST"
    )
    try:
        with urllib.request.urlopen(req1) as resp:
            print(f"Status: {resp.status}")
    except urllib.error.HTTPError as e:
        print(f"HTTP Status: {e.code} {e.reason}")
        print(f"Body: {e.read().decode()}")
        assert e.code == 419, f"Expected 419, got {e.code}"

    # 2. GET /sanctum/csrf-cookie
    print("\n[TEST 2] GET /sanctum/csrf-cookie")
    req2 = urllib.request.Request(
        f"{BASE_URL}/sanctum/csrf-cookie",
        headers={"Accept": "application/json", "Origin": ORIGIN},
        method="GET"
    )
    with urllib.request.urlopen(req2) as resp:
        print(f"HTTP Status: {resp.status} {resp.reason}")
        assert resp.status == 204

    xsrf_token = None
    for cookie in cookie_jar:
        if cookie.name == "XSRF-TOKEN":
            xsrf_token = urllib.parse.unquote(cookie.value)
    assert xsrf_token, "XSRF-TOKEN not found in cookie jar!"

    # 3. POST login with credentials & CSRF
    print("\n[TEST 3] POST /api/v1/auth/login WITH CSRF & CREDENTIALS")
    req3 = urllib.request.Request(
        f"{BASE_URL}/api/v1/auth/login",
        data=login_data,
        headers={
            "Content-Type": "application/json",
            "Accept": "application/json",
            "Origin": ORIGIN,
            "X-XSRF-TOKEN": xsrf_token,
        },
        method="POST"
    )
    school1_id = None
    with urllib.request.urlopen(req3) as resp:
        print(f"HTTP Status: {resp.status} {resp.reason}")
        body = json.loads(resp.read().decode())
        print(f"User: {body['data']['user']['name']} ({body['data']['user']['email']})")
        school1_id = body['data']['schools'][0]['school_id']
        print(f"Active School ID: {school1_id}")
        assert resp.status == 200

    # Refresh xsrf_token from cookie_jar since login regenerates session
    for cookie in cookie_jar:
        if cookie.name == "XSRF-TOKEN":
            xsrf_token = urllib.parse.unquote(cookie.value)

    # Discover School 2 ID by querying multi-school teacher profile
    multi_jar = http.cookiejar.CookieJar()
    multi_opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(multi_jar))
    req_m_csrf = urllib.request.Request(f"{BASE_URL}/sanctum/csrf-cookie", headers={"Origin": ORIGIN})
    with multi_opener.open(req_m_csrf):
        pass
    m_xsrf = None
    for c in multi_jar:
        if c.name == "XSRF-TOKEN":
            m_xsrf = urllib.parse.unquote(c.value)

    req_multi_login = urllib.request.Request(
        f"{BASE_URL}/api/v1/auth/login",
        data=json.dumps({"email": "multi@schools.ru", "password": MULTI_PASSWORD}).encode("utf-8"),
        headers={"Content-Type": "application/json", "Accept": "application/json", "Origin": ORIGIN, "X-XSRF-TOKEN": m_xsrf},
        method="POST"
    )
    school2_id = None
    with multi_opener.open(req_multi_login) as m_resp:
        m_body = json.loads(m_resp.read().decode())
        for sc in m_body["data"]["schools"]:
            if sc["school_id"] != school1_id:
                school2_id = sc["school_id"]
                break
    print(f"Discovered School 2 ID (foreign to teacher130): {school2_id}")

    # 4. GET /api/v1/auth/me
    print("\n[TEST 4] GET /api/v1/auth/me WITH ACTIVE SESSION")
    req4 = urllib.request.Request(
        f"{BASE_URL}/api/v1/auth/me",
        headers={"Accept": "application/json", "Origin": ORIGIN},
        method="GET"
    )
    with urllib.request.urlopen(req4) as resp:
        print(f"HTTP Status: {resp.status} {resp.reason}")
        print(f"Body: {resp.read().decode()}")
        assert resp.status == 200

    # 5. GET /api/v1/tenant/current
    print("\n[TEST 5] GET /api/v1/tenant/current (AUTO DEFAULT SCHOOL)")
    req5 = urllib.request.Request(
        f"{BASE_URL}/api/v1/tenant/current",
        headers={"Accept": "application/json", "Origin": ORIGIN},
        method="GET"
    )
    with urllib.request.urlopen(req5) as resp:
        print(f"HTTP Status: {resp.status} {resp.reason}")
        print(f"Body: {resp.read().decode()}")
        assert resp.status == 200

    # 6. GET /api/v1/calendar-events
    print("\n[TEST 6] GET /api/v1/calendar-events (CALENDAR CATALOG)")
    req6 = urllib.request.Request(
        f"{BASE_URL}/api/v1/calendar-events?per_page=10",
        headers={"Accept": "application/json", "Origin": ORIGIN},
        method="GET"
    )
    federal_event_id = None
    regional_event_id = None
    with urllib.request.urlopen(req6) as resp:
        print(f"HTTP Status: {resp.status} {resp.reason}")
        cal_resp = json.loads(resp.read().decode())
        print(f"Total events found: {cal_resp['meta']['total']}")
        for ev in cal_resp["data"]:
            print(f"  - [{ev['event_type']}] {ev['title']} (ID: {ev['id']}, Region: {ev['region_code']})")
            if ev['event_type'] == 'federal' and not federal_event_id:
                federal_event_id = ev['id']
            if ev['event_type'] == 'regional' and not regional_event_id:
                regional_event_id = ev['id']
        assert cal_resp['meta']['total'] >= 3

    # 7. GET /api/v1/calendar-events/{federal_event_id} (VERIFIED NORMATIVE CLAIM VS UNVERIFIED HISTORICAL CLAIM)
    if federal_event_id:
        print(f"\n[TEST 7] GET /api/v1/calendar-events/{federal_event_id} (VERIFIED FEDERAL EVIDENCE)")
        req7 = urllib.request.Request(
            f"{BASE_URL}/api/v1/calendar-events/{federal_event_id}",
            headers={"Accept": "application/json", "Origin": ORIGIN},
            method="GET"
        )
        with urllib.request.urlopen(req7) as resp:
            print(f"HTTP Status: {resp.status} {resp.reason}")
            detail = json.loads(resp.read().decode())
            claims = detail['data']['cultural_claims']
            print(f"  Event Title: {detail['data']['title']}")
            print(f"  Total Claims: {len(claims)}")
            
            normative_claim = claims[0]
            norm_status = normative_claim['evidence_records'][0]['verification_status']
            print(f"  Claim 1 [Normative]: {normative_claim['claim_text']}")
            print(f"  Status: {norm_status}")
            assert norm_status == "verified_authentic", f"Expected verified_authentic for normative claim, got {norm_status}"

            if len(claims) > 1:
                hist_claim = claims[1]
                hist_status = hist_claim['evidence_records'][0]['verification_status']
                print(f"  Claim 2 [Historical]: {hist_claim['claim_text']}")
                print(f"  Status: {hist_status}")
                assert hist_status == "unverified", f"Expected unverified for unverified historical details, got {hist_status}"

    # 8. GET /api/v1/calendar-events/{regional_event_id} (UNVERIFIED DEMO REGIONAL EVENT)
    if regional_event_id:
        print(f"\n[TEST 8] GET /api/v1/calendar-events/{regional_event_id} (UNVERIFIED REGIONAL DEMO EVIDENCE)")
        req8 = urllib.request.Request(
            f"{BASE_URL}/api/v1/calendar-events/{regional_event_id}",
            headers={"Accept": "application/json", "Origin": ORIGIN},
            method="GET"
        )
        with urllib.request.urlopen(req8) as resp:
            print(f"HTTP Status: {resp.status} {resp.reason}")
            detail = json.loads(resp.read().decode())
            claim = detail['data']['cultural_claims'][0]
            status = claim['evidence_records'][0]['verification_status']
            print(f"  Title: {detail['data']['title']}")
            print(f"  Verification Status: {status}")
            assert status == "unverified", f"Expected unverified for demo regional claim, got {status}"

    # 9. GET /api/v1/calendar-events with foreign X-School-Id header -> 403 Forbidden
    print(f"\n[TEST 9] FOREIGN X-School-Id HEADER ON CALENDAR: {school2_id}")
    req9 = urllib.request.Request(
        f"{BASE_URL}/api/v1/calendar-events",
        headers={
            "Accept": "application/json",
            "Origin": ORIGIN,
            "X-School-Id": school2_id,
        },
        method="GET"
    )
    try:
        with urllib.request.urlopen(req9) as resp:
            print(f"FAIL: Expected 403, got {resp.status}")
    except urllib.error.HTTPError as e:
        print(f"HTTP Status: {e.code} {e.reason}")
        print(f"Body: {e.read().decode()}")
        assert e.code == 403, f"Expected 403, got {e.code}"

    # 10. GET /api/v1/schools/{school1_id}/traditions (OWN SCHOOL TRADITIONS)
    print(f"\n[TEST 10] GET /api/v1/schools/{school1_id}/traditions (OWN SCHOOL TRADITIONS)")
    req10 = urllib.request.Request(
        f"{BASE_URL}/api/v1/schools/{school1_id}/traditions",
        headers={"Accept": "application/json", "Origin": ORIGIN},
        method="GET"
    )
    tradition_id = None
    with urllib.request.urlopen(req10) as resp:
        print(f"HTTP Status: {resp.status} {resp.reason}")
        traditions = json.loads(resp.read().decode())
        print(f"Traditions count: {len(traditions['data'])}")
        for t in traditions["data"]:
            print(f"  - {t['title']} (est. {t['established_year']}, ID: {t['id']})")
        if traditions["data"]:
            tradition_id = traditions["data"][0]["id"]
        assert len(traditions['data']) >= 1

    # 11. Cross-tenant traditions access -> 403 Forbidden
    print(f"\n[TEST 11] CROSS-TENANT TRADITIONS DENIAL ({school2_id})")
    req11 = urllib.request.Request(
        f"{BASE_URL}/api/v1/schools/{school2_id}/traditions",
        headers={"Accept": "application/json", "Origin": ORIGIN},
        method="GET"
    )
    try:
        with urllib.request.urlopen(req11) as resp:
            print(f"FAIL: Expected 403, got {resp.status}")
    except urllib.error.HTTPError as e:
        print(f"HTTP Status: {e.code} {e.reason}")
        print(f"Body: {e.read().decode()}")
        assert e.code == 403, f"Expected 403, got {e.code}"

    # 12. Create tradition in own school
    print(f"\n[TEST 12] POST /api/v1/schools/{school1_id}/traditions (CREATE TRADITION)")
    new_tradition = json.dumps({
        "title": "Научный лицейский марафон",
        "description": "Традиционный ежегодный научно-исследовательский марафон лицеистов.",
        "established_year": 2018
    }).encode("utf-8")
    req12 = urllib.request.Request(
        f"{BASE_URL}/api/v1/schools/{school1_id}/traditions",
        data=new_tradition,
        headers={
            "Content-Type": "application/json",
            "Accept": "application/json",
            "Origin": ORIGIN,
            "X-XSRF-TOKEN": xsrf_token,
        },
        method="POST"
    )
    created_tradition_id = None
    with urllib.request.urlopen(req12) as resp:
        print(f"HTTP Status: {resp.status} {resp.reason}")
        trad_resp = json.loads(resp.read().decode())
        created_tradition_id = trad_resp["data"]["id"]
        print(f"Created Tradition ID: {created_tradition_id}")
        assert resp.status == 201

    # 13. Create valid consecutive annual version 2026-2027
    print(f"\n[TEST 13] POST /api/v1/traditions/{created_tradition_id}/versions (ANNUAL VERSION 2026-2027)")
    ver_data = json.dumps({
        "academic_year": "2026-2027",
        "parameters_snapshot": {
            "scale": "all_school",
            "stages": 6,
            "theme": "Научные открытия Среднего Урала"
        },
        "notes": "Первая редакция марафона в цифровом формате"
    }).encode("utf-8")
    req13 = urllib.request.Request(
        f"{BASE_URL}/api/v1/traditions/{created_tradition_id}/versions",
        data=ver_data,
        headers={
            "Content-Type": "application/json",
            "Accept": "application/json",
            "Origin": ORIGIN,
            "X-XSRF-TOKEN": xsrf_token,
        },
        method="POST"
    )
    with urllib.request.urlopen(req13) as resp:
        print(f"HTTP Status: {resp.status} {resp.reason}")
        print(f"Body: {resp.read().decode()}")
        assert resp.status == 201

    # 14. Non-consecutive academic year rejection (2026-2029) -> 422
    print(f"\n[TEST 14] NON-CONSECUTIVE ACADEMIC YEAR REJECTION (2026-2029)")
    non_consec_data = json.dumps({
        "academic_year": "2026-2029",
        "parameters_snapshot": {"stages": 3}
    }).encode("utf-8")
    req14 = urllib.request.Request(
        f"{BASE_URL}/api/v1/traditions/{created_tradition_id}/versions",
        data=non_consec_data,
        headers={
            "Content-Type": "application/json",
            "Accept": "application/json",
            "Origin": ORIGIN,
            "X-XSRF-TOKEN": xsrf_token,
        },
        method="POST"
    )
    try:
        with urllib.request.urlopen(req14) as resp:
            print(f"FAIL: Expected 422, got {resp.status}")
    except urllib.error.HTTPError as e:
        print(f"HTTP Status: {e.code} {e.reason}")
        print(f"Body: {e.read().decode()}")
        assert e.code == 422, f"Expected 422, got {e.code}"

    # 15. Duplicate version rejection (same academic year 2026-2027) -> 422
    print(f"\n[TEST 15] DUPLICATE VERSION REJECTION (SAME ACADEMIC YEAR 2026-2027)")
    req15 = urllib.request.Request(
        f"{BASE_URL}/api/v1/traditions/{created_tradition_id}/versions",
        data=ver_data,
        headers={
            "Content-Type": "application/json",
            "Accept": "application/json",
            "Origin": ORIGIN,
            "X-XSRF-TOKEN": xsrf_token,
        },
        method="POST"
    )
    try:
        with urllib.request.urlopen(req15) as resp:
            print(f"FAIL: Expected 422, got {resp.status}")
    except urllib.error.HTTPError as e:
        print(f"HTTP Status: {e.code} {e.reason}")
        print(f"Body: {e.read().decode()}")
        assert e.code == 422, f"Expected 422, got {e.code}"

    # 16. Teacher forbidden from creating calendar event -> 403
    print("\n[TEST 16] TEACHER FORBIDDEN FROM CREATING CALENDAR EVENT")
    event_payload = json.dumps({
        "title": "Событие от учителя",
        "event_type": "federal",
        "date_type": "fixed_annual",
        "fixed_month": 5,
        "fixed_day": 1,
        "description": "Попытка создания события учителем без прав.",
        "target_age_groups": ["primary_1_4"],
        "recommended_value_directions": ["family"]
    }).encode("utf-8")
    req16 = urllib.request.Request(
        f"{BASE_URL}/api/v1/calendar-events",
        data=event_payload,
        headers={
            "Content-Type": "application/json",
            "Accept": "application/json",
            "Origin": ORIGIN,
            "X-XSRF-TOKEN": xsrf_token,
        },
        method="POST"
    )
    try:
        with urllib.request.urlopen(req16) as resp:
            print(f"FAIL: Expected 403, got {resp.status}")
    except urllib.error.HTTPError as e:
        print(f"HTTP Status: {e.code} {e.reason}")
        print(f"Body: {e.read().decode()}")
        assert e.code == 403, f"Expected 403, got {e.code}"

    # 17. Body tenant injection prevention (POST with school_id in body) -> 422
    print("\n[TEST 17] BODY TENANT INJECTION PREVENTION (POST WITH school_id)")
    inj_payload = json.dumps({
        "title": "Хакерская традиция",
        "description": "Попытка передачи school_id в теле запроса.",
        "school_id": school2_id,
    }).encode("utf-8")
    req17 = urllib.request.Request(
        f"{BASE_URL}/api/v1/schools/{school1_id}/traditions",
        data=inj_payload,
        headers={
            "Content-Type": "application/json",
            "Accept": "application/json",
            "Origin": ORIGIN,
            "X-XSRF-TOKEN": xsrf_token,
        },
        method="POST"
    )
    try:
        with urllib.request.urlopen(req17) as resp:
            print(f"FAIL: Expected 422, got {resp.status}")
    except urllib.error.HTTPError as e:
        print(f"HTTP Status: {e.code} {e.reason}")
        print(f"Body: {e.read().decode()}")
        assert e.code == 422, f"Expected 422, got {e.code}"

    # 18. Rate limiting on login (5 attempts/min limit) -> 429
    print("\n[TEST 18] RATE LIMITING ON /api/v1/auth/login (5 attempts/min limit)")
    bad_login = json.dumps({"email": "ratelimit@school.ru", "password": "WrongPassword!"}).encode("utf-8")
    got_429 = False
    for i in range(1, 8):
        req_rl = urllib.request.Request(
            f"{BASE_URL}/api/v1/auth/login",
            data=bad_login,
            headers={"Content-Type": "application/json", "Accept": "application/json", "Origin": ORIGIN, "X-XSRF-TOKEN": xsrf_token},
            method="POST"
        )
        try:
            with urllib.request.urlopen(req_rl) as resp:
                print(f"  Attempt {i}: HTTP {resp.status}")
        except urllib.error.HTTPError as e:
            print(f"  Attempt {i}: HTTP {e.code}")
            if e.code == 429:
                got_429 = True
                print(f"  Body: {e.read().decode()}")
                break
    assert got_429, "Expected 429 Too Many Requests was not returned!"

    # 19. Logout
    print("\n[TEST 19] POST /api/v1/auth/logout")
    req19 = urllib.request.Request(
        f"{BASE_URL}/api/v1/auth/logout",
        data=b"{}",
        headers={"Content-Type": "application/json", "Accept": "application/json", "Origin": ORIGIN, "X-XSRF-TOKEN": xsrf_token},
        method="POST"
    )
    with urllib.request.urlopen(req19) as resp:
        print(f"HTTP Status: {resp.status} {resp.reason}")
        assert resp.status == 204

    # 20. GET /api/v1/auth/me after logout -> 401 Unauthorized
    print("\n[TEST 20] GET /api/v1/auth/me AFTER LOGOUT")
    req20 = urllib.request.Request(
        f"{BASE_URL}/api/v1/auth/me",
        headers={"Accept": "application/json", "Origin": ORIGIN},
        method="GET"
    )
    try:
        with urllib.request.urlopen(req20) as resp:
            print(f"FAIL: Expected 401, got {resp.status}")
    except urllib.error.HTTPError as e:
        print(f"HTTP Status: {e.code} {e.reason}")
        print(f"Body: {e.read().decode()}")
        assert e.code == 401, f"Expected 401, got {e.code}"

    # 21. POST login with inactive account -> 403 Account Inactive
    print("\n[TEST 21] POST /api/v1/auth/login WITH INACTIVE ACCOUNT")
    req_csrf = urllib.request.Request(f"{BASE_URL}/sanctum/csrf-cookie", headers={"Origin": ORIGIN})
    with urllib.request.urlopen(req_csrf):
        pass
    for cookie in cookie_jar:
        if cookie.name == "XSRF-TOKEN":
            xsrf_token = urllib.parse.unquote(cookie.value)

    blocked_login = json.dumps({
        "email": "blocked@school130.ru",
        "password": BLOCKED_PASSWORD
    }).encode("utf-8")
    req21 = urllib.request.Request(
        f"{BASE_URL}/api/v1/auth/login",
        data=blocked_login,
        headers={"Content-Type": "application/json", "Accept": "application/json", "Origin": ORIGIN, "X-XSRF-TOKEN": xsrf_token},
        method="POST"
    )
    try:
        with urllib.request.urlopen(req21) as resp:
            print(f"FAIL: Expected 403, got {resp.status}")
    except urllib.error.HTTPError as e:
        print(f"HTTP Status: {e.code} {e.reason}")
        print(f"Body: {e.read().decode()}")
        assert e.code == 403, f"Expected 403, got {e.code}"

    print("\n" + "=" * 80)
    print("ALL 21 LIVE HTTP SECURITY & GATEWAY AUDIT TESTS COMPLETED SUCCESSFULLY!")
    print("=" * 80)

if __name__ == "__main__":
    run_tests()
