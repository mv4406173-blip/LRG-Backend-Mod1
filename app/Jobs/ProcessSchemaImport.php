<?php

namespace App\Jobs;

use App\Models\SchemaImport;
use App\Services\SchemaImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessSchemaImport implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 120;

    public function __construct(
        public readonly SchemaImport $import
    ) {}

    /**
     * Execute the job.
     */
    public function handle(SchemaImportService $service): void
    {
        $service->process($this->import->fresh());
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Schema import job exhausted its retry attempts.', [
            'import_id' => $this->import->id,
            'error' => $exception->getMessage(),
        ]);

        $this->import->update([
            'status' => SchemaImport::STATUS_FAILED,
            'finished_at' => now(),
            'error_code' => 'JOB_FAILED',
            'error_summary' => 'The import could not be processed after several attempts.',
        ]);
    }
}
