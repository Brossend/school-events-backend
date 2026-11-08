<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\CalendarEvent;
use App\Models\GenerationRun;
use App\Models\Scenario;
use App\Models\ScenarioRequest;
use App\Models\School;
use App\Models\User;
use App\Services\ContextSnapshotBuilder;
use App\Services\Llm\GenerationOutputValidator;
use App\Services\Llm\LlmProviderInterface;
use App\Services\Llm\ScenarioSemanticValidator;
use App\Services\Llm\ServerNormalizationService;
use App\Services\ScenarioPersistenceService;

echo "--- START TEST CONTOURS ---\n";

$user = User::first();
$school = School::first();

if (! $user || ! $school) {
    echo "User or school not found. Please seed database.\n";
    exit(1);
}

// Find a calendar event with sources/claims if possible
$event = CalendarEvent::with(['sources', 'culturalClaims.evidenceRecords'])->first();

$input = [
    'calendar_event_id' => $event?->id,
    'custom_event_title' => $event ? null : 'Праздник первого звонка',
    'scale' => 'class_level',
    'format_type' => 'class_hour',
    'target_grades' => [5, 6],
    'participants_count' => 28,
    'duration_minutes' => 45,
    'value_direction' => 'labor_and_creation',
    'family_involvement' => false,
    'available_resources' => ['Проектор', 'Цветная бумага'],
    'pedagogical_constraints' => 'Использовать интерактивные формы',
];

$builder = app(ContextSnapshotBuilder::class);
$snapshot = $builder->buildSnapshot($user, $school, $input);

echo "1. Context snapshot built successfully. Occasion: " . $snapshot['occasion']['title'] . "\n";
echo "   Cultural claims in snapshot: " . count($snapshot['cultural_provenance']['claims']) . "\n";

try {
$request = ScenarioRequest::create([
    'school_id' => $school->id,
    'user_id' => $user->id,
    'calendar_event_id' => $input['calendar_event_id'],
    'custom_event_title' => $input['custom_event_title'],
    'scale' => $input['scale'],
    'format_type' => $input['format_type'],
    'target_grades' => $input['target_grades'],
    'participants_count' => $input['participants_count'],
    'duration_minutes' => $input['duration_minutes'],
    'value_direction' => $input['value_direction'],
    'family_involvement' => $input['family_involvement'],
    'available_resources' => $input['available_resources'],
    'pedagogical_constraints' => $input['pedagogical_constraints'],
    'context_snapshot' => $snapshot,
]);

$llm = app(LlmProviderInterface::class);
$genResult = $llm->generate($request);
$rawOutput = $genResult['output_payload'];

echo "2. Raw LLM output generated. Concept: " . mb_substr($rawOutput['concept'], 0, 50) . "...\n";
echo "   Stages count: " . count($rawOutput['stages']) . "\n";

$v1 = app(GenerationOutputValidator::class);
$v1->validate($rawOutput);
echo "3. Contour 1 (JSON Schema Draft 2020-12) PASSED.\n";

$v2 = app(ScenarioSemanticValidator::class);
$v2->validate($rawOutput, $snapshot);
echo "4. Contour 2 (Semantic & Pedagogical Validation) PASSED.\n";

$v3 = app(ServerNormalizationService::class);
$normalized = $v3->normalize($rawOutput, $snapshot);
echo "5. Contour 3 (Server Normalization & Schema Validation) PASSED.\n";
echo "   Normalized cultural claims: " . count($normalized['cultural_claims']) . "\n";

$run = GenerationRun::create([
    'scenario_request_id' => $request->id,
    'provider_name' => \App\Services\Llm\MockLlmProvider::PROVIDER_NAME,
    'model_identifier' => \App\Services\Llm\MockLlmProvider::MODEL_IDENTIFIER,
    'prompt_version' => \App\Services\Llm\MockLlmProvider::PROMPT_VERSION,
    'schema_version' => \App\Services\Llm\MockLlmProvider::SCHEMA_VERSION,
    'status' => 'queued',
    'attempts' => 0,
]);

$persister = app(ScenarioPersistenceService::class);
$scenario = $persister->persist($request, $run, $normalized);

echo "6. ScenarioPersistenceService PASSED!\n";
echo "   Scenario ID: {$scenario->id}\n";
echo "   Current Revision ID: {$scenario->current_revision_id}\n";
echo "   Status: {$scenario->status}\n";
echo "   Run Status: {$run->fresh()->status}\n";
echo "   Stages created in DB: " . $scenario->currentRevision->stages()->count() . "\n";
echo "   Materials created in DB: " . $scenario->currentRevision->materials()->count() . "\n";
echo "   Moderation status: " . $scenario->currentRevision->moderationState->status . "\n";

echo "--- ALL 6 CONTOURS & PERSISTENCE VERIFIED SUCCESSFULLY ---\n";
} catch (\Throwable $e) {
    echo "ERROR OCCURRED:\n";
    echo $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
