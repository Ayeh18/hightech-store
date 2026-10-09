<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\Payments\ReconciliationResult;
use App\Services\Payments\ReconciliationService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Our books against the payment company's. The routes require the payments.view permission (admins). */
class ReconciliationController extends Controller
{
    public function __construct(private readonly ReconciliationService $reconciliation) {}

    /** `?from=2026-10-01&to=2026-10-07`: every payment sent to a gateway in that period, checked against the gateway. */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $tz = config('shop.timezone');
        $from = CarbonImmutable::createFromFormat('Y-m-d', $data['from'], $tz)->startOfDay();
        $to = CarbonImmutable::createFromFormat('Y-m-d', $data['to'], $tz)->startOfDay();

        if ($from->diffInDays($to) >= ReconciliationService::MAX_DAYS) {
            throw ValidationException::withMessages(['to' => 'Choose a period of at most '.ReconciliationService::MAX_DAYS.' days.']);
        }

        $report = $this->reconciliation->report($from, $to, $request->user());

        return response()->json(['data' => [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'summary' => $report['summary'],
            // The ones that disagree first, then the rest in the order they happened.
            'rows' => collect($report['results'])->sortBy(fn (ReconciliationResult $r) => $r->matched() ? 1 : 0)->values()
                ->map(fn (ReconciliationResult $r) => $r->toArray())->all(),
        ]]);
    }

    /** Ask the gateway again about one payment and apply its answer (only for disagreements that a missed message explains). */
    public function sync(Request $request, Payment $payment): JsonResponse
    {
        return response()->json(['data' => $this->reconciliation->sync($payment, $request->user())->toArray()]);
    }
}
