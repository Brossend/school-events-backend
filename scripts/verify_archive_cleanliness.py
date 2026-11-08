#!/usr/bin/env python3
"""
Archive Cleanliness and Secret Hygiene Verification Suite
Validates that release ZIP archives contain no sensitive environment files,
no active APP_KEYs, and no hardcoded test passwords.
"""

import sys
import zipfile
from pathlib import Path

ROOT_DIR = Path(__file__).resolve().parent.parent

# Allowed example/template files
ALLOWED_ENV_FILES = {
    ".env.example",
    ".env.testing.example",
}

FORBIDDEN_PATTERNS = [b"APP_KEY=base64:"]
# Read local secrets without embedding them in distributable source.
for env_file in (".env", ".env.testing"):
    env_path = ROOT_DIR / env_file
    if env_path.is_file():
        for line in env_path.read_text(encoding="utf-8-sig").splitlines():
            if "=" not in line or line.lstrip().startswith("#"):
                continue
            key, value = line.split("=", 1)
            value = value.strip().strip(chr(34) + chr(39))
            if any(word in key for word in ("PASSWORD", "SECRET", "TOKEN", "APP_KEY")) and len(value) > 7 and value not in {"postgres", "password", "changeme"} and not value.startswith("${"):
                FORBIDDEN_PATTERNS.append(value.encode("utf-8"))


def verify_zip(zip_path: Path) -> int:
    print("=" * 70)
    print(f"VERIFYING ARCHIVE CLEANLINESS & SECRET HYGIENE: {zip_path.name}")
    print("=" * 70)

    if not zip_path.exists():
        print(f"[-] FAIL: Archive does not exist: {zip_path}")
        return 1

    violations = []
    file_count = 0

    with zipfile.ZipFile(zip_path, "r") as z:
        namelist = z.namelist()
        file_count = len(namelist)

        for name in namelist:
            # Check for forbidden .env files
            basename = Path(name).name
            if basename.startswith(".env"):
                if basename not in ALLOWED_ENV_FILES:
                    violations.append(f"Forbidden environment file included: {name}")

            # Check contents of files for forbidden secrets
            # Inspect text/config files only
            if any(name.endswith(ext) for ext in [".php", ".yml", ".yaml", ".sh", ".json", ".example", ".conf"]):
                try:
                    content = z.read(name)
                    for pattern in FORBIDDEN_PATTERNS:
                        if pattern in content:
                            violations.append(f"Forbidden secret content found in: {name}")
                except Exception as e:
                    violations.append(f"Failed to read {name}: {e}")

    print(f"[+] Total files inspected in archive: {file_count}")

    if violations:
        print(f"[-] FAIL: {len(violations)} security cleanliness violation(s) detected:")
        for v in violations:
            print(f"    - {v}")
        return 1

    print("[+] SUCCESS: Archive is clean! No unapproved .env files, no active APP_KEY, no hardcoded secrets.")
    print("=" * 70)
    return 0

if __name__ == "__main__":
    archive_file = ROOT_DIR / "school-backend-be009-1.zip"
    if len(sys.argv) > 1:
        archive_file = Path(sys.argv[1])
    sys.exit(verify_zip(archive_file))
