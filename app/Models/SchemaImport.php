<?php

namespace App\Models;

use Database\Factories\SchemaImportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SchemaImport extends Model
{
    /** @use HasFactory<SchemaImportFactory> */
    use HasFactory;

    public const STATUS_QUEUED      = 'queued';
    public const STATUS_PROCESSING  = 'processing';
    public const STATUS_COMPLETED   = 'completed';
    public const STATUS_FAILED      = 'failed';
    public const STATUS_CANCELLED   = 'cancelled';

    public const TERMINAL_STATUSES = [
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
        self::STATUS_CANCELLED,
    ];

    public const SUPPORTED_DIALECTS = ['mysql', 'mariadb', 'postgresql', 'sqlite'];

    protected $fillable = [
        'project_id',
        'requested_by',
        'original_filename',
        'storage_path',
        'file_sha256',
        'file_size_bytes',
        'dialect',
        'status',
        'idempotency_key',
        'attempt_count',
        'parser_version',
        'error_code',
        'error_summary',
        'queued_at',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'file_size_bytes' => 'integer',
            'attempt_count'   => 'integer',
            'queued_at'       => 'datetime',
            'started_at'      => 'datetime',
            'finished_at'     => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function diagnostics(): HasMany
    {
        return $this->hasMany(SchemaImportDiagnostic::class, 'import_id');
    }

    public function artifact(): HasOne
    {
        return $this->hasOne(SchemaArtifact::class, 'import_id');
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES, true);
    }

    public function isRetryable(): bool
    {
        return $this->status === self::STATUS_FAILED && $this->attempt_count < 10;
    }
}
