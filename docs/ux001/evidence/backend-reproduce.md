# UX-001 backend suite reproduction

Recorded PASS: **455 tests, 2242 assertions, zero errors/failures/skipped**,
PHPUnit elapsed **1:46.807**. PHP 8.4.27, PHPUnit 12.5.40.
Backend branch: feature/ux-001-teacher-experience.

## Exact successful invocation (PowerShell)

```powershell
Set-Location -LiteralPath 'C:/Users/bross/WebstormProjects/school'
docker exec -w /tmp/ux001-review school-app-test-1 php vendor/bin/phpunit --colors=never --testdox --log-junit /tmp/backend-full-suite.xml 2>&1 | Tee-Object -FilePath docs/ux001/evidence/backend-full-suite.log
$testExit = $LASTEXITCODE
docker cp school-app-test-1:/tmp/backend-full-suite.xml docs/ux001/evidence/backend-full-suite.xml
if ($testExit -ne 0) { throw "Backend suite failed: $testExit" }
```

The existing successful snapshot uses database school_events_test and
PostgreSQL search_path ux001_backend_review_62a29e01362a, set only in
/tmp/ux001-review/config/database.php. The workspace config and API public
schema were unchanged. Migrations were applied and committed BEFORE PHPUnit.
This matters because DatabaseTransactions otherwise rolls back migrations
created during the first test, while TestCase retains its static migrated flag.

## Environment (no secrets)

The exact invocation inherited the existing test container environment and
used the copied private .env.testing file. No credentials appeared in command
arguments or output. Database host: postgres:5432. Existing container/test
configuration supplies APP_KEY and database credentials.

phpunit.xml selects APP_ENV=testing, DB_CONNECTION=pgsql,
DB_DATABASE=school_events_test, LLM_PROVIDER=mock, BCRYPT_ROUNDS=4,
CACHE_STORE=array, QUEUE_CONNECTION=sync, SESSION_DRIVER=file,
SESSION_COOKIE=laravel_session, MAIL_MAILER=array, BROADCAST_CONNECTION=null.
Tests/TestCase verifies both configured and actual database name and forces
mock generation, synchronous queue and array cache.

## Rebuilding an equivalent isolated snapshot

Use a new /tmp/ux001-reproduce path; this preparation refuses to overwrite it.
Python, Docker and the existing school-app-test-1 container are prerequisites.
The archive includes vendor and may take several minutes on Windows.
Run suites sequentially. This does not reconfigure running Docker services.

```powershell
Set-Location -LiteralPath 'C:/Users/bross/WebstormProjects/school'
$taskTemp = 'C:/Users/bross/.codex/ux001'
New-Item -ItemType Directory -Force -Path $taskTemp | Out-Null
@'
import tarfile
from pathlib import Path
root = Path.cwd()
with tarfile.open('C:/Users/bross/.codex/ux001/backend-reproduce.tar', 'w') as archive:
    for name in ['app', 'bootstrap', 'config', 'database', 'routes', 'resources',
                 'tests', 'vendor', 'public', 'artisan', 'composer.json',
                 'composer.lock', 'phpunit.xml', '.env.testing']:
        archive.add(root / name, arcname=name)
    for path in (root / 'docs').rglob('*'):
        if path.is_file() and 'evidence' not in path.relative_to(root / 'docs').parts:
            archive.add(path, arcname=str(path.relative_to(root)))
'@ | python -
if ($LASTEXITCODE -ne 0) { throw 'Archive failed' }
docker cp "$taskTemp/backend-reproduce.tar" school-app-test-1:/tmp/backend-reproduce.tar
if ($LASTEXITCODE -ne 0) { throw 'Archive copy failed' }
docker exec school-app-test-1 sh -c 'test ! -e /tmp/ux001-reproduce && mkdir /tmp/ux001-reproduce && tar -xf /tmp/backend-reproduce.tar -C /tmp/ux001-reproduce && mkdir -p /tmp/ux001-reproduce/storage/framework/cache/data /tmp/ux001-reproduce/storage/framework/sessions /tmp/ux001-reproduce/storage/framework/views /tmp/ux001-reproduce/storage/logs && rm -f /tmp/ux001-reproduce/bootstrap/cache/config.php'
if ($LASTEXITCODE -ne 0) { throw 'Snapshot preparation failed (path may already exist)' }

@'
<?php
chdir('/tmp/ux001-reproduce');
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!app()->environment('testing')
    || config('database.connections.pgsql.database') !== 'school_events_test'
    || Illuminate\Support\Facades\DB::selectOne('SELECT current_database() AS db')->db !== 'school_events_test') {
    throw new RuntimeException('Unsafe environment/database');
}
$schema = 'ux001_backend_review_'.bin2hex(random_bytes(6));
$path = 'config/database.php';
$source = file_get_contents($path);
$source = str_replace("'search_path' => 'public'", "'search_path' => '".$schema."'", $source, $count);
if ($count !== 1) { throw new RuntimeException('Unexpected database config'); }
Illuminate\Support\Facades\DB::statement('CREATE SCHEMA '.$schema);
file_put_contents($path, $source);
file_put_contents('/tmp/ux001-reproduce-schema', $schema);
echo 'Verified testing / school_events_test; isolated schema: '.$schema.PHP_EOL;
'@ | Set-Content -LiteralPath "$taskTemp/prepare-reproduce.php" -Encoding utf8
docker cp "$taskTemp/prepare-reproduce.php" school-app-test-1:/tmp/prepare-reproduce.php
if ($LASTEXITCODE -ne 0) { throw 'Preparation script copy failed' }
docker exec -e APP_ENV=testing -e DB_CONNECTION=pgsql -e DB_DATABASE=school_events_test school-app-test-1 php /tmp/prepare-reproduce.php
if ($LASTEXITCODE -ne 0) { throw 'Environment/schema guard failed' }
docker exec -w /tmp/ux001-reproduce -e APP_ENV=testing -e DB_CONNECTION=pgsql -e DB_DATABASE=school_events_test school-app-test-1 php artisan migrate --force --no-interaction
if ($LASTEXITCODE -ne 0) { throw 'Pre-test migrations failed' }
```

Then use the exact full-suite invocation above, substituting
**-w /tmp/ux001-reproduce** for **-w /tmp/ux001-review**. Choose different
evidence filenames to preserve the recorded PASS logs. No migrate:fresh,
table truncation or fixture deletion is required. Schema is retained for inspection.

The snapshot MUST include docs JSON Schema files and docs/openapi.yaml: they
are runtime inputs to generation and contract tests. Their initial omission
caused a discarded failed run; the preserved final PASS includes these files.

## Focused checks

In the prepared snapshot, run sequentially using the same Docker exec prefix:

```text
php vendor/bin/phpunit --colors=never --testdox tests/Feature/RememberLoginTest.php tests/Feature/SchoolInvitationTest.php
php vendor/bin/phpunit --colors=never --testdox tests/Feature/PracticeCalendarTest.php
php vendor/bin/phpunit --colors=never --testdox --log-junit /tmp/backend-full-suite.xml
```

Recorded focused: 28 tests / 133 assertions; calendar after isolation fix:
17 tests / 198 assertions. Both groups also pass in the final full suite.
Fresh/upgrade migration tests use real PostgreSQL temporary schemas.
Registration concurrency uses two real PHP workers with a synchronized start;
both same-invitation and same-email cases assert 201+409, exactly one membership
and one consumed invitation. Zero skipped elements verified in JUnit XML.

Five changed PHP files matched SHA256 between workspace and successful snapshot:
AuthController.php, LoginRequest.php, RememberLoginTest.php,
SchoolInvitationTest.php and PracticeCalendarTest.php. Scoped Pint passed.
Parent runs the required workspace dirty Pint separately.
Browser fixture in public was verified after the final suite without printing
credentials; its private access JSON remains valid. Practice8020 was untouched.
