# PowerShell Bootstrap Script for School Events Backend (BE-002)
# Prerequisites: Docker Desktop running on Windows with Linux containers
param (
    [switch]$Seed
)

$ErrorActionPreference = "Stop"

function Check-ExitCode([string]$stepName) {
    if ($LASTEXITCODE -ne 0) {
        Write-Error "FATAL: Command failed at step '$stepName' with exit code $LASTEXITCODE"
        exit $LASTEXITCODE
    }
}

Write-Host "=================================================================" -ForegroundColor Cyan
Write-Host "Starting Clean Bootstrap for School Events Backend (BE-002)" -ForegroundColor Cyan
Write-Host "=================================================================" -ForegroundColor Cyan

# 1. Environment file setup
if (-not (Test-Path ".env")) {
    Write-Host "[1/7] Creating .env from .env.example..." -ForegroundColor Yellow
    Copy-Item ".env.example" ".env"
} else {
    Write-Host "[1/7] .env already exists. Preserving." -ForegroundColor Green
}

# 2. Build and start Docker containers
Write-Host "[2/7] Building and starting Docker containers..." -ForegroundColor Yellow
docker compose up -d --build
Check-ExitCode "Docker Compose Up"

# Wait for database and redis health
Write-Host "[3/7] Waiting for containers to become healthy..." -ForegroundColor Yellow
Start-Sleep -Seconds 10

# 3. Install Composer dependencies inside container
Write-Host "[4/7] Installing Composer dependencies inside app container..." -ForegroundColor Yellow
docker compose exec -T app composer install --no-interaction --prefer-dist --optimize-autoloader
Check-ExitCode "Composer Install"

# 4. Generate Application Key ONLY if missing or empty
Write-Host "[5/7] Checking APP_KEY..." -ForegroundColor Yellow
$envContent = Get-Content ".env" -Raw
if ($envContent -match "APP_KEY=base64:[A-Za-z0-9+/=]+" -or $envContent -match "APP_KEY=\S{10,}") {
    Write-Host "  APP_KEY already configured. Preserving existing key." -ForegroundColor Green
} else {
    Write-Host "  APP_KEY missing or empty. Generating key..." -ForegroundColor Yellow
    docker compose exec -T app php artisan key:generate --force
    Check-ExitCode "Key Generation"
}

# 5. Execute migrations on primary and test databases
Write-Host "[6/7] Running database migrations..." -ForegroundColor Yellow
docker compose exec -T app php artisan migrate --force
Check-ExitCode "Primary Database Migration"

docker compose exec -T -e DB_DATABASE=school_events_test app php artisan migrate --force
Check-ExitCode "Test Database Migration"

# Optional demo seeder (strictly executed ONLY with explicit -Seed switch, no default execution)
if ($Seed) {
    Write-Host "Explicit -Seed switch provided. Seeding demo dataset into primary database..." -ForegroundColor Yellow
    docker compose exec -T app php artisan db:seed --force
    Check-ExitCode "Database Seeder"
} else {
    Write-Host "Demo seeder skipped (use -Seed to populate demo schools and users)." -ForegroundColor Gray
}

# 6. Run test suite
Write-Host "[7/7] Running PHPUnit test suite against isolated school_events_test..." -ForegroundColor Yellow
docker compose exec -T -e APP_ENV=testing app php artisan test
Check-ExitCode "PHPUnit Test Suite"

Write-Host "=================================================================" -ForegroundColor Green
Write-Host "Bootstrap completed successfully! Backend is available at http://localhost:8000" -ForegroundColor Green
Write-Host "Health probe: http://localhost:8000/health" -ForegroundColor Green
Write-Host "=================================================================" -ForegroundColor Green
