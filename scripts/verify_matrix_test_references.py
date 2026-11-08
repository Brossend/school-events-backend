#!/usr/bin/env python3
"""
Design Matrix Test Reference Verification Suite
Validates that 100% of the PHPUnit test references in docs/design-backend-coverage-final.md
exist as real, executable test methods in the codebase.
"""

import glob
import re
import sys
from pathlib import Path

ROOT_DIR = Path(__file__).resolve().parent.parent
MATRIX_FILE = ROOT_DIR / "docs" / "design-backend-coverage-final.md"
TESTS_DIR = ROOT_DIR / "tests"

def load_existing_tests():
    """
    Scan all PHP test files in tests/ and return a set of 'ClassName::method_name'.
    """
    test_set = set()
    for path in glob.glob(str(TESTS_DIR / "**" / "*.php"), recursive=True):
        with open(path, "r", encoding="utf-8") as f:
            content = f.read()
        class_match = re.search(r'class\s+(\w+)\s+extends', content)
        if not class_match:
            continue
        cls_name = class_match.group(1)
        methods = re.findall(r'public\s+function\s+(test_\w+)\s*\(', content)
        for m in methods:
            test_set.add(f"{cls_name}::{m}")
    return test_set

def extract_matrix_references():
    """
    Extract test references from docs/design-backend-coverage-final.md tables.
    Returns list of tuples: (row_id, endpoint, test_ref).
    """
    if not MATRIX_FILE.exists():
        raise FileNotFoundError(f"Matrix file not found: {MATRIX_FILE}")

    references = []
    with open(MATRIX_FILE, "r", encoding="utf-8") as f:
        for line in f:
            line_str = line.strip()
            # Match table rows starting with a section.item number (e.g. | 1.1 |)
            m = re.match(r'^\|\s*(\d+\.\d+)\s*\|(.*?)\|$', line_str)
            if m:
                parts = [p.strip() for p in line_str.split('|')[1:-1]]
                if len(parts) >= 6:
                    row_id = parts[0]
                    endpoint = parts[2].replace("`", "").strip()
                    # The test column is index 5
                    test_raw = parts[5].replace("`", "").strip()
                    references.append((row_id, endpoint, test_raw))
    return references

def verify():
    print("=" * 70)
    print("VERIFYING DESIGN COVERAGE MATRIX TEST REFERENCES")
    print("=" * 70)

    try:
        existing_tests = load_existing_tests()
    except Exception as e:
        print(f"[-] ERROR: Failed to load tests: {e}")
        return 1

    print(f"[+] Loaded {len(existing_tests)} valid PHPUnit test methods from codebase.")

    try:
        matrix_refs = extract_matrix_references()
    except Exception as e:
        print(f"[-] ERROR: Failed to parse matrix file: {e}")
        return 1

    print(f"[+] Found {len(matrix_refs)} test references in {MATRIX_FILE.name}.")

    matched = 0
    missing = []
    for row_id, endpoint, ref in matrix_refs:
        # Some rows might reference multiple tests separated by comma or semicolon
        refs_to_check = [r.strip() for r in re.split(r'[,;]', ref) if r.strip()]
        row_ok = True
        for single_ref in refs_to_check:
            if single_ref in existing_tests:
                pass
            else:
                row_ok = False
                missing.append((row_id, endpoint, single_ref))
        if row_ok:
            matched += 1

    print(f"[*] Validated {matched}/{len(matrix_refs)} matrix rows.")

    if missing:
        print(f"[-] FAIL: {len(missing)} invalid or missing test references found:")
        for row_id, ep, bad_ref in missing:
            print(f"    - Row {row_id} ({ep}): '{bad_ref}' does not exist in tests/")
        return 1

    print("[+] SUCCESS: 100% of PHPUnit test references in design matrix are valid!")
    print("=" * 70)
    return 0

if __name__ == "__main__":
    sys.exit(verify())
