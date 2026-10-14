<?php

namespace App\Services;

use App\Models\Project;
use App\Models\SchemaArtifact;
use App\Models\SchemaImport;
use App\Models\SchemaImportDiagnostic;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class SchemaImportService
{
    public const PARSER_VERSION = '1.0.0';

    public const MAX_FILE_SIZE_BYTES = 10 * 1024 * 1024;

    public function __construct(
        private readonly LrgSchemaNormalizer $normalizer
    ) {}

    public function process(SchemaImport $import): void
    {
        $locked = DB::transaction(function () use ($import): ?SchemaImport {
            $current = SchemaImport::query()
                ->whereKey($import->id)
                ->lockForUpdate()
                ->first();

            if (! $current || $current->status !== SchemaImport::STATUS_QUEUED) {
                return null;
            }

            $current->update([
                'status' => SchemaImport::STATUS_PROCESSING,
                'started_at' => now(),
                'attempt_count' => $current->attempt_count + 1,
                'parser_version' => self::PARSER_VERSION,
            ]);

            return $current->fresh();
        });

        if (! $locked) {
            return;
        }

        $import = $locked;

        try {
            $content = Storage::disk('local')->get($import->storage_path);

            if ($content === null) {
                throw new \RuntimeException('SQL file not found in storage.');
            }

            if (strlen($content) > self::MAX_FILE_SIZE_BYTES) {
                throw new \RuntimeException('SQL file exceeds the configured size limit.');
            }

            if (hash('sha256', $content) !== $import->file_sha256) {
                throw new \RuntimeException('File integrity check failed: SHA-256 mismatch.');
            }

            $normalized = $this->normalizer->normalize($content, $import->dialect, $import->file_sha256);
            $this->persistDiagnostics($import, $normalized['diagnostics']);

            if ($this->normalizer->hasBlockingErrors($normalized['diagnostics'])) {
                $import->update([
                    'status' => SchemaImport::STATUS_FAILED,
                    'finished_at' => now(),
                    'error_code' => $normalized['diagnostics'][0]['code'] ?? 'VALIDATION_ERROR',
                    'error_summary' => substr($normalized['diagnostics'][0]['message'] ?? 'Schema validation failed.', 0, 1000),
                ]);

                return;
            }

            DB::transaction(function () use ($import, $normalized): void {
                $this->publishArtifact($import, $normalized['document']);

                $import->update([
                    'status' => SchemaImport::STATUS_COMPLETED,
                    'finished_at' => now(),
                    'error_code' => null,
                    'error_summary' => null,
                ]);
            });
        } catch (\Throwable $e) {
            Log::error('SchemaImport processing failed', [
                'import_id' => $import->id,
                'error' => $e->getMessage(),
            ]);

            SchemaImportDiagnostic::create([
                'import_id' => $import->id,
                'severity' => 'critical',
                'code' => 'PROCESSING_ERROR',
                'message' => 'The import could not be processed due to an internal error.',
                'created_at' => now(),
            ]);

            $import->update([
                'status' => SchemaImport::STATUS_FAILED,
                'finished_at' => now(),
                'error_code' => 'PROCESSING_ERROR',
                'error_summary' => 'The import could not be processed due to an internal error.',
            ]);
        }
    }

    public function queueRetry(SchemaImport $import): SchemaImport
    {
        if (! $import->isRetryable()) {
            throw new \LogicException('This import cannot be retried.');
        }

        DB::transaction(function () use ($import): void {
            $import->diagnostics()->delete();
            $import->update([
                'status' => SchemaImport::STATUS_QUEUED,
                'error_code' => null,
                'error_summary' => null,
                'started_at' => null,
                'finished_at' => null,
                'queued_at' => now(),
            ]);
        });

        return $import->fresh();
    }

    private function persistDiagnostics(SchemaImport $import, array $diagnostics): void
    {
        $import->diagnostics()->delete();

        foreach ($diagnostics as $diagnostic) {
            SchemaImportDiagnostic::create([
                'import_id' => $import->id,
                'severity' => $diagnostic['severity'] ?? 'info',
                'code' => $diagnostic['code'] ?? 'UNKNOWN',
                'message' => substr((string) ($diagnostic['message'] ?? ''), 0, 2000),
                'source_location' => $diagnostic['source_location'] ?? null,
                'created_at' => now(),
            ]);
        }
    }

    private function publishArtifact(SchemaImport $import, array $normalized): void
    {
        Project::query()
            ->whereKey($import->project_id)
            ->lockForUpdate()
            ->firstOrFail();

        $nextVersion = SchemaArtifact::where('project_id', $import->project_id)
            ->max('version');
        $nextVersion = ($nextVersion ?? 0) + 1;

        $jsonDocument = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $artifactSha256 = hash('sha256', $jsonDocument);

        SchemaArtifact::create([
            'project_id' => $import->project_id,
            'import_id' => $import->id,
            'version' => $nextVersion,
            'format_name' => LrgSchemaNormalizer::FORMAT_NAME,
            'format_version' => LrgSchemaNormalizer::FORMAT_VERSION,
            'schema_document' => $normalized,
            'artifact_sha256' => $artifactSha256,
            'parser_version' => self::PARSER_VERSION,
            'created_by' => $import->requested_by,
            'created_at' => now(),
        ]);
    }
}
