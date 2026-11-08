<?php

declare(strict_types=1);
use App\Services\Llm\PedagogicalQualityGate;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$db = DB::selectOne('select current_database() as db,current_user as role');
if (! app()->environment('testing') || $db->db !== 'school_events_test' || $db->role !== 'school_test_user') {
    throw new RuntimeException('Test scope required');
}
$gate = app(PedagogicalQualityGate::class);
$reports = [];
foreach (['class', 'school', 'family', 'cultural-b'] as $name) {
    $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/llm0011/'.$name.'.json')), true);
    $reports[$name] = ['origin' => $fixture['origin'], 'run_id' => $fixture['run_id'], 'original_prompt_version' => $fixture['prompt_version'], 'quality' => $gate->assess($fixture['payload'])];
}
$old = json_decode(file_get_contents(base_path('tests/Fixtures/llm0011/class.json')), true)['payload'];
$fixed = $old;
$fixed['materials']['printable_cards'][0]['back_text'] = 'Максимум 7 предметов: шесть открыток и один сувенир. Проверка: 6*15=90, 90+30=120. Восемь предметов при условии хотя бы одного сувенира купить невозможно.';
$fixed['materials']['printable_cards'][1]['back_text'] = 'Успеть невозможно: необходимо 135 минут при доступных 120; дефицит составляет 15 минут. Обсудите перенос части работы, а не обещайте невозможное.';
$reports['corrected_regression_copy'] = ['origin' => 'Deterministic corrected COPY, not a live LLM result or mutation of old revision', 'quality' => $gate->assess($fixed)];
echo json_encode(['database' => $db->db, 'gate_version' => $gate::VERSION, 'reports' => $reports], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
