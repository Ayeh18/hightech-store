<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\CouponType;
use Database\Factories\CouponFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'description', 'type', 'value', 'min_order_amount', 'max_discount_amount', 'starts_at', 'ends_at', 'usage_limit', 'per_user_limit', 'is_active'])]
class Coupon extends Model
{
    /** @use HasFactory<CouponFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'type' => CouponType::class,
            'min_order_amount' => MoneyCast::class,
            'max_discount_amount' => MoneyCast::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function usages(): HasMany
    {
        return $this->hasMany(CouponUsage::class);
    }

    /** Codes are not case-sensitive for customers, so they are always stored (and looked up) in capitals. */
    public static function normalize(string $code): string
    {
        return strtoupper(trim($code));
    }

    protected function setCodeAttribute(string $value): void
    {
        $this->attributes['code'] = self::normalize($value);
    }
}
