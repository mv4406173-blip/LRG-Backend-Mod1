<?php

namespace App\Policies;

use App\Models\SchemaArtifact;
use App\Models\User;

class SchemaArtifactPolicy
{
    /**
     * Determine whether the user can view the artifact.
     */
    public function view(User $user, SchemaArtifact $artifact): bool
    {
        return $user->id === $artifact->project->owner_id;
    }
}
