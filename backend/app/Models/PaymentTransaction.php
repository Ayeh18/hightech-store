<?php

namespace App\Models;

use App\Enums\PaymentTransactionType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One line in a payment's diary. Rows are only ever added: the database refuses updates and deletes. */
#[Fillable(['payment_id', 'type', 'status_before', 'status_after', 'amount', 'provider_event_id', 'note', 'payload'])]
class PaymentTransaction extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['type' => PaymentTransactionType::class, 'payload' => 'array'];
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
