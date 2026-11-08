<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\PilotService;
use Illuminate\Console\Command;

class DiagnosePilotDatasetCommand extends Command
{
    protected $signature = 'pilot:diagnose-dataset {--format=table : Output format: table or json}';

    protected $description = 'Perform diagnostic inventory of historical pilot dataset provenance';

    public function handle(PilotService $pilotService): int
    {
        $report = $pilotService->diagnoseDataset();

        if ($this->option('format') === 'json') {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return self::SUCCESS;
        }

        $this->info('Starting diagnostic inventory of pilot responses provenance...');

        $this->newLine();
        $this->info("Total responses scanned: {$report['total_responses_scanned']}");
        $this->table(
            ['Category', 'Count'],
            [
                ['Confirmed Synthetic (explicit flag)', $report['summary']['confirmed_synthetic_count']],
                ['Suspected Synthetic (legacy demo heuristic)', $report['summary']['suspected_synthetic_count']],
                ['Unclassified / Requires Review', $report['summary']['unclassified_count'] ?? $report['summary']['requires_review_count'] ?? 0],
                ['Verified Real', $report['summary']['verified_real_count']],
            ]
        );

        $this->newLine();
        $this->warn($report['safety_notice']);

        if (! empty($report['suspected_synthetic'])) {
            $this->newLine();
            $this->comment('Suspected Synthetic Responses Details:');
            $rows = array_map(function ($item) {
                return [
                    $item['response_id'],
                    $item['participant_code'],
                    $item['school_name'],
                    implode(', ', $item['reasons']),
                ];
            }, array_slice($report['suspected_synthetic'], 0, 15));

            $this->table(['Response ID', 'Participant Code', 'School', 'Suspect Indicators'], $rows);

            if (count($report['suspected_synthetic']) > 15) {
                $rem = count($report['suspected_synthetic']) - 15;
                $this->line("... and {$rem} more suspected records.");
            }
        }

        return self::SUCCESS;
    }
}
