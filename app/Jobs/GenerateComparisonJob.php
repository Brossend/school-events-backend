<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Llm\ComparisonGenerationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateComparisonJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 840;

    public function __construct(public readonly string $comparisonId) {}

    public function handle(ComparisonGenerationService $service): void
    {
        $service->execute($this->comparisonId);
    }

    public function failed(?\Throwable $e): void
    {
        app(ComparisonGenerationService::class)->fail($this->comparisonId);
    }
}
