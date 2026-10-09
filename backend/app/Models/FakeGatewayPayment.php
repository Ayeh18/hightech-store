<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** The pretend payment company's own record of a payment (local development only). */
#[Fillable(['reference', 'merchant_reference', 'description', 'amount', 'paid_amount', 'currency', 'return_url', 'status'])]
class FakeGatewayPayment extends Model
{
    protected $primaryKey = 'reference';

    public $incrementing = false;

    protected $keyType = 'string';
}
