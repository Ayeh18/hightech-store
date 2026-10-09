<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** The pretend payment company's record of a refund (local development only). */
#[Fillable(['reference', 'payment_reference', 'idempotency_key', 'amount', 'status'])]
class FakeGatewayRefund extends Model
{
    protected $primaryKey = 'reference';

    public $incrementing = false;

    protected $keyType = 'string';
}
