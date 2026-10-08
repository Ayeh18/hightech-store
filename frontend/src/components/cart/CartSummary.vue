<script setup>
import AlertMessage from '@/components/ui/AlertMessage.vue'
import BaseButton from '@/components/ui/BaseButton.vue'

defineProps({
  subtotal: { type: Object, default: null },
  itemCount: { type: Number, default: 0 },
  checkoutReady: { type: Boolean, default: false },
  hasPriceChanges: { type: Boolean, default: false },
  busy: { type: Boolean, default: false },
})
defineEmits(['accept-prices', 'clear'])
</script>

<template>
  <section class="rounded-card border border-line bg-surface p-5" aria-labelledby="cart-summary-heading">
    <h2 id="cart-summary-heading" class="font-display text-lg font-semibold">Order summary</h2>

    <dl class="mt-4 space-y-2 text-sm">
      <div class="flex justify-between">
        <dt class="text-muted">Items</dt>
        <dd class="tabular-nums" data-testid="item-count">{{ itemCount }}</dd>
      </div>
      <div class="flex items-end justify-between border-t border-line pt-3">
        <dt class="text-muted">Subtotal</dt>
        <dd class="font-display text-2xl font-bold tabular-nums" data-testid="subtotal">
          {{ subtotal?.formatted ?? 'RM 0.00' }}
        </dd>
      </div>
    </dl>
    <p class="mt-2 text-xs text-muted">Shipping, discounts and payment are added at checkout.</p>

    <div class="mt-4 space-y-2.5" aria-live="polite">
      <AlertMessage v-if="hasPriceChanges" type="warning">
        <p>Some prices changed since you added them. The total above uses today's prices.</p>
        <button
          type="button"
          class="mt-1.5 font-semibold underline disabled:opacity-50"
          :disabled="busy"
          @click="$emit('accept-prices')"
        >
          Accept new prices
        </button>
      </AlertMessage>
      <AlertMessage v-if="!checkoutReady && itemCount > 0" type="error">
        Fix the items marked in red before you can check out.
      </AlertMessage>
      <AlertMessage v-else-if="itemCount > 0" type="success">Everything in your cart is available.</AlertMessage>
    </div>

    <BaseButton class="mt-4" block variant="ghost" :disabled="busy || itemCount === 0" @click="$emit('clear')">
      Clear cart
    </BaseButton>
  </section>
</template>
