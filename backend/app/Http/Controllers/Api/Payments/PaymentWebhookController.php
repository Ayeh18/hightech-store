<?php

namespace App\Http\Controllers\Api\Payments;

use App\Http\Controllers\Controller;
use App\Services\Payments\Exceptions\InvalidWebhook;
use App\Services\Payments\GatewayManager;
use App\Services\Payments\PaymentProcessor;
use Dedoc\Scramble\Attributes\Response as ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Where payment companies call us: POST /api/webhooks/{provider}. There is no login and no cookie here; the signature on
 * the request is the only proof that it is genuine.
 */
class PaymentWebhookController extends Controller
{
    /**
     * Receive a payment notification (webhook).
     *
     * Called by the payment company, not by a logged-in user: the signature is the only proof it is genuine (see "Payments and webhooks"). Every outcome that is not an error is answered `200` with what was done, including `duplicate` for an event already processed.
     */
    #[ApiResponse(200, 'Understood. `status` says what was done: `processed`, `duplicate`, `ignored`, `amount_mismatch` or `late_payment`.', type: 'array{status: string}')]
    #[ApiResponse(401, 'The signature is missing, wrong or too old.', type: 'array{message: string}')]
    #[ApiResponse(400, 'The signature is valid but the body could not be understood.', type: 'array{message: string}')]
    public function handle(Request $request, string $provider, GatewayManager $gateways, PaymentProcessor $processor): JsonResponse
    {
        abort_unless($gateways->has($provider), 404);

        // Header names are lower case; the first value of each is enough.
        $headers = array_map(fn (array $values) => (string) ($values[0] ?? ''), $request->headers->all());

        try {
            // getContent() is the raw body. The signature covers those exact bytes, so it must not be re-encoded first.
            $event = $gateways->driver($provider)->parseWebhook($request->getContent(), $headers);
        } catch (InvalidWebhook $e) {
            Log::warning('Rejected a payment webhook.', ['provider' => $provider, 'reason' => $e->getMessage(), 'ip' => $request->ip()]);

            return response()->json(['message' => $e->forged ? 'Invalid signature.' : 'The webhook could not be understood.'], $e->forged ? 401 : 400);
        }

        // Anything that is not an error is answered with 200, including "I have seen this before": a non-2xx answer
        // would only make the gateway send the same event again. A real failure (exception) becomes a 500 and, because
        // nothing was committed, the gateway's retry is processed normally.
        return response()->json(['status' => $processor->handle($provider, $event)->value]);
    }
}
