<?php

namespace App\Http\Requests\Admin;

use App\Enums\CouponType;
use App\Models\Coupon;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Creating and editing a coupon. Money is in sen; a percentage is in basis points (1000 = 10%). */
class CouponRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Codes are not case-sensitive, so "welcome10" and "WELCOME10" are the same coupon.
        if (is_string($this->input('code'))) {
            $this->merge(['code' => Coupon::normalize($this->input('code'))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var Coupon|null $coupon */
        $coupon = $this->route('coupon');
        $percent = $this->input('type') === CouponType::Percent->value;

        return [
            'code' => ['required', 'string', 'regex:/^[A-Z0-9_-]{3,40}$/', Rule::unique('coupons', 'code')->ignore($coupon?->id)],
            'description' => ['nullable', 'string', 'max:255'],
            'type' => ['required', Rule::enum(CouponType::class)],
            'value' => ['required', 'integer', 'min:1', $percent ? 'max:10000' : 'max:100000000'],
            'min_order_amount' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            // Only a percentage coupon needs a cap on how much it can take off.
            'max_discount_amount' => [$percent ? 'nullable' : 'prohibited', 'integer', 'min:1', 'max:100000000'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'usage_limit' => ['nullable', 'integer', 'min:1', 'max:10000000'],
            'per_user_limit' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, mixed> the validated data, with the money columns as Money objects */
    public function couponAttributes(): array
    {
        $data = $this->validated();
        $data['min_order_amount'] = Money::fromSen((int) ($data['min_order_amount'] ?? 0));
        $data['max_discount_amount'] = isset($data['max_discount_amount']) ? Money::fromSen((int) $data['max_discount_amount']) : null;

        return $data;
    }
}
