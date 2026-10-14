<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSchemaImportRequest;
use App\Jobs\ProcessSchemaImport;
use App\Models\Project;
use App\Models\SchemaImport;
use App\Services\SchemaImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class SchemaImportController extends Controller
{
    public function __construct(
        private readonly SchemaImportService $schemaImportService
    ) {}

    public function store(StoreSchemaImportRequest $request, Project $project): JsonResponse
    {
        $project = $this->accessibleProject($request, $project);

        if ($project->isArchived()) {
            return response()->json([
                'message' => 'Un proyecto archivado no acepta nuevas importaciones.',
            ], Response::HTTP_CONFLICT);
        }

        $this->authorize('importSchema', $project);

        $file = $request->file('file');

        if ($file->getSize() > SchemaImportService::MAX_FILE_SIZE_BYTES) {
            return response()->json([
                'message' => 'El archivo es demasiado grande.',
            ], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        if ($request->filled('idempotency_key')) {
            $existing = SchemaImport::query()
                ->where('project_id', $project->id)
                ->where('idempotency_key', $request->string('idempotency_key'))
                ->first();

            if ($existing) {
                return response()->json([
                    'data' => $this->importResource($existing),
                ], Response::HTTP_ACCEPTED);
            }
        }

        $content = file_get_contents($file->getRealPath());
        if ($content === false) {
            throw new \RuntimeException('No fue posible leer el archivo SQL recibido.');
        }

        $sha256 = hash('sha256', $content);
        $storagePath = "schema_imports/{$project->id}/".Str::uuid().'.sql';

        if (! Storage::disk('local')->put($storagePath, $content)) {
            throw new \RuntimeException('No fue posible almacenar el archivo SQL recibido.');
        }

        $import = SchemaImport::create([
            'project_id' => $project->id,
            'requested_by' => $request->user()->id,
            'original_filename' => mb_substr(str_replace("\0", '', $file->getClientOriginalName()), 0, 255),
            'storage_path' => $storagePath,
            'file_sha256' => $sha256,
            'file_size_bytes' => $file->getSize() ?: strlen($content),
            'dialect' => $request->dialect,
            'status' => SchemaImport::STATUS_QUEUED,
            'idempotency_key' => $request->idempotency_key,
            'queued_at' => now(),
        ]);

        ProcessSchemaImport::dispatch($import);

        return response()->json([
            'data' => $this->importResource($import),
        ], Response::HTTP_ACCEPTED);
    }

    public function index(Request $request, Project $project): JsonResponse
    {
        $project = $this->accessibleProject($request, $project);

        $imports = SchemaImport::where('project_id', $project->id)
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json([
            'data' => $imports->map(fn (SchemaImport $i) => $this->importResource($i)),
            'meta' => [
                'current_page' => $imports->currentPage(),
                'last_page' => $imports->lastPage(),
                'total' => $imports->total(),
            ],
        ]);
    }

    public function show(Request $request, Project $project, SchemaImport $import): JsonResponse
    {
        $project = $this->accessibleProject($request, $project);
        abort_if($import->project_id !== $project->id, 404);

        $diagnostics = $import->diagnostics()->orderBy('id')->get();

        return response()->json([
            'data' => array_merge($this->importResource($import), [
                'diagnostics_detail' => $diagnostics->map(fn ($d) => [
                    'id' => $d->id,
                    'severity' => $d->severity,
                    'code' => $d->code,
                    'message' => $d->message,
                    'source_location' => $d->source_location,
                    'created_at' => $d->created_at?->toIso8601String(),
                ]),
            ]),
        ]);
    }

    public function retry(Request $request, Project $project, SchemaImport $import): JsonResponse
    {
        $project = $this->accessibleProject($request, $project);
        abort_if($import->project_id !== $project->id, 404);

        if ($project->isArchived()) {
            return response()->json([
                'message' => 'Un proyecto archivado no acepta nuevas importaciones.',
            ], Response::HTTP_CONFLICT);
        }

        if (! $import->isRetryable()) {
            return response()->json([
                'message' => 'Esta importación no puede reintentarse.',
            ], Response::HTTP_CONFLICT);
        }

        $this->authorize('retry', $import);

        $import = $this->schemaImportService->queueRetry($import);
        ProcessSchemaImport::dispatch($import);

        return response()->json([
            'data' => $this->importResource($import),
        ], Response::HTTP_ACCEPTED);
    }

    private function importResource(SchemaImport $i): array
    {
        $errorCount = $i->diagnostics()->whereIn('severity', ['error', 'critical'])->count();
        $warningCount = $i->diagnostics()->where('severity', 'warning')->count();

        $i->loadMissing('artifact');

        return [
            'id' => $i->id,
            'project_id' => $i->project_id,
            'status' => $i->status,
            'dialect' => $i->dialect,
            'original_filename' => $i->original_filename,
            'file_size_bytes' => $i->file_size_bytes,
            'attempt_count' => $i->attempt_count,
            'artifact_id' => $i->artifact?->id,
            'artifact_version' => $i->artifact?->version,
            'diagnostics' => [
                'errors' => $errorCount,
                'warnings' => $warningCount,
            ],
            'queued_at' => $i->queued_at?->toIso8601String(),
            'started_at' => $i->started_at?->toIso8601String(),
            'finished_at' => $i->finished_at?->toIso8601String(),
            'created_at' => $i->created_at->toIso8601String(),
        ];
    }
}
