<?php

declare(strict_types=1);

namespace App\Services\Export;

use App\Models\ExportJob;
use App\Models\ExportedArtifact;
use App\Models\ScenarioRevision;
use App\Models\TeacherSignoff;
use App\Services\Export\Renderers\DocxExportRenderer;
use App\Services\Export\Renderers\ExportRendererInterface;
use App\Services\Export\Renderers\PdfExportRenderer;
use App\Services\Export\Renderers\PptxExportRenderer;
use App\Services\Export\Renderers\PrintPackPdfExportRenderer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Throwable;

class ScenarioExportService
{
    /**
     * Resolve document renderer strategy by requested export format.
     */
    public function getRenderer(string $format): ExportRendererInterface
    {
        return match ($format) {
            'pdf' => new PdfExportRenderer(),
            'docx' => new DocxExportRenderer(),
            'pptx' => new PptxExportRenderer(),
            'print_pack_pdf' => new PrintPackPdfExportRenderer(),
            default => throw new UnprocessableEntityHttpException("Формат экспорта '{$format}' не поддерживается."),
        };
    }

    /**
     * Compile and export scenario revision into the requested format.
     */
    public function export(ExportJob $exportJob): ExportedArtifact
    {
        $revision = ScenarioRevision::with(['scenario.school', 'stages', 'materials', 'moderationState'])->find($exportJob->revision_id);
        if (! $revision) {
            $exportJob->update([
                'status' => 'failed',
                'error_message' => 'Scenario revision not found.',
                'completed_at' => now(),
            ]);
            throw new UnprocessableEntityHttpException('Ревизия сценария не найдена.');
        }

        $scenario = $revision->scenario;

        // 1. Check current revision constraint (historical export prohibited in MVP)
        if ($scenario->current_revision_id !== $revision->id) {
            $exportJob->update([
                'status' => 'failed',
                'error_message' => 'Export is only supported for the current active revision.',
                'completed_at' => now(),
            ]);
            throw new ConflictHttpException('Экспорт доступен только для текущей активной ревизии сценария.');
        }

        // 2. Check moderation gate: no open P0 violations
        $moderationState = $revision->moderationState;
        if (! $moderationState || in_array($moderationState->status, ['pending_verification', 'running', 'failed_engine', 'verification_blocked'], true) || $moderationState->p0_violations_count > 0) {
            $exportJob->update([
                'status' => 'failed',
                'error_message' => 'Export forbidden: revision has open P0 violations or has not passed moderation.',
                'completed_at' => now(),
            ]);
            throw new AccessDeniedHttpException('Экспорт невозможен: ревизия содержит открытые блокирующие нарушения (P0) или не завершила модерацию.');
        }

        // 3. Check human teacher sign-off gate
        $anySignoff = TeacherSignoff::with('user')
            ->where('revision_id', $revision->id)
            ->where('is_confirmed', true)
            ->orderByDesc('confirmed_at')
            ->orderByDesc('created_at')
            ->first();

        if (! $anySignoff) {
            $exportJob->update([
                'status' => 'failed',
                'error_message' => 'Export forbidden: teacher sign-off required.',
                'completed_at' => now(),
            ]);
            throw new AccessDeniedHttpException('Экспорт невозможен: отсутствует обязательное подтверждение сценария педагогом (sign-off).');
        }

        // 3.b Live Provenance Revocation Gate & Fingerprint Integrity Gate
        $provenanceService = app(\App\Services\ProvenanceVerificationService::class);
        $provenanceCheck = $provenanceService->verifyRevisionProvenanceAuthenticity($revision);
        if (! $provenanceCheck['valid']) {
            $revision->moderationState()->updateOrCreate(
                ['revision_id' => $revision->id],
                [
                    'status' => 'verification_blocked',
                    'p0_violations_count' => max(1, $moderationState?->p0_violations_count ?? 1),
                    'updated_at' => now(),
                ]
            );

            $exportJob->update([
                'status' => 'failed',
                'error_message' => 'Экспорт заблокирован: рецензия на первоисточник была отозвана или отклонена куратором.',
                'completed_at' => now(),
            ]);

            throw new AccessDeniedHttpException('Экспорт заблокирован: рецензия на первоисточник была отозвана или отклонена куратором.');
        }

        $currentFingerprint = $provenanceService->computeRevisionProvenanceFingerprint($revision);

        // Moderation reverification gate: must have non-empty matching fingerprint
        if (empty($moderationState->provenance_fingerprint) || $moderationState->provenance_fingerprint !== $currentFingerprint) {
            $exportJob->update([
                'status' => 'failed',
                'error_message' => 'Экспорт запрещён: доказательная база первоисточников изменилась после модерации либо отсутствует цифровой отпечаток проверки. Требуется повторная модерация.',
                'completed_at' => now(),
            ]);

            throw new AccessDeniedHttpException('Экспорт запрещён: требуется повторная модерация ревизии под актуальную доказательную базу.');
        }

        // Sign-off fingerprint gate: must have non-empty matching fingerprint
        $signoff = TeacherSignoff::with('user')
            ->where('revision_id', $revision->id)
            ->where('is_confirmed', true)
            ->where('provenance_fingerprint', $currentFingerprint)
            ->orderByDesc('confirmed_at')
            ->orderByDesc('created_at')
            ->first();

        if (! $signoff || empty($signoff->provenance_fingerprint)) {
            $exportJob->update([
                'status' => 'failed',
                'error_message' => 'Экспорт запрещён: согласование педагога устарело либо не содержит цифрового отпечатка доказательной базы.',
                'completed_at' => now(),
            ]);

            throw new AccessDeniedHttpException('Экспорт запрещён: согласование педагога устарело либо не содержит цифрового отпечатка доказательной базы.');
        }

        // Export job fingerprint gate: must have non-empty matching fingerprint
        if (empty($exportJob->provenance_fingerprint) || $exportJob->provenance_fingerprint !== $currentFingerprint) {
            $exportJob->update([
                'status' => 'failed',
                'error_message' => 'Экспорт запрещён: цифровой отпечаток задания на экспорт не соответствует актуальной доказательной базе.',
                'completed_at' => now(),
            ]);

            throw new AccessDeniedHttpException('Экспорт запрещён: отпечаток задания на экспорт не соответствует актуальной доказательной базе.');
        }

        // 4. Update job to processing
        $exportJob->update([
            'status' => 'processing',
        ]);

        try {
            // Context & occasions
            $payload = $revision->content_payload ?? [];
            $contextSnapshot = $scenario->scenarioRequest?->context_snapshot ?? [];
            $occasionTitle = $contextSnapshot['occasion']['title'] ?? ($contextSnapshot['occasion_title'] ?? $scenario->title);

            $claims = $payload['cultural_claims'] ?? ($payload['cultural_context']['verified_claims'] ?? []);

            $viewData = [
                'scenario' => $scenario,
                'revision' => $revision,
                'stages' => $revision->stages,
                'materials' => $revision->materials,
                'materialsPayload' => $payload['materials'] ?? [],
                'presentationSlides' => $payload['materials']['presentation_slides'] ?? [],
                'printableCards' => $payload['materials']['printable_cards'] ?? [],
                'handouts' => $payload['materials']['handouts'] ?? [],
                'props' => $payload['props'] ?? [],
                'valueDirection' => $payload['value_direction'] ?? '',
                'claims' => $claims,
                'signoff' => $signoff,
                'occasionTitle' => $occasionTitle,
                'pedagogicalTasks' => $revision->pedagogical_tasks ?? ($payload['pedagogical_tasks'] ?? []),
                'expectedOutcomes' => $revision->expected_outcomes ?? ($payload['expected_outcomes'] ?? []),
                'roles' => $payload['roles'] ?? [],
                'activities' => $payload['activities'] ?? [],
                'preparationChecklist' => $payload['preparation_checklist'] ?? [],
                'teacherInstructions' => $revision->teacher_instructions ?? ($payload['teacher_instructions'] ?? []),
                'recommendations' => $payload['teacher_instructions']['methodological_recommendations'] ?? ($payload['methodological_recommendations'] ?? ($payload['recommendations'] ?? [])),
                'safetyRules' => $payload['teacher_instructions']['general_safety_rules'] ?? ($payload['safety_notes'] ?? ($payload['safety_and_adaptation']['rules'] ?? [])),
                'adaptation' => $payload['teacher_instructions']['adaptation_for_disabilities_or_small_groups'] ?? ($payload['adaptation'] ?? ($payload['safety_and_adaptation']['adaptation'] ?? null)),
                'safetyAndAdaptation' => $payload['safety_and_adaptation'] ?? ($payload['safety_notes'] ?? ($payload['adaptation'] ?? [])),
                'targetAudience' => $revision->target_audience ?? ($payload['target_audience'] ?? null),
            ];

            // Render document via format-specific strategy
            $renderer = $this->getRenderer($exportJob->format);
            $rendered = $renderer->render($revision, $exportJob, $viewData);

            $fileBytes = $rendered['content'];
            $sha256 = hash('sha256', $fileBytes);
            $fileSizeBytes = strlen($fileBytes);
            $fileName = $rendered['file_name'];
            $mimeType = $rendered['mime_type'];
            $extension = $rendered['extension'];

            $relativePath = "exports/{$exportJob->id}.{$extension}";

            $disk = Storage::disk('local');

            $exportDir = storage_path('app/private/exports');
            if (! is_dir($exportDir)) {
                @mkdir($exportDir, 0775, true);
            }
            @chmod($exportDir, 0775);

            // Store in private disk (never public)
            $stored = $disk->put($relativePath, $fileBytes);
            if (! $stored || ! $disk->exists($relativePath) || $disk->size($relativePath) !== $fileSizeBytes) {
                throw new \RuntimeException("Не удалось сохранить скомпилированный документ на диск: {$relativePath}");
            }

            try {
                $realPath = $disk->path($relativePath);
                if (file_exists($realPath)) {
                    @chmod($realPath, 0664);
                    @chgrp($realPath, 'www-data');
                }
            } catch (\Throwable $e) {
                // Ignore for mock/in-memory test disks
            }

            // Record exported artifact in DB (updateOrCreate to guard against retried jobs)
            $artifact = DB::transaction(function () use ($exportJob, $relativePath, $fileName, $fileSizeBytes, $sha256, $mimeType) {
                $art = ExportedArtifact::updateOrCreate(
                    ['export_job_id' => $exportJob->id],
                    [
                        'file_disk' => 'local',
                        'file_path' => $relativePath,
                        'file_name' => $fileName,
                        'file_size_bytes' => $fileSizeBytes,
                        'mime_type' => $mimeType,
                        'sha256_hash' => $sha256,
                        'provenance_fingerprint' => $exportJob->provenance_fingerprint,
                        'expires_at' => now()->addDays(7),
                        'created_at' => now(),
                    ]
                );

                $exportJob->update([
                    'status' => 'ready',
                    'completed_at' => now(),
                ]);

                return $art;
            });

            return $artifact;
        } catch (Throwable $e) {
            Log::error('Export compilation failed: '.$e->getMessage(), [
                'export_job_id' => $exportJob->id,
                'format' => $exportJob->format,
                'exception' => $e,
            ]);

            $exportJob->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at' => now(),
            ]);

            throw $e;
        }
    }
}
