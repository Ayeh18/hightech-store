<?php

namespace App\Jobs;

use App\Services\Payments\Gateways\FakeGateway;
use App\Services\Payments\WebhookSigner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;

/**
 * Sends one signed webhook from the pretend payment company to the shop. Like a real gateway it tries again, with
 * growing pauses, until the shop answers with a success code, so the shop has to cope with receiving an event twice.
 */
class DeliverFakeWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public readonly string $body) {}

    /** @return array<int, int> seconds to wait before each retry */
    public function backoff(): array
    {
        return [5, 30, 120, 600];
    }

    public function handle(WebhookSigner $signer): void
    {
        // The signature is made fresh on every attempt (its timestamp must be recent); the body, and so the event id, never changes.
        $signature = $signer->sign($this->body, (string) config('payments.fake.webhook_secret'), time());

        Http::withBody($this->body, 'application/json')
            ->withHeaders([FakeGateway::SIGNATURE_HEADER => $signature])
            ->timeout(10)
            ->post((string) config('payments.fake.webhook_url'))
            ->throw();
    }
}
