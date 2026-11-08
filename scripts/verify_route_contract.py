#!/usr/bin/env python3
"""
Route & OpenAPI Contract Verification Suite
Ensures 100% synchronization between Laravel registered routes and OpenAPI 3.1.0 specification.
"""

import json
import subprocess
import sys
import yaml
from pathlib import Path

ROOT_DIR = Path(__file__).resolve().parent.parent
OPENAPI_FILE = ROOT_DIR / "docs" / "openapi.yaml"

def get_artisan_routes():
    """
    Retrieve active API routes from Laravel via Docker Compose, Docker exec, or local PHP.
    Does NOT use any hardcoded fallback.
    Returns (routes_list, None) on success, or (None, error_message) on failure.
    """
    cmd_candidates = [
        ["docker", "compose", "exec", "-T", "app", "php", "artisan", "route:list", "--path=api", "--json"],
        ["docker", "exec", "school-app-1", "php", "artisan", "route:list", "--path=api", "--json"],
        ["php", "artisan", "route:list", "--path=api", "--json"],
    ]

    last_error = None
    for cmd in cmd_candidates:
        try:
            res = subprocess.run(
                cmd,
                stdout=subprocess.PIPE,
                stderr=subprocess.PIPE,
                cwd=ROOT_DIR,
                text=True,
                timeout=25
            )
            stdout = res.stdout.strip()
            if res.returncode == 0 and stdout.startswith("["):
                try:
                    data = json.loads(stdout)
                    if isinstance(data, list) and len(data) > 0:
                        return data, None
                except json.JSONDecodeError as je:
                    last_error = f"JSON decode error: {je}"
            elif res.stderr:
                last_error = res.stderr.strip()
        except subprocess.TimeoutExpired:
            last_error = f"Command timed out: {' '.join(cmd)}"
        except FileNotFoundError:
            continue
        except Exception as e:
            last_error = str(e)

    return None, last_error or "All candidate commands failed to execute"

def verify_contracts():
    print("=" * 70)
    print("VERIFYING LARAVEL ROUTE LIST VS OPENAPI 3.1.0 SPECIFICATION")
    print("=" * 70)

    if not OPENAPI_FILE.exists():
        print(f"FAIL: OpenAPI file not found at {OPENAPI_FILE}")
        return 1

    try:
        with open(OPENAPI_FILE, "r", encoding="utf-8") as f:
            spec = yaml.safe_load(f)
    except Exception as e:
        print(f"FAIL: Failed to parse OpenAPI YAML: {e}")
        return 1

    openapi_paths = spec.get("paths", {})
    future_endpoints = spec.get("x-future-endpoints", {})

    # Extract OpenAPI active operations: {(method, normalized_path)}
    openapi_ops = set()
    for path, path_item in openapi_paths.items():
        if not isinstance(path_item, dict):
            continue
        for method, op in path_item.items():
            if method.lower() in ["get", "post", "patch", "put", "delete"]:
                openapi_ops.add((method.upper(), path))

    # Retrieve live routes from Laravel
    raw_routes, err = get_artisan_routes()
    if raw_routes is None:
        print(f"[-] FAIL: VERIFICATION_UNAVAILABLE: Unable to retrieve active Laravel routes via Docker or local PHP CLI.")
        if err:
            print(f"[-] Diagnostic details: {err}")
        return 2

    laravel_ops = set()
    for r in raw_routes:
        uri = r.get("uri", "").strip("/")
        # Explicit environment rule: exclude testing harness routes (registered conditionally in local/testing)
        if uri.startswith("api/v1/test/") or uri == "api/v1/test":
            continue

        # Strip api/v1 prefix
        if uri.startswith("api/v1/"):
            norm_path = "/" + uri[len("api/v1/"):]
        elif uri == "api/v1":
            norm_path = "/"
        else:
            norm_path = "/" + uri

        raw_methods = r.get("method", "").split("|")
        for m in raw_methods:
            m = m.strip().upper()
            if m in ["GET", "POST", "PATCH", "PUT", "DELETE"]:
                laravel_ops.add((m, norm_path))

    print(f"Total Laravel active production operations: {len(laravel_ops)}")
    print(f"Total OpenAPI active operations:            {len(openapi_ops)}")
    print(f"Total OpenAPI future endpoints (planned):   {len(future_endpoints)}")

    missing_in_openapi = sorted(laravel_ops - openapi_ops)
    missing_in_laravel = sorted(openapi_ops - laravel_ops)

    if missing_in_openapi:
        print("\n[!] Discrepancy: Active Laravel routes missing from OpenAPI paths:")
        for m, p in missing_in_openapi:
            print(f"    - {m} {p}")

    if missing_in_laravel:
        print("\n[!] Discrepancy: OpenAPI paths missing from active Laravel routes:")
        for m, p in missing_in_laravel:
            print(f"    - {m} {p}")

    # Check future endpoints are cleanly separated
    expected_future = {"/auth/forgot-password"}
    actual_future = set(future_endpoints.keys())
    future_mismatch = expected_future - actual_future
    if future_mismatch:
        print(f"\n[!] Warning: Expected future endpoints missing from x-future-endpoints: {future_mismatch}")

    if not missing_in_openapi and not missing_in_laravel:
        print("\n[+] SUCCESS: 100% agreement between Laravel route list and OpenAPI 3.1.0!")
        print(f"[+] All {len(laravel_ops)} production operations matched exactly.")
        print(f"[+] All {len(future_endpoints)} future roadmap endpoints isolated in x-future-endpoints.")
        print("=" * 70)
        return 0
    else:
        print("\n[-] FAIL: Route contracts are out of sync!")
        print("=" * 70)
        return 1

if __name__ == "__main__":
    sys.exit(verify_contracts())
