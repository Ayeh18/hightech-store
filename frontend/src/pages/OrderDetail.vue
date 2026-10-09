<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import OrderTotals from '@/components/checkout/OrderTotals.vue'
import OrderLines from '@/components/orders/OrderLines.vue'
import OrderStatusBadge from '@/components/orders/OrderStatusBadge.vue'
import AlertMessage from '@/components/ui/AlertMessage.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { parseApiError } from '@/lib/api'
import { redirectTo } from '@/lib/redirect'
import { useOrderStore } from '@/stores/orders'

const store = useOrderStore()
const route = useRoute()

const order = ref(null)
const loading = ref(true)
const notFound = ref(false)
const error = ref('')
const confirmingCancel = ref(false)
const cancelling = ref(false)
const cancelError = ref('')
const paying = ref(false)
const payError = ref('')
const polls = ref(0)

// After paying, the payment company tells the shop in the background (a webhook), which can take a few seconds. Until
// then this page asks the server again every couple of seconds. The server is the only one who says the order is paid.
const POLL_MS = 2000
const POLL_LIMIT = 20
let timer = null

const lastPayment = computed(() => order.value?.payment ?? null)
const paymentOpen = computed(() => ['PENDING', 'PROCESSING'].includes(lastPayment.value?.status.value))
const isPaid = computed(() => order.value?.payment_status.value === 'PAID' && order.value.status.value !== 'CANCELLED')
// We wait when a payment is undecided AND the customer has just come back from the payment page, or the bank is working.
// Also when the payment page said "succeeded" but the server has not recorded it yet, even if the order looks closed:
// that is the payment that lands just after the order expired. The page's word is only a hint to keep waiting; what is
// shown as true always comes from the server.
const shouldWait = computed(() => {
  if (!order.value) return false
  const returned = route.query.payment === 'return'
  const undecided = paymentOpen.value && (returned || order.value.status.value === 'PAYMENT_PROCESSING')
  const expectingMoney = returned && route.query.result === 'succeeded' && !isPaid.value && !order.value.refund_pending

  return undecided || expectingMoney
})
const confirming = computed(() => shouldWait.value && polls.value < POLL_LIMIT)
const timedOut = computed(() => shouldWait.value && polls.value >= POLL_LIMIT)
// The money's real state when it arrived after the order was cancelled (the order's own payment status says "cancelled").
const paymentBadge = computed(() =>
  order.value.refund_pending ? { value: 'REFUND_PENDING', label: 'Refund pending' } : order.value.payment_status,
)
const payLabel = computed(() => {
  if (lastPayment.value?.status.value === 'PENDING') return 'Continue to payment'
  if (lastPayment.value?.status.value === 'FAILED') return 'Try paying again'
  return `Pay ${order.value.total.formatted}`
})

function stopPolling() {
  clearTimeout(timer)
  timer = null
}

function poll() {
  stopPolling()
  if (!confirming.value) return

  timer = setTimeout(async () => {
    polls.value += 1
    try {
      const before = order.value.status.value
      order.value = await store.fetchOrder(route.params.id)
      if (order.value.status.value !== before) store.reset() // the orders list is out of date now
    } catch {
      /* a failed check is not an answer: keep waiting */
    }
    poll()
  }, POLL_MS)
}

onBeforeUnmount(stopPolling)

async function pay() {
  paying.value = true
  payError.value = ''
  try {
    const payment = await store.startPayment(order.value.id)
    if (!payment.checkout_url) throw new Error('missing page')
    redirectTo(payment.checkout_url) // leaves the shop; the button stays busy until the page unloads
  } catch (e) {
    const parsed = parseApiError(e)
    payError.value = Object.values(parsed.errors).flat()[0] ?? parsed.message
    paying.value = false
    // The order may have changed under us (expired, or paid in another tab): show what is true now.
    if (e.response?.status === 422) load()
  }
}

async function load() {
  stopPolling()
  polls.value = 0
  loading.value = true
  notFound.value = false
  error.value = ''
  try {
    order.value = await store.fetchOrder(route.params.id)
    document.title = `${order.value.order_number} · HighTech Store`
    poll()
  } catch (e) {
    order.value = null
    if (e.response?.status === 404) notFound.value = true
    else error.value = parseApiError(e).message
  } finally {
    loading.value = false
  }
}

onMounted(load)

const time = (iso) => new Intl.DateTimeFormat('en-MY', { timeStyle: 'short' }).format(new Date(iso))
const when = (iso) => new Intl.DateTimeFormat('en-MY', { dateStyle: 'long', timeStyle: 'short' }).format(new Date(iso))
const address = computed(() => order.value?.shipping_address)

async function cancel() {
  cancelling.value = true
  cancelError.value = ''
  try {
    order.value = await store.cancel(order.value.id)
    confirmingCancel.value = false
    store.reset() // the list is out of date now
  } catch (e) {
    const parsed = parseApiError(e)
    cancelError.value = Object.values(parsed.errors).flat()[0] ?? parsed.message
  } finally {
    cancelling.value = false
  }
}
</script>

<template>
  <div class="mx-auto max-w-4xl px-4 py-8 sm:px-6">
    <div v-if="loading" class="animate-pulse space-y-4" aria-busy="true" aria-label="Loading your order">
      <div class="h-10 w-1/2 rounded bg-surface" />
      <div class="h-64 rounded-card bg-surface" />
    </div>

    <EmptyState v-else-if="notFound" title="Order not found" text="We couldn't find that order on your account.">
      <RouterLink :to="{ name: 'orders' }"><BaseButton>My orders</BaseButton></RouterLink>
    </EmptyState>

    <div v-else-if="error" role="alert">
      <EmptyState title="We couldn't load this order" :text="error"
        ><BaseButton @click="load">Try again</BaseButton></EmptyState
      >
    </div>

    <article v-else-if="order">
      <nav class="mb-4 text-sm text-muted" aria-label="Breadcrumb">
        <RouterLink :to="{ name: 'orders' }" class="hover:text-ink">My orders</RouterLink>
        <span class="mx-2" aria-hidden="true">/</span><span>{{ order.order_number }}</span>
      </nav>

      <header class="flex flex-wrap items-start justify-between gap-4">
        <div>
          <h1 class="font-display text-3xl font-semibold tracking-tight">{{ order.order_number }}</h1>
          <p class="mt-1 text-sm text-muted">Placed {{ when(order.placed_at) }}</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
          <OrderStatusBadge :status="order.status" />
          <OrderStatusBadge :status="paymentBadge" kind="payment" />
        </div>
      </header>

      <div class="mt-5 space-y-3">
        <AlertMessage v-if="order.refund_pending" type="warning">
          <p class="font-medium">We received your payment after this order was cancelled</p>
          <p class="mt-0.5">
            We couldn't keep your items, so the money will be refunded to you. You're welcome to place the order again.
          </p>
        </AlertMessage>

        <AlertMessage v-if="isPaid" type="success">
          <p class="font-medium">
            Payment received<template v-if="order.paid_at"> on {{ when(order.paid_at) }}</template>
          </p>
          <p class="mt-0.5">Thank you! We'll start preparing your order.</p>
        </AlertMessage>

        <AlertMessage v-else-if="confirming" type="warning">
          <p class="font-medium">Confirming your payment…</p>
          <p class="mt-0.5">
            This usually takes a few seconds. You don't need to do anything, and please don't pay again.
          </p>
        </AlertMessage>
        <AlertMessage v-else-if="timedOut" type="warning">
          <p class="font-medium">We haven't had an answer from the payment company yet</p>
          <p class="mt-0.5">
            If you paid, this order will update shortly. Refresh this page in a minute, and please don't pay again.
          </p>
        </AlertMessage>
        <template v-else-if="order.can_pay">
          <AlertMessage v-if="lastPayment?.status.value === 'FAILED'">
            <p class="font-medium">Your payment didn't go through</p>
            <p class="mt-0.5">
              <template v-if="lastPayment.failure_reason">{{ lastPayment.failure_reason }}. </template>Your items are
              still reserved until {{ time(order.expires_at) }}, so you can try again.
            </p>
          </AlertMessage>
          <AlertMessage v-else type="warning">
            <p class="font-medium">Waiting for payment</p>
            <p class="mt-0.5">
              Your items are reserved until {{ time(order.expires_at) }}. After that the order is cancelled
              automatically and the items go back on sale.
            </p>
          </AlertMessage>
        </template>
        <AlertMessage v-else-if="order.status.value === 'CANCELLED'" type="error">
          This order was cancelled<template v-if="order.cancelled_at"> on {{ when(order.cancelled_at) }}</template
          ><template v-if="!order.refund_pending">. Nothing was charged.</template><template v-else>.</template>
        </AlertMessage>
      </div>

      <div class="mt-6 grid items-start gap-6 lg:grid-cols-[1fr_20rem]">
        <section class="rounded-card border border-line bg-surface p-5" aria-labelledby="items-heading">
          <h2 id="items-heading" class="font-display text-lg font-semibold">Items</h2>
          <OrderLines :lines="order.lines" class="mt-2" />
        </section>

        <div class="space-y-4">
          <section class="rounded-card border border-line bg-surface p-5" aria-labelledby="totals-heading">
            <h2 id="totals-heading" class="mb-3 font-display text-lg font-semibold">Payment summary</h2>
            <OrderTotals
              :subtotal="order.subtotal"
              :discount="order.discount_total"
              :shipping="order.shipping_total"
              :total="order.total"
              :coupon-code="order.coupon_code"
            />
          </section>

          <section class="rounded-card border border-line bg-surface p-5 text-sm" aria-labelledby="ship-heading">
            <h2 id="ship-heading" class="mb-2 font-display text-lg font-semibold">Delivery address</h2>
            <p class="font-medium">{{ address.name }}</p>
            <p class="text-muted">{{ address.phone }}</p>
            <p class="mt-1 text-muted">
              {{ address.line1 }}<template v-if="address.line2">, {{ address.line2 }}</template
              ><br />
              {{ address.postcode }} {{ address.city }}, {{ address.state }}
            </p>
            <p v-if="order.notes" class="mt-3 border-t border-line pt-3 text-xs text-muted">
              Your note: {{ order.notes }}
            </p>
          </section>

          <div v-if="order.can_pay" class="space-y-2">
            <AlertMessage v-if="payError">{{ payError }}</AlertMessage>
            <BaseButton block :loading="paying" :disabled="confirming" @click="pay">{{ payLabel }}</BaseButton>
          </div>

          <BaseButton v-if="order.can_cancel" block variant="secondary" @click="confirmingCancel = true"
            >Cancel order</BaseButton
          >
        </div>
      </div>

      <BaseModal :open="confirmingCancel" title="Cancel this order?" @close="confirmingCancel = false">
        <div class="space-y-3 p-5 text-sm">
          <AlertMessage v-if="cancelError">{{ cancelError }}</AlertMessage>
          <p>
            Order <span class="font-semibold">{{ order.order_number }}</span> will be cancelled and your items released.
            You can order again at any time.
          </p>
        </div>
        <template #footer>
          <div class="flex justify-end gap-3">
            <BaseButton variant="ghost" @click="confirmingCancel = false">Keep order</BaseButton>
            <BaseButton class="!bg-danger" :loading="cancelling" @click="cancel">Cancel order</BaseButton>
          </div>
        </template>
      </BaseModal>
    </article>
  </div>
</template>
