<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request)
    {
        return AuditLog::with('user:id,name,role')
            ->when($request->action, fn ($q, $a) => $q->where('action', 'like', "$a%"))
            ->orderByDesc('id')
            ->paginate($this->perPage($request, 30))
            ->through(fn (AuditLog $l) => array_merge($l->toArray(), ['user_name' => $l->user?->name]));
    }
}
