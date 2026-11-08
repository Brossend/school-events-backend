<?php

declare(strict_types=1);
use App\Models\GenerationRun;
use App\Models\ScenarioRequest;
use App\Models\ScenarioRevision;
use App\Models\School;
use App\Services\Llm\PedagogicalQualityGate;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$db = DB::selectOne('select current_database() as db,current_user as role');
$run = getenv('LLM0011_RUN');
if (! app()->environment('testing') || $db->db !== 'school_events_test' || $db->role !== 'school_test_user' || ! preg_match('/^[a-f0-9]{10}$/', (string) $run)) {
    throw new RuntimeException('Isolated acceptance scope required');
}
$schools = School::where('name', 'like', 'LLM0011 '.$run.' %')->pluck('id');
$requests = ScenarioRequest::whereIn('school_id', $schools)->get();
$samples = [];
foreach (GenerationRun::whereIn('scenario_request_id', $requests->pluck('id'))->get() as $generation) {
    $request = $requests->firstWhere('id', $generation->scenario_request_id);
    $revision = ScenarioRevision::where('source_generation_run_id', $generation->id)->first();
    $samples[] = ['origin' => 'live LiteLLM, not fixture', 'generation_run_id' => $generation->id, 'revision_id' => $revision?->id,
        'provider' => $generation->provider_name, 'model' => $generation->model_identifier, 'prompt_version' => $generation->prompt_version, 'status' => $generation->status,
        'latency_ms' => $generation->latency_ms, 'prompt_tokens' => $generation->prompt_tokens, 'completion_tokens' => $generation->completion_tokens,
        'error_code' => $generation->error_code, 'error_message' => $generation->error_message,
        'input' => $request->only(['scale', 'format_type', 'target_grades', 'participants_count', 'duration_minutes', 'family_involvement', 'available_resources', 'pedagogical_constraints']),
        'output_payload' => $generation->sanitized_response, 'quality_review' => $revision ? app(PedagogicalQualityGate::class)->forRevision($revision) : null,
        'generation_audit' => json_decode($generation->sanitized_prompt ?? 'null', true)];
}
echo json_encode(['database' => $db->db, 'role' => $db->role, 'runId' => $run, 'samples' => $samples], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
