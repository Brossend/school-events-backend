#!/usr/bin/env python3
import subprocess
import sys

print("Running PHP syntax lint on all PHP files in container...")
res = subprocess.run(
    ["docker", "compose", "exec", "-T", "app", "find", "app", "routes", "config", "database", "tests", "-name", "*.php", "-exec", "php", "-l", "{}", "+"],
    capture_output=True,
    text=True
)
lines = res.stdout.splitlines()
errors = [l for l in lines if "Parse error" in l or "Fatal error" in l]
if errors:
    print(f"[-] PHP Lint Failed with {len(errors)} errors:")
    for e in errors:
        print(f"    {e}")
    sys.exit(1)

valid_files = [l for l in lines if "No syntax errors detected" in l]
print(f"[+] PHP Lint Success: All {len(valid_files)} files syntax check OK (0 errors).")
