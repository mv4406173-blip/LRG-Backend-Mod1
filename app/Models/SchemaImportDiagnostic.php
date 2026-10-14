<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SchemaImportDiagnostic extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'import_id',
        'severity',
        'code',
        'message',
        'source_location',
        'details',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'source_location' => 'array',
            'details'         => 'array',
            'created_at'      => 'datetime',
        ];
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(SchemaImport::class, 'import_id');
    }
}
