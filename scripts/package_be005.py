import os
import zipfile
import hashlib
from pathlib import Path

ROOT_DIR = Path(__file__).resolve().parent.parent
ARCHIVE_NAME = "school-backend-be005.zip"
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
    ".nuxt",
    ".output",
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
    if rel_path.name.endswith(".zip"):
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
        if parts[2] in ("cache", "sessions", "views", "testing"):
            return True
    # exclude generated export binaries
    if len(parts) >= 4 and parts[0] == "storage" and parts[1] == "app" and parts[2] == "private" and parts[3] == "exports" and rel_path.name != ".gitignore":
        return True
    return False

def build_zip():
    if ARCHIVE_PATH.exists():
        ARCHIVE_PATH.unlink()

    file_count = 0
    with zipfile.ZipFile(ARCHIVE_PATH, "w", zipfile.ZIP_DEFLATED) as zf:
        for root, dirs, files in os.walk(ROOT_DIR):
            for file in files:
                full_path = Path(root) / file
                rel_path = full_path.relative_to(ROOT_DIR)
                if not should_exclude(rel_path):
                    zf.write(full_path, rel_path.as_posix())
                    file_count += 1

    sha256 = hashlib.sha256(ARCHIVE_PATH.read_bytes()).hexdigest()
    size_mb = ARCHIVE_PATH.stat().st_size / (1024 * 1024)
    print(f"Archive: {ARCHIVE_NAME}")
    print(f"Files packaged: {file_count}")
    print(f"Size: {size_mb:.2f} MB")
    print(f"SHA-256: {sha256}")

if __name__ == "__main__":
    build_zip()
