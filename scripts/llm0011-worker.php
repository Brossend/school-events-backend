<?php

declare(strict_types=1);
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
$db = DB::selectOne('select current_database() as db,current_user as role');
$run = getenv('LLM0011_RUN');
$queue = config('queue.connections.redis.queue');
if (! app()->environment('testing') || $db->db !== 'school_events_test' || $db->role !== 'school_test_user'
    || ! preg_match('/^[a-f0-9]{10}$/', (string) $run) || $queue !== 'llm0011-'.$run || config('queue.default') !== 'redis'
    || config('llm.provider') !== 'litellm' || config('llm.timeout') > 240 || config('queue.connections.redis.retry_after') <= 840) {
    throw new RuntimeException('LLM0011 isolated worker refused configuration');
}
$dir = storage_path('app/llm0011-'.$run);
if (! is_dir($dir)) {
    mkdir($dir, 0770, true);
}
touch($dir.'/gateway-requests.jsonl');
Event::listen(RequestSending::class, function ($event) use ($dir) {
    if (str_starts_with($event->request->url(), rtrim(config('llm.base_url'), '/').'/')) {
        file_put_contents($dir.'/gateway-requests.jsonl', json_encode([
            'time' => now()->toIso8601String(), 'method' => $event->request->method(),
            'path' => parse_url($event->request->url(), PHP_URL_PATH),
            'model' => $event->request['model'] ?? null, 'transaction_level' => DB::transactionLevel(),
            'max_http_timeout' => config('llm.timeout'), 'total_budget' => config('llm.total_timeout'),
            'repair_passes' => config('llm.repair_passes'),
        ])."\n", FILE_APPEND | LOCK_EX);
    }
});
Event::listen(ResponseReceived::class, function ($event) use ($dir) {
    if (! str_starts_with($event->request->url(), rtrim(config('llm.base_url'), '/').'/')) {
        return;
    }
    $body = $event->response->json();
    $record = ['time' => now()->toIso8601String(), 'method' => $event->request->method(), 'path' => parse_url($event->request->url(), PHP_URL_PATH),
        'status' => $event->response->status(), 'requested_model' => $event->request['model'] ?? null,
        'response_model' => $body['model'] ?? null, 'usage' => $body['usage'] ?? null,
        'finish_reason' => $body['choices'][0]['finish_reason'] ?? null, 'transaction_level' => DB::transactionLevel()];
    file_put_contents($dir.'/gateway-http.jsonl', json_encode($record, JSON_UNESCAPED_UNICODE)."\n", FILE_APPEND | LOCK_EX);
});
echo json_encode(['environment' => app()->environment(), 'database' => $db->db, 'role' => $db->role, 'queue' => $queue,
    'repairPasses' => config('llm.repair_passes'), 'totalBudget' => config('llm.total_timeout'),
    'provider' => config('llm.provider'), 'model' => config('llm.model'), 'secretPresent' => ! empty(config('llm.api_key')),
    'httpBudget' => config('llm.timeout'), 'generationJobTimeout' => 420, 'comparisonJobTimeout' => 840,
    'workerTimeout' => 840, 'retryAfter' => config('queue.connections.redis.retry_after')])."\n";
exit($kernel->call('queue:work', ['connection' => 'redis', '--queue' => $queue, '--tries' => 1, '--timeout' => 840, '--sleep' => 1, '--max-time' => 3600, '--stop-when-empty' => in_array('--drain', $argv, true)]));
