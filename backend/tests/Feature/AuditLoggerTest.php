<?php

use App\Models\User;
use App\Services\AuditLogger;

it('never stores sensitive values', function () {
    $actor = User::factory()->create();

    $log = app(AuditLogger::class)->log(
        'user.updated',
        $actor,
        $actor,
        ['name' => 'Old', 'password' => 'hunter2'],
        ['name' => 'New', 'api_key' => 'abc', 'nested' => ['payment_secret' => 'x', 'ok' => 1]],
    );

    expect($log->old_values)->toBe(['name' => 'Old'])
        ->and($log->new_values)->toBe(['name' => 'New', 'nested' => ['ok' => 1]])
        ->and($log->subject_id)->toBe($actor->id);
});
