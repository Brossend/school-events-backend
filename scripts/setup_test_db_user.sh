#!/usr/bin/env bash
# Idempotent PostgreSQL Test Database & User Setup for Existing or New Volumes
# Safe to execute against live/existing database volumes without data loss or touching school_events.

set -e

POSTGRES_USER="${DB_USERNAME:-postgres}"
POSTGRES_DB="${DB_DATABASE:-school_events}"
TEST_USER="${DB_TEST_USERNAME:-school_test_user}"
TEST_PASS="${DB_TEST_PASSWORD:-}"

# Check .env.testing if TEST_PASS not provided in environment
if [ -z "$TEST_PASS" ] && [ -f ".env.testing" ]; then
    TEST_PASS=$(grep -E "^DB_PASSWORD=" .env.testing | head -n1 | cut -d= -f2- | tr -d " '\r\n\"")
fi

# Check .env if TEST_PASS still not provided
if [ -z "$TEST_PASS" ] && [ -f ".env" ]; then
    TEST_PASS=$(grep -E "^DB_TEST_PASSWORD=" .env | head -n1 | cut -d= -f2- | tr -d " '\r\n\"")
fi

if [ -z "$TEST_PASS" ]; then
    echo "[-] ERROR: DB_TEST_PASSWORD is not configured and could not be found in .env.testing or .env." >&2
    echo "    Please set DB_TEST_PASSWORD in environment or define DB_PASSWORD in .env.testing." >&2
    exit 1
fi

echo "[*] Configuring test database '$TEST_USER' and enforcing tenant isolation..."

# Determine whether to use local psql or docker compose exec postgres
execute_psql() {
    local target_db="$1"
    local sql_commands="$2"

    if command -v psql >/dev/null 2>&1; then
        psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$target_db" <<< "$sql_commands"
    else
        docker compose exec -T postgres psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$target_db" <<< "$sql_commands"
    fi
}

# 1. Ensure test database exists and revoke connect on primary database
SQL_INIT="
SELECT 'CREATE DATABASE school_events_test'
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = 'school_events_test')\\gexec

DO \$\$
BEGIN
    IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = '$TEST_USER') THEN
        EXECUTE format('CREATE USER %I WITH ENCRYPTED PASSWORD %L', '$TEST_USER', '$TEST_PASS');
    ELSE
        EXECUTE format('ALTER USER %I WITH ENCRYPTED PASSWORD %L', '$TEST_USER', '$TEST_PASS');
    END IF;
END
\$\$;

GRANT ALL PRIVILEGES ON DATABASE school_events_test TO $TEST_USER;
ALTER DATABASE school_events_test OWNER TO $TEST_USER;

-- Strict Isolation: revoke connection to primary working database
REVOKE CONNECT ON DATABASE $POSTGRES_DB FROM PUBLIC;
REVOKE CONNECT ON DATABASE $POSTGRES_DB FROM $TEST_USER;
GRANT CONNECT ON DATABASE $POSTGRES_DB TO $POSTGRES_USER;
"

execute_psql "$POSTGRES_DB" "$SQL_INIT"

# 2. Grant permissions on school_events_test schema public
SQL_PERMS="
GRANT ALL ON SCHEMA public TO $TEST_USER;
GRANT ALL PRIVILEGES ON ALL TABLES IN SCHEMA public TO $TEST_USER;
GRANT ALL PRIVILEGES ON ALL SEQUENCES IN SCHEMA public TO $TEST_USER;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL ON TABLES TO $TEST_USER;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL ON SEQUENCES TO $TEST_USER;
"

execute_psql "school_events_test" "$SQL_PERMS"

echo "[+] Database and test user configuration completed successfully."
echo "[+] Isolation check: $TEST_USER is strictly forbidden from connecting to $POSTGRES_DB."
