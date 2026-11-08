<?php

declare(strict_types=1);

namespace App\Services\Export\Renderers;

use App\Models\ExportJob;
use App\Models\ScenarioRevision;

interface ExportRendererInterface
{
    /**
     * Render revision data into binary document payload.
     *
     * @param array<string, mixed> $viewData
     * @return array{
     *     content: string,
     *     file_name: string,
     *     mime_type: string,
     *     extension: string
     * }
     */
    public function render(ScenarioRevision $revision, ExportJob $job, array $viewData): array;
}
