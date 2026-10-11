<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\AdminPaymentDetailResource;
use App\Http\Resources\Admin\AdminPaymentResource;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/** Every payment attempt and its diary. The routes require the payments.view permission (admins). */
class PaymentController extends Controller
{
    /** List every payment. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(PaymentStatus::class)],
            'requires_refund' => ['nullable', 'boolean'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $term = isset($filters['q']) ? '%'.addcslashes(trim($filters['q']), '%_\\').'%' : null;

        $payments = Payment::query()
            ->with('order.user')
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when(isset($filters['requires_refund']), fn ($q) => $q->where('requires_refund', (bool) $filters['requires_refund']))
            ->when($term, fn ($q) => $q->whereHas('order', fn ($o) => $o
                ->where('order_number', 'ilike', $term)
                ->orWhereHas('user', fn ($u) => $u->where('email', 'ilike', $term)->orWhere('name', 'ilike', $term))))
            ->latest()->latest('id')
            ->paginate(20);

        return AdminPaymentResource::collection($payments);
    }

    /** Show one payment with its full history. */
    public function show(Payment $payment): AdminPaymentDetailResource
    {
        return new AdminPaymentDetailResource($payment->load(['order.user', 'transactions', 'refunds']));
    }
}
