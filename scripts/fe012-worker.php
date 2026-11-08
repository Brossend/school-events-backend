<?php

declare(strict_types=1);
use App\Models\ExportJob;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
$db = DB::selectOne('select current_database() as db,current_user as role');
$queue = config('queue.connections.redis.queue');
$run = getenv('FE012_RUN');
if (! app()->environment('testing') || $db->db !== 'school_events_test' || $db->role !== 'school_test_user' || config('queue.default') !== 'redis' || ! preg_match('/^[a-f0-9]{10}$/', (string) $run) || $queue !== 'fe012-'.$run) {
    throw new RuntimeException('Isolated worker guard refused');
}
$dir = storage_path('app/fe012-'.$run);
if (! is_dir($dir)) {
    mkdir($dir, 0770, true);
}
ExportJob::updated(function ($job) use ($run, $dir) {
    if (! $job->wasChanged('status')) {
        return;
    }
    $name = $job->revision?->scenario?->school?->name;
    if (! str_starts_with((string) $name, 'FE012 '.$run.' ')) {
        throw new RuntimeException('Foreign export in isolated queue');
    }
    $actual = DB::table('export_jobs')->where('id', $job->id)->value('status');
    file_put_contents($dir.'/export-transitions.jsonl', json_encode(['job_id' => $job->id, 'status' => $actual, 'time' => microtime(true)])."\n", FILE_APPEND | LOCK_EX);
    // Test-only observation window; no status is injected and all real renderers execute.
    // This makes the committed processing row observable by the real HTTP poller.
    if ($actual === 'processing') {
        usleep(2500000);
    }
});
exit($kernel->call('queue:work', ['connection' => 'redis', '--queue' => $queue, '--sleep' => 1, '--tries' => 1, '--timeout' => 180, '--max-time' => 1800]));
