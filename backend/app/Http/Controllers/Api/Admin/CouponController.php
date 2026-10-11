<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CouponRequest;
use App\Http\Resources\Admin\CouponResource;
use App\Models\Coupon;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/** Discount codes. The routes require the coupons.manage permission (admins). Orders keep their own copy of what was taken off. */
class CouponController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** List coupons. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $term = $request->validate(['q' => ['nullable', 'string', 'max:40']])['q'] ?? null;

        $coupons = Coupon::query()
            ->withCount(['usages as used_count' => fn ($q) => $q->whereNull('released_at')])
            ->when($term, fn ($q) => $q->where('code', 'ilike', '%'.addcslashes(trim($term), '%_\\').'%'))
            ->latest()->latest('id')
            ->paginate(20);

        return CouponResource::collection($coupons);
    }

    /** Create a coupon. */
    public function store(CouponRequest $request): JsonResponse
    {
        $coupon = Coupon::create($request->couponAttributes());
        $this->audit->log('coupon.created', $request->user(), $coupon, null, ['code' => $coupon->code, 'type' => $coupon->type->value, 'value' => $coupon->value]);

        return (new CouponResource($this->fresh($coupon)))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /** Change a coupon. */
    public function update(CouponRequest $request, Coupon $coupon): CouponResource
    {
        $old = $coupon->only(['code', 'type', 'value', 'is_active']);
        $coupon->update($request->couponAttributes());
        $this->audit->log('coupon.updated', $request->user(), $coupon, $old, $coupon->only(['code', 'type', 'value', 'is_active']));

        return new CouponResource($this->fresh($coupon));
    }

    /**
     * Delete a coupon that was never used.
     *
     * Only a coupon nobody has ever used. After that the history matters, so switch it off instead.
     */
    public function destroy(Request $request, Coupon $coupon): Response
    {
        if ($coupon->usages()->exists()) {
            throw ValidationException::withMessages(['coupon' => 'This coupon has been used on orders, so it cannot be deleted. Switch it off instead.']);
        }

        $this->audit->log('coupon.deleted', $request->user(), $coupon, ['code' => $coupon->code]);
        $coupon->delete();

        return response()->noContent();
    }

    private function fresh(Coupon $coupon): Coupon
    {
        return Coupon::withCount(['usages as used_count' => fn ($q) => $q->whereNull('released_at')])->findOrFail($coupon->id);
    }
}
