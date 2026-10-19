<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class AuditVerificationJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $message = 'Verification of Redis queue execution for BE-001 audit'
    ) {}

    public function handle(): void
    {
        Log::info('AUDIT_QUEUE_VERIFICATION_SUCCESS: ' . $this->message, [
            'dispatched_at' => now()->toIso8601String(),
            'connection' => config('queue.default'),
        ]);
    }
}
