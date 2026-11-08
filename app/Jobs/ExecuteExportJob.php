<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ExportJob;
use App\Models\IdempotencyKey;
use App\Services\Export\ScenarioExportService;
use App\Services\IdempotencyService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ExecuteExportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;
    public int $timeout = 120;

    public function __construct(
        public readonly string $exportJobId,
        public readonly ?string $idempotencyKeyId = null
    ) {}

    public function handle(
        ScenarioExportService $exportService,
        IdempotencyService $idempotencyService
    ): void {
        $job = ExportJob::find($this->exportJobId);
        if (! $job || $job->status !== 'queued') {
            return;
        }

        try {
            $artifact = $exportService->export($job);

            if ($this->idempotencyKeyId) {
                $idempotencyRecord = IdempotencyKey::find($this->idempotencyKeyId);
                if ($idempotencyRecord) {
                    $idempotencyService->markCompleted($idempotencyRecord, 202, [
                        'data' => [
                            'id' => $job->id,
                            'status' => 'ready',
                            'download_url' => "/api/v1/export-jobs/{$job->id}/download",
                            'file_name' => $artifact->file_name,
                            'file_size_bytes' => $artifact->file_size_bytes,
                        ],
                    ]);
                }
            }
        } catch (Throwable $e) {
            Log::error("ExecuteExportJob {$this->exportJobId} failed: ".$e->getMessage(), [
                'exception' => $e,
            ]);

            if ($this->idempotencyKeyId) {
                $idempotencyRecord = IdempotencyKey::find($this->idempotencyKeyId);
                if ($idempotencyRecord) {
                    $idempotencyService->markFailed($idempotencyRecord, 500, [
                        'error' => 'EXPORT_FAILED',
                        'message' => $e->getMessage(),
                    ]);
                }
            }

            throw $e;
        }
    }
}
