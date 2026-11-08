<?php

declare(strict_types=1);
use App\Models\ScenarioRequest;
use App\Services\Llm\GenerationOutputValidator;
use App\Services\Llm\LiteLlmProvider;
use App\Services\Llm\LlmProviderException;
use App\Services\Llm\ScenarioSemanticValidator;
use App\Services\Llm\ServerNormalizationService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$db = DB::selectOne('select current_database() as db, current_user as role');
if (! app()->environment('testing') || $db->db !== 'school_events_test' || $db->role !== 'school_test_user') {
    throw new RuntimeException('Isolated contour required');
}
config(['llm.provider' => 'litellm', 'llm.model' => getenv('LLM001_MODEL') ?: 'GigaChat-3-Pro']);
config(['llm.disable_thinking' => config('llm.model') === 'Qwen3.8-27B']);
$request = new ScenarioRequest([
    'custom_event_title' => 'Синтетический учебный пример: мастерская взаимопомощи',
    'scale' => 'class_level', 'format_type' => 'class_hour', 'value_direction' => 'mutual_aid',
    'target_grades' => [5, 6], 'participants_count' => 30, 'duration_minutes' => 45,
    'family_involvement' => false, 'available_resources' => ['бумага', 'карандаши', 'доска'],
    'pedagogical_constraints' => 'Без экрана и соревнования на скорость. Все персонажи вымышленные.',
    'context_snapshot' => ['occasion' => ['title' => 'Синтетический учебный пример: мастерская взаимопомощи'], 'cultural_provenance' => ['claims' => []],
        'pedagogical_parameters' => ['duration_minutes' => 45]],
]);
$result = [];
try {
    $result = app(LiteLlmProvider::class)->generate($request);
    app(GenerationOutputValidator::class)->validate($result['output_payload']);
    app(ScenarioSemanticValidator::class)->validate($result['output_payload'], 45);
    app(ServerNormalizationService::class)->normalize($result['output_payload'], $request->context_snapshot);
    echo json_encode(['origin' => 'live LiteLLM', 'valid' => true, ...$result], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} catch (LlmProviderException $e) {
    echo json_encode(['valid' => false, 'error_code' => $e->errorCode, 'message' => $e->getMessage(), ...$e->metadata], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit(1);
} catch (ValidationException $e) {
    echo json_encode(['valid' => false, 'validation_errors' => $e->errors(), ...$result], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit(1);
}
