#!/usr/bin/env python3
"""
Packaging script for BE-009.1 release candidate: school-backend-be009-1.zip
Includes automatic cleanliness validation and SHA-256 generation.
"""

import hashlib
import os
import sys
import zipfile
from pathlib import Path

ROOT_DIR = Path(__file__).resolve().parent.parent
ARCHIVE_NAME = "school-backend-be009-1.zip"
ARCHIVE_PATH = ROOT_DIR / ARCHIVE_NAME

EXCLUDE_DIRS = {
    ".git",
    "vendor",
    "node_modules",
    "__pycache__",
    ".idea",
    ".vscode",
    ".phpunit.cache",
    ".gemini",
    "brain",
    "exports",
    "frontend",
    ".output",
    ".nuxt",
    ".venv",
    "venv",
}

EXCLUDE_FILES = {
    ".env",
    ".env.testing",
    ".env.backup",
    ".env.production",
    "cookies.txt",
    ".phpunit.result.cache",
    "all_tests.json",
    "all_tests_exact.json",
    "phpunit_tests.txt",
}

def should_exclude(rel_path: Path):
    parts = rel_path.parts
    for p in parts:
        if p in EXCLUDE_DIRS:
            return True
        if p == "screenshots":
            return True
        if p == "figma":
            return True

    if rel_path.name.endswith(".zip"):
        return True
    if rel_path.name.endswith(".log") and rel_path.name != ".gitignore":
        return True
    if rel_path.name.endswith(".fig"):
        return True
    if rel_path.name in EXCLUDE_FILES:
        return True

    # Exclude any .env file that is not in the allowed list
    if rel_path.name.startswith(".env") and rel_path.name not in [".env.example", ".env.testing.example"]:
        return True

    # Exclude dynamic cache and log contents
    if len(parts) >= 2 and parts[0] == "storage" and parts[1] == "logs" and rel_path.name != ".gitignore":
        return True
    if len(parts) >= 2 and parts[0] == "bootstrap" and parts[1] == "cache" and rel_path.name != ".gitignore":
        return True
    if len(parts) >= 3 and parts[0] == "storage" and parts[1] == "framework" and rel_path.name != ".gitignore":
        return True
    if len(parts) >= 3 and parts[0] == "storage" and parts[1] == "app" and parts[2] == "testing":
        return True

    return False

def package():
    if ARCHIVE_PATH.exists():
        ARCHIVE_PATH.unlink()

    count = 0
    with zipfile.ZipFile(ARCHIVE_PATH, "w", zipfile.ZIP_DEFLATED) as zipf:
        for root, dirs, files in os.walk(ROOT_DIR):
            for file in files:
                full_path = Path(root) / file
                rel_path = full_path.relative_to(ROOT_DIR)

                if should_exclude(rel_path):
                    continue

                zipf.write(full_path, rel_path.as_posix())
                count += 1

    sha256 = hashlib.sha256(ARCHIVE_PATH.read_bytes()).hexdigest()
    size_kb = ARCHIVE_PATH.stat().st_size / 1024

    print("=" * 70)
    print(f"RELEASE CANDIDATE PACKAGE CREATED: {ARCHIVE_NAME}")
    print(f"Total files packaged: {count}")
    print(f"Archive size:         {size_kb:.2f} KB ({ARCHIVE_PATH.stat().st_size} bytes)")
    print(f"SHA-256 Checksum:     {sha256}")
    print("=" * 70)

    # Automatically run cleanliness check
    from verify_archive_cleanliness import verify_zip
    res = verify_zip(ARCHIVE_PATH)
    if res != 0:
        print("[-] Packaging FAILED cleanliness validation!")
        sys.exit(res)

    print("[+] Packaging and cleanliness validation SUCCEEDED!")

if __name__ == "__main__":
    package()
