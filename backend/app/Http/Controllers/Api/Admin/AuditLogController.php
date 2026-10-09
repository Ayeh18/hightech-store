<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\AuditLogResource;
use App\Models\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Who did what, newest first. The routes require the audit.view permission (admins). Read-only: nothing here edits the log. */
class AuditLogController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'action' => ['nullable', 'string', 'max:80'],
            'q' => ['nullable', 'string', 'max:100'],
            'subject' => ['nullable', 'string', 'max:40', 'alpha_num'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $tz = config('shop.timezone');
        $actor = isset($filters['q']) ? '%'.addcslashes(trim($filters['q']), '%_\\').'%' : null;

        $logs = AuditLog::query()
            ->with('user')
            ->when($filters['action'] ?? null, fn ($q, $action) => $q->where('action', $action))
            ->when($actor, fn ($q) => $q->whereHas('user', fn ($u) => $u->where('email', 'ilike', $actor)->orWhere('name', 'ilike', $actor)))
            // "Order", "Product"... the class name at the end of the stored subject type.
            ->when($filters['subject'] ?? null, fn ($q, $subject) => $q->where('subject_type', 'like', '%\\'.$subject))
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->where('created_at', '>=', CarbonImmutable::createFromFormat('Y-m-d', $from, $tz)->startOfDay()->utc()))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->where('created_at', '<=', CarbonImmutable::createFromFormat('Y-m-d', $to, $tz)->endOfDay()->utc()))
            ->latest()->latest('id')
            ->paginate(25);

        return AuditLogResource::collection($logs);
    }

    /** Every action that has been recorded, for the filter list. */
    public function actions(): JsonResponse
    {
        return response()->json(['data' => AuditLog::query()->distinct()->orderBy('action')->pluck('action')]);
    }
}
