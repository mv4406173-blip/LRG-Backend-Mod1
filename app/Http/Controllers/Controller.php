<?php

namespace App\Http\Controllers;

use App\Models\Project;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;

abstract class Controller
{
    use AuthorizesRequests;

    /**
     * Deny access to foreign projects without revealing that they exist.
     */
    protected function accessibleProject(Request $request, Project $project): Project
    {
        abort_unless($request->user()?->id === $project->owner_id, 404);

        return $project;
    }
}
