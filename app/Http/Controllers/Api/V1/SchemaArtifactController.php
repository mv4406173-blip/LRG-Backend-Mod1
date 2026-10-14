<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\SchemaArtifact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SchemaArtifactController extends Controller
{
    public function index(Request $request, Project $project): JsonResponse
    {
        $project = $this->accessibleProject($request, $project);

        $artifacts = SchemaArtifact::where('project_id', $project->id)
            ->orderByDesc('version')
            ->paginate(20);

        return response()->json([
            'data' => $artifacts->map(fn (SchemaArtifact $a) => $this->artifactSummary($a)),
            'meta' => [
                'current_page' => $artifacts->currentPage(),
                'last_page' => $artifacts->lastPage(),
                'total' => $artifacts->total(),
            ],
        ]);
    }

    public function show(Request $request, Project $project, SchemaArtifact $artifact): JsonResponse
    {
        $project = $this->accessibleProject($request, $project);
        abort_if($artifact->project_id !== $project->id, 404);

        return response()->json([
            'data' => array_merge($this->artifactSummary($artifact), [
                'schema_document' => $artifact->schema_document,
            ]),
        ]);
    }

    private function artifactSummary(SchemaArtifact $a): array
    {
        $doc = $a->schema_document ?? [];

        return [
            'id' => $a->id,
            'project_id' => $a->project_id,
            'import_id' => $a->import_id,
            'version' => $a->version,
            'format_name' => $a->format_name,
            'format_version' => $a->format_version,
            'artifact_sha256' => $a->artifact_sha256,
            'parser_version' => $a->parser_version,
            'table_count' => count($doc['tables'] ?? []),
            'relationship_count' => count($doc['relationships'] ?? []),
            'created_at' => $a->created_at?->toIso8601String(),
        ];
    }
}
