#!/bin/bash
set -euo pipefail
umask 077
[[ $# == 2 && $1 =~ ^[0-9a-f]{40}$ && $2 =~ ^[0-9a-f]{40}$ ]] || exit 64
cd /opt/school-events
exec 9>/var/lock/school-events-deploy.lock
flock -n 9 || { echo "Another deployment is running"; exit 75; }
export BACKEND_SHA=$1 FRONTEND_SHA=$2
backend_image="registry.gitlab.com/brossend2335/school-events-backend:$BACKEND_SHA"
frontend_image="registry.gitlab.com/brossend2335/school-events-frontend:$FRONTEND_SHA"
DOCKER_CONFIG=/opt/school-events/secrets/registry-backend docker pull "$backend_image"
DOCKER_CONFIG=/opt/school-events/secrets/registry-frontend docker pull "$frontend_image"
mkdir -p "releases/$BACKEND_SHA"
for file in compose.yml nginx.conf Caddyfile; do
    docker run --rm --network none --entrypoint cat "$backend_image" "/opt/deployment/$file" > "releases/$BACKEND_SHA/$file"
done
compose() { docker compose --env-file /dev/null -f /opt/school-events/compose.yml "$@"; }
if [[ -f current.env ]]; then
    (set -a; source current.env; set +a; compose stop worker)
    /usr/local/sbin/school-backup
    cp current.env previous.env
fi
cp "releases/$BACKEND_SHA/"{compose.yml,nginx.conf,Caddyfile} .
compose config --quiet
compose up -d --wait postgres redis
# Only the initial empty database needs an application role. Never rotate it on deploy.
if ! compose exec -T postgres psql -U school_owner -d school_events -tAc "SELECT 1 FROM pg_roles WHERE rolname='school_app'" | grep -q 1; then
    (
        source /opt/school-events/secrets/backend.env
        [[ $DB_PASSWORD =~ ^[0-9a-f]{64}$ ]] || exit 65
        printf "CREATE ROLE school_app LOGIN PASSWORD '%s' NOSUPERUSER NOCREATEDB NOCREATEROLE; ALTER DATABASE school_events OWNER TO school_app; GRANT ALL ON SCHEMA public TO school_app;\n" "$DB_PASSWORD"
    ) | compose exec -T postgres psql -v ON_ERROR_STOP=1 -U school_owner -d school_events >/dev/null
fi
# New volumes inherit the application storage skeleton from this exact image.
compose run --rm --no-deps app php artisan migrate --force --no-interaction
compose up -d --wait --wait-timeout 180
curl --fail --silent --show-error http://127.0.0.1:8080/api/v1/health >/dev/null
curl --fail --silent --show-error http://127.0.0.1:8080/login >/dev/null
printf "BACKEND_SHA=%s\nFRONTEND_SHA=%s\n" "$BACKEND_SHA" "$FRONTEND_SHA" > current.env
cp current.env "releases/$(date -u +%Y%m%dT%H%M%SZ).env"
echo "Deployment healthy: $BACKEND_SHA / $FRONTEND_SHA"
