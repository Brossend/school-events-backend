#!/bin/sh
set -eu
case "$DB_PASSWORD" in *[!0-9a-f]*|'') echo 'Invalid practice DB password format' >&2; exit 1;; esac
psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname postgres -v app_password="$DB_PASSWORD" <<'SQL'
CREATE ROLE school_ux001_app LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE PASSWORD :'app_password';
CREATE DATABASE school_events_ux001 OWNER school_ux001_app;
SQL
