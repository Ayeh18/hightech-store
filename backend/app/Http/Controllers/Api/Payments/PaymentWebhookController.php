<?php

namespace App\Http\Controllers\Api\Payments;

use App\Http\Controllers\Controller;
use App\Services\Payments\Exceptions\InvalidWebhook;
use App\Services\Payments\GatewayManager;
use App\Services\Payments\PaymentProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Where payment companies call us: POST /api/webhooks/{provider}. There is no login and no cookie here; the signature on
 * the request is the only proof that it is genuine.
 */
class PaymentWebhookController extends Controller
{
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
