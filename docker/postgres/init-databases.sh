#!/usr/bin/env bash
set -e

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<-EOSQL
    SELECT 'CREATE DATABASE school_events_test'
    WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = 'school_events_test')\gexec
EOSQL
