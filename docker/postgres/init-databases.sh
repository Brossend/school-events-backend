#!/usr/bin/env bash
set -e

TEST_USER="${DB_TEST_USERNAME:-school_test_user}"
TEST_PASS="${DB_TEST_PASSWORD:-}"

# Always ensure test database exists and primary database is protected from public connections
psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<-EOSQL
    SELECT 'CREATE DATABASE school_events_test'
    WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = 'school_events_test')\gexec

    REVOKE CONNECT ON DATABASE $POSTGRES_DB FROM PUBLIC;
    GRANT CONNECT ON DATABASE $POSTGRES_DB TO $POSTGRES_USER;
EOSQL

if [ -n "$TEST_PASS" ]; then
    echo "[*] Initializing dedicated test user $TEST_USER with configured secret..."
    psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<-EOSQL
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
        REVOKE CONNECT ON DATABASE $POSTGRES_DB FROM $TEST_USER;
EOSQL

    psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "school_events_test" <<-EOSQL
        GRANT ALL ON SCHEMA public TO $TEST_USER;
        GRANT ALL PRIVILEGES ON ALL TABLES IN SCHEMA public TO $TEST_USER;
        GRANT ALL PRIVILEGES ON ALL SEQUENCES IN SCHEMA public TO $TEST_USER;
        ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL ON TABLES TO $TEST_USER;
        ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL ON SEQUENCES TO $TEST_USER;
EOSQL

    echo "[+] Dedicated test user $TEST_USER successfully provisioned and isolated."
else
    echo "[!] DB_TEST_PASSWORD is not set. Skipping test user creation during initdb."
    echo "[!] Main database and services will start normally."
    echo "[!] Test contour (app-test) will refuse to start until DB_TEST_PASSWORD is configured and scripts/setup_test_db_user.sh is executed."
fi
