<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = AuditLog::query()->latest();

        if ($request->filled('user_id')) {
            $q->where('user_id', (int) $request->user_id);
        }

        if ($request->filled('action')) {
            $q->where('action', $request->action);
        }

        if ($request->filled('auditable_type')) {
            $q->where('auditable_type', $request->auditable_type);
        }

        if ($request->filled('auditable_id')) {
            $q->where('auditable_id', (int) $request->auditable_id);
        }

        if ($request->filled('from')) {
            $q->where('created_at', '>=', $request->from);
        }

        if ($request->filled('to')) {
            $q->where('created_at', '<=', $request->to);
        }

        return response()->json(
            $q->paginate((int) $request->get('per_page', 100))
        );
    }
}
