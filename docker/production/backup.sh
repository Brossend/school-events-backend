#!/bin/bash
set -euo pipefail
umask 077
cd /opt/school-events
set -a
source current.env
set +a
compose() { docker compose --env-file /dev/null -f compose.yml "$@"; }
target="backups/$(date -u +%Y%m%dT%H%M%SZ)"
mkdir -p "$target"
compose exec -T postgres sh -c 'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Fc' > "$target/database.dump"
compose exec -T app tar -C /var/www/html/storage -czf - app > "$target/storage.tar.gz"
cp current.env "$target/release.env"
sha256sum "$target"/* > "$target/checksums.sha256"
echo "Backup created: $target"
# No automatic deletion: review capacity and export encrypted copies off-site.
