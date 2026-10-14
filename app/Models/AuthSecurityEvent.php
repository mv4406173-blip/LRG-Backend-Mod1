<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuthSecurityEvent extends Model
{
    protected $fillable = [
        'event_type',
        'ip_address',
        'user_agent',
        'occurred_at',
        'metadata',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'metadata'    => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
