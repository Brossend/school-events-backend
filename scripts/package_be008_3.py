import os
import zipfile
import hashlib
from pathlib import Path

ROOT_DIR = Path(__file__).resolve().parent.parent
ARCHIVE_NAME = "school-backend-be008-3.zip"
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
    ".env.backup",
    ".env.production",
    "cookies.txt",
    ".phpunit.result.cache",
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

    # exclude log contents
    if len(parts) >= 2 and parts[0] == "storage" and parts[1] == "logs" and rel_path.name != ".gitignore":
        return True
    # exclude bootstrap cache generated files
    if len(parts) >= 2 and parts[0] == "bootstrap" and parts[1] == "cache" and rel_path.name != ".gitignore":
        return True
    # exclude session / cache / testing files
    if len(parts) >= 3 and parts[0] == "storage" and parts[1] == "framework" and rel_path.name != ".gitignore":
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

    print("=" * 60)
    print(f"RELEASE CANDIDATE PACKAGE CREATED: {ARCHIVE_NAME}")
    print(f"Total files packaged: {count}")
    print(f"Archive size:         {size_kb:.2f} KB ({ARCHIVE_PATH.stat().st_size} bytes)")
    print(f"SHA-256 Checksum:     {sha256}")
    print("=" * 60)

if __name__ == "__main__":
    package()
