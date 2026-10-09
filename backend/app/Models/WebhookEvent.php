<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** A gateway event we have accepted. (provider, event_id) is unique, which is what makes webhooks idempotent. */
#[Fillable(['provider', 'event_id', 'type', 'source', 'payload', 'outcome'])]
class WebhookEvent extends Model
{
    public const UPDATED_AT = null;

    public const CREATED_AT = 'received_at';

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }
}
