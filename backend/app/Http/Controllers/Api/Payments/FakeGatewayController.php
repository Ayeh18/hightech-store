<?php

namespace App\Http\Controllers\Api\Payments;

use App\Http\Controllers\Controller;
use App\Models\FakeGatewayPayment;
use App\Services\Payments\Fake\FakePay;
use App\Services\Payments\GatewayManager;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The pretend payment company's payment page, as an API for the page in the Vue app. Like a real gateway's page it needs
 * no login. It does not exist at all (404) when the fake gateway is switched off or the app runs in production.
 */
class FakeGatewayController extends Controller
{
    public function show(string $reference, GatewayManager $gateways, FakePay $fake): JsonResponse
    {
        abort_unless($gateways->has('fake'), 404);

        $payment = FakeGatewayPayment::findOrFail($reference);

        return response()->json(['data' => [
            'reference' => $payment->reference,
            'description' => $payment->description,
            'amount' => Money::fromSen($payment->amount),
            'status' => $payment->status,
            'return_url' => $fake->returnUrl($payment),
        ]]);
    }

    /** The customer's choice on the page. The result reaches the shop later, as a webhook. */
    public function complete(Request $request, string $reference, GatewayManager $gateways, FakePay $fake): JsonResponse
    {
        abort_unless($gateways->has('fake'), 404);

        $data = $request->validate([
            'outcome' => ['required', 'string', Rule::in(FakePay::OUTCOMES)],
            // Pay a different amount than asked (sen), to see what reconciliation catches. Only a payment can have an amount.
            'amount' => ['nullable', 'integer', 'min:1', 'max:100000000', 'prohibited_unless:outcome,succeeded'],
        ]);
        $payment = $fake->complete(FakeGatewayPayment::findOrFail($reference), $data['outcome'], $data['amount'] ?? null);

        return response()->json(['data' => ['status' => $payment->status, 'return_url' => $fake->returnUrl($payment)]]);
    }
}
