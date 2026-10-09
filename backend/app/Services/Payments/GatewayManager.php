<?php

namespace App\Services\Payments;

use App\Services\Payments\Contracts\PaymentGateway;
use App\Services\Payments\Exceptions\UnknownGateway;
use Illuminate\Contracts\Container\Container;

/** Finds the gateway object for a name ("fake", later a real one). The only place that knows the list of gateways. */
class GatewayManager
{
    public function __construct(private readonly Container $container) {}

    public function default(): PaymentGateway
    {
        return $this->driver((string) config('payments.default'));
    }

    /** @throws UnknownGateway */
    public function driver(string $name): PaymentGateway
    {
        if (! $this->has($name)) {
            throw new UnknownGateway("Unknown payment gateway \"{$name}\".");
        }

        return $this->container->make(config("payments.gateways.{$name}"));
    }

    public function has(string $name): bool
    {
        if (config("payments.gateways.{$name}") === null) {
            return false;
        }

        // The simulator must never run in production, whatever the settings say.
        return ! (in_array($name, config('payments.local_only'), true) && app()->isProduction());
    }
}
