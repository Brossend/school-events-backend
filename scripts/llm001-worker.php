<?php

declare(strict_types=1);
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
$db = DB::selectOne('select current_database() as db,current_user as role');
$run = getenv('LLM001_RUN');
$queue = config('queue.connections.redis.queue');
if (! app()->environment('testing') || $db->db !== 'school_events_test' || $db->role !== 'school_test_user'
    || ! preg_match('/^[a-f0-9]{10}$/', (string) $run) || $queue !== 'llm001-'.$run || config('queue.default') !== 'redis'
    || config('llm.provider') !== 'litellm' || config('llm.timeout') > 120 || config('queue.connections.redis.retry_after') <= 600) {
    throw new RuntimeException('LLM001 isolated worker refused configuration');
}
$dir = storage_path('app/llm001-'.$run);
if (! is_dir($dir)) {
    mkdir($dir, 0770, true);
}
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
    'provider' => config('llm.provider'), 'model' => config('llm.model'), 'secretPresent' => ! empty(config('llm.api_key')),
    'httpBudget' => config('llm.timeout'), 'generationJobTimeout' => 300, 'comparisonJobTimeout' => 600,
    'workerTimeout' => 600, 'retryAfter' => config('queue.connections.redis.retry_after')])."\n";
exit($kernel->call('queue:work', ['connection' => 'redis', '--queue' => $queue, '--tries' => 1, '--timeout' => 600, '--sleep' => 1, '--max-time' => 1800, '--stop-when-empty' => in_array('--drain', $argv, true)]));
