<?php

namespace App\Policies;

use App\Models\SchemaImport;
use App\Models\User;

class SchemaImportPolicy
{
    /**
     * Determine whether the user can view the import.
     */
    public function view(User $user, SchemaImport $import): bool
    {
        return $user->id === $import->project->owner_id;
    }

    /**
     * Determine whether the user can retry the import.
     */
    public function retry(User $user, SchemaImport $import): bool
    {
        return $user->id === $import->project->owner_id
            && $import->isRetryable();
    }
}
