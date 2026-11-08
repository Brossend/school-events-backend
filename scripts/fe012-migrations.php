<?php

declare(strict_types=1);
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
use App\Models\School;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

$db = DB::selectOne('select current_database() as db,current_user as role');
if (! app()->environment('testing') || $db->db !== 'school_events_test' || $db->role !== 'school_test_user') {
    throw new RuntimeException('Isolated DB required');
}
$before = DB::selectOne('select count(*) as n from public.migrations')->n;
$run = bin2hex(random_bytes(5));
$schemas = [];
$results = [];
try {
    foreach (['fresh', 'upgrade'] as $mode) {
        $schema = 'fe012_migration_'.$run.'_'.$mode;
        $schemas[] = $schema;
        DB::statement('CREATE SCHEMA "'.$schema.'"');
        config(['database.connections.pgsql.search_path' => $schema]);
        DB::purge('pgsql');
        if (DB::selectOne('select current_schema() as schema')->schema !== $schema) {
            throw new RuntimeException('Schema isolation failed');
        }
        if ($mode === 'fresh') {
            if (Artisan::call('migrate:fresh', ['--force' => true, '--database' => 'pgsql']) !== 0) {
                throw new RuntimeException('Fresh failed');
            }
        } else {
            $paths = glob(base_path('database/migrations/*.php'));
            sort($paths);
            if (Artisan::call('migrate', ['--force' => true, '--database' => 'pgsql', '--path' => array_slice($paths, 0, 6), '--realpath' => true]) !== 0) {
                throw new RuntimeException('Initial migration failed');
            }
            $school = School::create(['name' => 'FE012 upgrade sentinel', 'region_code' => '66', 'city' => 'Test', 'settings' => []]);
            $id = $school->id;
            if (Artisan::call('migrate', ['--force' => true, '--database' => 'pgsql']) !== 0) {
                throw new RuntimeException('Upgrade failed');
            }
            if (! School::whereKey($id)->exists()) {
                throw new RuntimeException('Upgrade lost sentinel');
            }
        }
        $results[$mode] = ['exitCode' => 0, 'schema' => $schema, 'migrations' => DB::table('migrations')->count(), 'sentinelPreserved' => $mode === 'upgrade' ? true : null];
    }
} finally {
    config(['database.connections.pgsql.search_path' => 'public']);
    DB::purge('pgsql');
    foreach ($schemas as $schema) {
        if (! preg_match('/^fe012_migration_[a-f0-9]{10}_(fresh|upgrade)$/', $schema)) {
            throw new RuntimeException('Unsafe schema');
        }DB::statement('DROP SCHEMA "'.$schema.'" CASCADE');
    }
}
$after = DB::selectOne('select count(*) as n from public.migrations')->n;
if ($before !== $after) {
    throw new RuntimeException('Public migration ledger changed');
}
echo json_encode(['database' => $db->db, 'role' => $db->role, 'results' => $results, 'temporarySchemasRemoved' => true, 'publicMigrationCountBefore' => $before, 'publicMigrationCountAfter' => $after], JSON_PRETTY_PRINT);
