<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\RefundReason;
use App\Enums\RefundStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ApproveRefundRequest;
use App\Http\Requests\Admin\CancelPaidOrderRequest;
use App\Http\Requests\Admin\IssueRefundRequest;
use App\Http\Requests\Admin\RejectRefundRequest;
use App\Http\Resources\Admin\AdminPaymentResource;
use App\Http\Resources\Orders\RefundResource;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Services\Payments\RefundService;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Money going back to customers. The routes require the refunds.manage permission (admins only).
 * Actions that call the payment company answer 200 with the refund even when the company refused: the refund then has
 * the status FAILED and a failure_reason, and can be retried.
 */
class RefundController extends Controller
{
    public function __construct(private readonly RefundService $refunds) {}

    /** The refund queue. `?status=REQUESTED` is the list of customer requests waiting for a decision. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $status = $request->validate(['status' => ['nullable', Rule::enum(RefundStatus::class)]])['status'] ?? null;

        $refunds = Refund::query()
            ->with(['order.user'])
            ->when($status, fn ($q) => $q->where('status', $status))
            ->latest()
            ->paginate(20);

        return RefundResource::collection($refunds);
    }

    /** Payments that arrived after their order was cancelled and still need their money sent back. */
    public function needingRefund(): AnonymousResourceCollection
    {
        $payments = Payment::query()
            ->where('requires_refund', true)
            ->where('status', 'PAID')
            ->with('order.user')
            ->latest()
            ->get();

        return AdminPaymentResource::collection($payments);
    }

    public function approve(ApproveRefundRequest $request, Refund $refund): RefundResource
    {
        return new RefundResource($this->refunds->approve($refund, $request->user(), $request->validated('restock'), $request->validated('note')));
    }

    public function reject(RejectRefundRequest $request, Refund $refund): RefundResource
    {
        return new RefundResource($this->refunds->reject($refund, $request->user(), $request->validated('note')));
    }

    public function retry(Request $request, Refund $refund): RefundResource
    {
        return new RefundResource($this->refunds->retry($refund, $request->user()));
    }

    /** Refund an amount (in sen) on one payment: a goodwill gesture, or a late payment in full. */
    public function issue(IssueRefundRequest $request, Payment $payment): RefundResource
    {
        $data = $request->validated();

        return new RefundResource($this->refunds->issue(
            $payment,
            Money::fromSen($data['amount']),
            RefundReason::from($data['reason']),
            $request->user(),
            $data['note'] ?? null,
        ));
    }

    /** Cancel a paid order that has not shipped: refunds it in full and puts the goods back on the shelf. */
    public function cancelOrder(CancelPaidOrderRequest $request, Order $order): RefundResource
    {
        return new RefundResource($this->refunds->cancelPaidOrder($order, $request->user(), $request->validated('note')));
    }
}
