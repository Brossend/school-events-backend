<?php

declare(strict_types=1);

namespace App\Services\Export\Renderers;

use App\Models\ExportJob;
use App\Models\ScenarioRevision;
use Barryvdh\DomPDF\Facade\Pdf;

class PdfExportRenderer implements ExportRendererInterface
{
    public function render(ScenarioRevision $revision, ExportJob $job, array $viewData): array
    {
        $scenario = $revision->scenario;

        $pdf = Pdf::loadView('pdf.scenario', $viewData);
        $pdf->setPaper('a4', 'portrait');
        $content = $pdf->output();

        $fileName = "scenario-{$scenario->id}-v{$revision->version_number}.pdf";

        return [
            'content' => $content,
            'file_name' => $fileName,
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
        ];
    }
}
