<?php

use App\Services\Payments\Exceptions\InvalidWebhook;
use App\Services\Payments\WebhookSigner;

$body = '{"id":"evt_1","type":"payment.succeeded"}';

it('accepts a body signed with the shared secret', function () use ($body) {
    $signer = new WebhookSigner;
    $header = $signer->sign($body, 'secret', 1_760_000_000);

    $signer->verify($body, $header, 'secret', 300, now: 1_760_000_100);

    expect($header)->toMatch('/^t=1760000000,v1=[0-9a-f]{64}$/');
});

it('rejects a body that was changed after signing', function () use ($body) {
    $signer = new WebhookSigner;
    $header = $signer->sign($body, 'secret', 1_760_000_000);

    $signer->verify(str_replace('evt_1', 'evt_2', $body), $header, 'secret', 300, now: 1_760_000_000);
})->throws(InvalidWebhook::class, 'signature does not match');

it('rejects a signature made with a different secret', function () use ($body) {
    $signer = new WebhookSigner;

    $signer->verify($body, $signer->sign($body, 'attacker-guess', 1_760_000_000), 'secret', 300, now: 1_760_000_000);
})->throws(InvalidWebhook::class, 'signature does not match');

it('rejects an old request, so a captured webhook cannot be replayed later', function () use ($body) {
    $signer = new WebhookSigner;
    $header = $signer->sign($body, 'secret', 1_760_000_000);

    $signer->verify($body, $header, 'secret', 300, now: 1_760_000_000 + 301);
})->throws(InvalidWebhook::class, 'timestamp');

it('also rejects a timestamp from the future', function () use ($body) {
    $signer = new WebhookSigner;

    $signer->verify($body, $signer->sign($body, 'secret', 1_760_001_000), 'secret', 300, now: 1_760_000_000);
})->throws(InvalidWebhook::class, 'timestamp');

it('rejects a missing or malformed header', function (?string $header) use ($body) {
    (new WebhookSigner)->verify($body, $header, 'secret', 300);
})->with([null, '', 'v1=abc', 't=1,v1=short', 't=abc,v1='.str_repeat('a', 64)])
    ->throws(InvalidWebhook::class, 'missing or malformed header');

it('refuses to accept anything when no secret is configured', function () use ($body) {
    $signer = new WebhookSigner;

    $signer->verify($body, $signer->sign($body, '', 1_760_000_000), '', 300, now: 1_760_000_000);
})->throws(InvalidWebhook::class, 'no secret');
