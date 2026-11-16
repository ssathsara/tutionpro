<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

class AuditLogger
{
    public static function log(string $action, ?Model $subject = null, array $meta = []): void
    {
        $user = auth()->user();
        AuditLog::create([
            'institute_id' => $user?->institute_id ?? ($subject?->institute_id ?? null),
            'user_id' => $user?->id,
            'action' => $action,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey(),
            'meta' => $meta ?: null,
            'ip' => request()?->ip(),
        ]);
    }
}
