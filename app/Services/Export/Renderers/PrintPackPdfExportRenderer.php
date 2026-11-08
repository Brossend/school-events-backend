<?php

declare(strict_types=1);

namespace App\Services\Export\Renderers;

use App\Models\ExportJob;
use App\Models\ScenarioRevision;
use Barryvdh\DomPDF\Facade\Pdf;

class PrintPackPdfExportRenderer implements ExportRendererInterface
{
    public function render(ScenarioRevision $revision, ExportJob $job, array $viewData): array
    {
        $scenario = $revision->scenario;

        $viewData['exportJob'] = $job;

        $pdf = Pdf::loadView('pdf.print_pack', $viewData);
        $pdf->setPaper('a4', 'portrait');
        $content = $pdf->output();

        $fileName = "scenario-print-pack-{$scenario->id}-v{$revision->version_number}.pdf";

        return [
            'content' => $content,
            'file_name' => $fileName,
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
        ];
    }
}
