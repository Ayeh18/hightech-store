<script setup>
defineProps({
  subtotal: { type: Object, required: true },
  discount: { type: Object, default: null },
  shipping: { type: Object, default: null }, // null: not known until an address is chosen
  total: { type: Object, required: true },
  couponCode: { type: String, default: null },
})
</script>

<template>
  <dl class="space-y-2 text-sm">
    <div class="flex justify-between">
      <dt class="text-muted">Subtotal</dt>
      <dd class="tabular-nums" data-testid="subtotal">{{ subtotal.formatted }}</dd>
    </div>
    <div v-if="discount && discount.amount > 0" class="flex justify-between text-success">
      <dt>
        Discount<span v-if="couponCode"> ({{ couponCode }})</span>
      </dt>
      <dd class="tabular-nums" data-testid="discount">−{{ discount.formatted }}</dd>
    </div>
    <div class="flex justify-between">
      <dt class="text-muted">Shipping</dt>
      <dd class="tabular-nums" data-testid="shipping">
        <template v-if="shipping === null">Choose an address</template>
        <template v-else-if="shipping.amount === 0">Free</template>
        <template v-else>{{ shipping.formatted }}</template>
      </dd>
    </div>
    <div class="flex items-end justify-between border-t border-line pt-3">
      <dt class="text-muted">Total</dt>
      <dd class="font-display text-2xl font-bold tabular-nums" data-testid="total">{{ total.formatted }}</dd>
    </div>
  </dl>
</template>
