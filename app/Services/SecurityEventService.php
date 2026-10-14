<?php

namespace App\Services;

use App\Models\AuthSecurityEvent;
use Illuminate\Http\Request;

class SecurityEventService
{
    public function record(
        string $eventType,
        ?int $userId,
        Request $request,
        array $metadata = []
    ): void {
        AuthSecurityEvent::create([
            'event_type'  => $eventType,
            'user_id'     => $userId,
            'ip_address'  => $request->ip(),
            'user_agent'  => substr($request->userAgent() ?? '', 0, 500),
            'occurred_at' => now(),
            'metadata'    => $metadata ?: null,
        ]);
    }
}
