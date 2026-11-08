<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ScenarioRevision;
use App\Services\Moderation\ModerationEngineService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class RunVerificationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;
    public int $timeout = 60;

    public function __construct(
        public readonly string $revisionId,
        public readonly ?string $verificationRunId = null
    ) {}

    public function handle(ModerationEngineService $engine): void
    {
        $revision = ScenarioRevision::find($this->revisionId);
        if (! $revision) {
            Log::warning("RunVerificationJob: ScenarioRevision {$this->revisionId} not found.");
            return;
        }

        try {
            $engine->verifyRevision($revision, $this->verificationRunId);
        } catch (Throwable $e) {
            Log::error("RunVerificationJob failed for revision {$this->revisionId}: ".$e->getMessage(), [
                'exception' => $e,
            ]);
            // The service already updates state to failed_engine
            throw $e;
        }
    }
}
