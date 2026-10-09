<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import AddressPicker from '@/components/checkout/AddressPicker.vue'
import CouponBox from '@/components/checkout/CouponBox.vue'
import OrderTotals from '@/components/checkout/OrderTotals.vue'
import AlertMessage from '@/components/ui/AlertMessage.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { parseApiError } from '@/lib/api'
import { useAddressStore } from '@/stores/addresses'
import { useCartStore } from '@/stores/cart'
import { useCheckoutStore } from '@/stores/checkout'

const cart = useCartStore()
const addresses = useAddressStore()
const checkout = useCheckoutStore()
const router = useRouter()

const loading = ref(true)
const reasons = ref([]) // why the server refused the order, in its own words

const quote = computed(() => checkout.quote)
const emptyCart = computed(() => !loading.value && cart.loaded && cart.items.length === 0)

// How far the customer is from free shipping: only a hint. The server decides the real shipping cost.
const nearFreeShipping = computed(() => {
  if (!quote.value || quote.value.shipping === null || quote.value.shipping.amount === 0) return null
  const spent = quote.value.subtotal.amount - quote.value.discount.amount
  const threshold = quote.value.free_shipping_over.amount
  const missing = threshold - spent
  return missing > 0 ? { sen: missing, text: formatRinggit(missing) } : null
})
const formatRinggit = (sen) =>
  `RM ${Math.trunc(sen / 100).toLocaleString('en-MY')}.${String(sen % 100).padStart(2, '0')}`

onMounted(async () => {
  // Re-read everything from the server: the cart may have changed since the customer last looked.
  await Promise.all([cart.fetch(), addresses.fetch()])
  const chosen = addresses.defaultAddress
  if (chosen && !checkout.addressId) checkout.addressId = chosen.id
  if (cart.items.length) await checkout.refreshQuote()
  loading.value = false
})

// Picking another address (or adding one) re-prices the order: shipping depends on the state.
watch(
  () => checkout.addressId,
  (id, previous) => {
    if (!loading.value && id !== previous) checkout.refreshQuote()
  },
)

async function placeOrder() {
  reasons.value = []
  try {
    const order = await checkout.place()
    await cart.fetch() // the cart was emptied by the order
    router.push({ name: 'order', params: { id: order.id } })
  } catch (e) {
    const parsed = parseApiError(e)
    const list = Object.entries(parsed.errors)
      .filter(([field]) => field !== 'coupon_code')
      .flatMap(([, messages]) => messages)
    reasons.value = list.length ? list : [parsed.message]
    await Promise.all([cart.fetch(), checkout.refreshQuote()]) // show what changed
  }
}
</script>

<template>
  <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6">
    <header class="mb-6">
      <h1 class="font-display text-3xl font-semibold tracking-tight">Checkout</h1>
      <p class="mt-1 text-sm text-muted">
        Your total is calculated by the store from today's prices. Nothing is charged on this page.
      </p>
    </header>

    <div
      v-if="loading"
      class="grid animate-pulse gap-6 lg:grid-cols-[1fr_24rem]"
      aria-busy="true"
      aria-label="Loading checkout"
    >
      <div class="space-y-4">
        <div class="h-48 rounded-card bg-surface" />
        <div class="h-32 rounded-card bg-surface" />
      </div>
      <div class="h-80 rounded-card bg-surface" />
    </div>

    <EmptyState v-else-if="emptyCart" title="Your cart is empty" text="Add something to your cart before checking out.">
      <RouterLink :to="{ name: 'products' }"><BaseButton>Browse parts</BaseButton></RouterLink>
    </EmptyState>

    <div v-else-if="cart.error && !cart.loaded" role="alert">
      <EmptyState title="We couldn't load your checkout" :text="cart.error"
        ><BaseButton @click="$router.go(0)">Try again</BaseButton></EmptyState
      >
    </div>

    <div v-else class="grid items-start gap-6 lg:grid-cols-[1fr_24rem]">
      <div class="space-y-6">
        <section class="rounded-card border border-line bg-surface p-5" aria-labelledby="delivery-heading">
          <h2 id="delivery-heading" class="mb-4 font-display text-lg font-semibold">1. Delivery address</h2>
          <AddressPicker v-model="checkout.addressId" />
        </section>

        <section class="rounded-card border border-line bg-surface p-5" aria-labelledby="items-heading">
          <div class="mb-3 flex items-center justify-between">
            <h2 id="items-heading" class="font-display text-lg font-semibold">2. Your items</h2>
            <RouterLink :to="{ name: 'cart' }" class="text-sm text-accent hover:underline">Edit cart</RouterLink>
          </div>
          <ul class="divide-y divide-line text-sm">
            <li v-for="line in cart.items" :key="line.id" class="flex items-start justify-between gap-4 py-2.5">
              <div class="min-w-0">
                <p class="font-medium">
                  <span
                    v-if="line.kind === 'build'"
                    class="mr-1.5 rounded-md bg-accent-soft px-1.5 py-0.5 text-xs font-normal text-accent"
                    >PC</span
                  >{{ line.name }}
                </p>
                <p class="text-xs text-muted">
                  Qty {{ line.quantity
                  }}<template v-if="line.kind === 'build'"> · {{ line.components.length }} parts</template>
                </p>
              </div>
              <p class="shrink-0 tabular-nums">{{ line.unit_price ? line.line_total.formatted : '—' }}</p>
            </li>
          </ul>
        </section>

        <section class="rounded-card border border-line bg-surface p-5" aria-labelledby="notes-heading">
          <h2 id="notes-heading" class="mb-3 font-display text-lg font-semibold">
            3. Note for the store <span class="text-sm font-normal text-muted">(optional)</span>
          </h2>
          <label for="order-notes" class="sr-only">Note for the store</label>
          <textarea
            id="order-notes"
            v-model="checkout.notes"
            rows="2"
            maxlength="500"
            placeholder="Delivery instructions, building access…"
            class="w-full rounded-xl border border-line bg-bg px-4 py-2.5 text-sm text-ink placeholder:text-muted/70 focus:border-accent focus:outline-none"
          />
        </section>
      </div>

      <aside class="space-y-4 lg:sticky lg:top-24" aria-label="Order summary">
        <section class="rounded-card border border-line bg-surface p-5" aria-labelledby="summary-heading">
          <h2 id="summary-heading" class="mb-4 font-display text-lg font-semibold">Order summary</h2>

          <CouponBox
            :applied-code="quote?.coupon.applied ? quote.coupon.code : null"
            :error="checkout.couponError"
            :busy="checkout.quoting"
            class="mb-5"
            @apply="checkout.applyCoupon($event)"
            @remove="checkout.applyCoupon('')"
          />

          <OrderTotals
            v-if="quote"
            :subtotal="quote.subtotal"
            :discount="quote.discount"
            :shipping="quote.shipping"
            :total="quote.total"
            :coupon-code="quote.coupon.applied ? quote.coupon.code : null"
          />
          <p v-if="nearFreeShipping" class="mt-3 rounded-lg bg-bg px-3 py-2 text-xs text-muted">
            Add {{ nearFreeShipping.text }} more to get free shipping.
          </p>

          <div class="mt-4 space-y-2.5" aria-live="polite">
            <AlertMessage v-if="checkout.error">{{ checkout.error }}</AlertMessage>
            <AlertMessage v-if="reasons.length">
              <p class="font-medium">We couldn't place your order:</p>
              <ul class="mt-1 list-disc space-y-0.5 pl-5">
                <li v-for="reason in reasons" :key="reason">{{ reason }}</li>
              </ul>
            </AlertMessage>
            <AlertMessage v-else-if="quote && quote.blockers.length" type="warning">
              <ul class="list-disc space-y-0.5 pl-5">
                <li v-for="blocker in quote.blockers" :key="blocker">{{ blocker }}</li>
              </ul>
            </AlertMessage>
          </div>

          <BaseButton class="mt-4" block :disabled="!checkout.canPlace" :loading="checkout.placing" @click="placeOrder"
            >Place order</BaseButton
          >
          <p class="mt-2 text-center text-xs text-muted">
            Placing the order reserves your items for {{ quote?.payment_window_minutes ?? 30 }} minutes. You can cancel
            it until it is paid.
          </p>
        </section>
      </aside>
    </div>
  </div>
</template>
