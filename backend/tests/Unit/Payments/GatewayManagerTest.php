<?php

use App\Services\Payments\Exceptions\UnknownGateway;
use App\Services\Payments\GatewayManager;
use App\Services\Payments\Gateways\FakeGateway;

it('finds the configured gateway', function () {
    $manager = app(GatewayManager::class);

    expect($manager->default())->toBeInstanceOf(FakeGateway::class)
        ->and($manager->has('fake'))->toBeTrue()
        ->and($manager->driver('fake')->name())->toBe('fake');
});

it('does not know gateways that are not configured', function () {
    $manager = app(GatewayManager::class);

    expect($manager->has('stripe'))->toBeFalse();
    $manager->driver('stripe');
})->throws(UnknownGateway::class);

it('never offers the local simulator in production, whatever the settings say', function () {
    app()['env'] = 'production';

    $manager = app(GatewayManager::class);

    expect($manager->has('fake'))->toBeFalse();
    $manager->default();
})->throws(UnknownGateway::class);
