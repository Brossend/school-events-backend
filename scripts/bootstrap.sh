#!/usr/bin/env bash
# Bash Bootstrap Script for School Events Backend (BE-002)
set -e

echo "================================================================="
echo "Starting Clean Bootstrap for School Events Backend"
echo "================================================================="

# 1. Environment file setup
if [ ! -f ".env" ]; then
    echo "[1/7] Creating .env from .env.example..."
    cp .env.example .env
else
    echo "[1/7] .env already exists. Preserving."
fi

# 2. Build and start Docker containers
echo "[2/7] Building and starting Docker containers..."
docker compose up -d --build

# Wait for database and redis health
echo "[3/7] Waiting for containers to become healthy..."
sleep 10

# 3. Install Composer dependencies inside container
echo "[4/7] Installing Composer dependencies inside app container..."
docker compose exec -T app composer install --no-interaction --prefer-dist --optimize-autoloader

# 4. Generate Application Key ONLY if not already set
echo "[5/7] Checking APP_KEY..."
if grep -q '^APP_KEY=.\+' .env; then
    echo "  APP_KEY already exists. Preserving existing key."
else
    echo "  APP_KEY is missing or empty. Generating new key..."
    docker compose exec -T app php artisan key:generate --force
fi

# 5. Execute migrations on primary and test databases
echo "[6/7] Running database migrations..."
docker compose exec -T app php artisan migrate --force
docker compose exec -T -e DB_DATABASE=school_events_test app php artisan migrate --force

# Optional demo seeder (strictly requires explicit --seed flag, never runs by default or with || true)
if [ "$1" = "--seed" ] || [ "$1" = "--demo-data" ]; then
    echo "Explicit --seed flag passed. Seeding demo dataset into primary database..."
    docker compose exec -T app php artisan db:seed --force
else
    echo "Demo seeder skipped (pass --seed to run demo dataset)."
fi

# 6. Run test suite
echo "[7/7] Running PHPUnit test suite against isolated school_events_test..."
docker compose exec -T -e APP_ENV=testing app php artisan test

echo "================================================================="
echo "Bootstrap completed successfully! Backend is available at http://localhost:8000"
echo "Health probe: http://localhost:8000/health"
echo "================================================================="
