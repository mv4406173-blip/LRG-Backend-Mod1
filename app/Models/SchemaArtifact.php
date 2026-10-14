<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SchemaArtifact extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'project_id',
        'import_id',
        'version',
        'format_name',
        'format_version',
        'schema_document',
        'artifact_sha256',
        'parser_version',
        'created_by',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'schema_document' => 'array',
            'version'         => 'integer',
            'created_at'      => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(SchemaImport::class, 'import_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function diagnostics(): HasMany
    {
        return $this->hasMany(SchemaImportDiagnostic::class, 'import_id', 'import_id');
    }
}
