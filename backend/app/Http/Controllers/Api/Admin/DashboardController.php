<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\Reports\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The admin dashboard. The route requires the reports.view permission. */
class DashboardController extends Controller
{
    /** `?days=7|30|90` (default 30): the period the cards, charts and top lists cover. */
    public function show(Request $request, DashboardService $dashboard): JsonResponse
    {
        $days = (int) ($request->validate(['days' => ['nullable', Rule::in([7, 30, 90])]])['days'] ?? 30);

        return response()->json(['data' => $dashboard->build($request->user(), $request, $days)]);
    }
}
