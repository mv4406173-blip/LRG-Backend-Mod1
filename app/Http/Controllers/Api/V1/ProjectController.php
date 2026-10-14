<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ProjectController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Project::class);

        $projects = Project::where('owner_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json([
            'data' => $projects->map(fn (Project $p) => $this->projectResource($p)),
            'meta' => [
                'current_page' => $projects->currentPage(),
                'last_page' => $projects->lastPage(),
                'total' => $projects->total(),
            ],
        ]);
    }

    public function store(StoreProjectRequest $request): JsonResponse
    {
        $this->authorize('create', Project::class);

        $data = $request->validated();

        $project = Project::create([
            'owner_id' => $request->user()->id,
            'name' => $data['name'],
            'slug' => $data['slug'],
            'description' => $data['description'] ?? null,
            'status' => 'active',
        ]);

        return response()->json([
            'data' => $this->projectResource($project),
        ], 201);
    }

    public function show(Request $request, Project $project): JsonResponse
    {
        $project = $this->accessibleProject($request, $project);
        $this->authorize('view', $project);

        return response()->json([
            'data' => $this->projectResource($project),
        ]);
    }

    public function update(UpdateProjectRequest $request, Project $project): JsonResponse
    {
        $project = $this->accessibleProject($request, $project);
        $this->authorize('update', $project);

        $data = $request->validated();

        if (isset($data['name'])) {
            $data['slug'] = Str::slug($data['name']).'-'.Str::random(6);
        }

        $project->update($data);

        return response()->json([
            'data' => $this->projectResource($project->fresh()),
        ]);
    }

    public function destroy(Request $request, Project $project): JsonResponse
    {
        $project = $this->accessibleProject($request, $project);
        $this->authorize('delete', $project);

        $project->update(['status' => 'archived']);

        return response()->json(null, 204);
    }

    private function projectResource(Project $p): array
    {
        return [
            'id' => $p->id,
            'name' => $p->name,
            'slug' => $p->slug,
            'description' => $p->description,
            'status' => $p->status,
            'created_at' => $p->created_at->toIso8601String(),
            'updated_at' => $p->updated_at->toIso8601String(),
        ];
    }
}
