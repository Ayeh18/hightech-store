<script setup>
import { computed, onMounted, ref } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import OrderTotals from '@/components/checkout/OrderTotals.vue'
import OrderLines from '@/components/orders/OrderLines.vue'
import OrderStatusBadge from '@/components/orders/OrderStatusBadge.vue'
import AlertMessage from '@/components/ui/AlertMessage.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { parseApiError } from '@/lib/api'
import { useOrderStore } from '@/stores/orders'

const store = useOrderStore()
const route = useRoute()

const order = ref(null)
const loading = ref(true)
const notFound = ref(false)
const error = ref('')
const confirming = ref(false)
const cancelling = ref(false)
const cancelError = ref('')

async function load() {
  loading.value = true
  notFound.value = false
  error.value = ''
  try {
    order.value = await store.fetchOrder(route.params.id)
    document.title = `${order.value.order_number} · HighTech Store`
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
    confirming.value = false
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
          <OrderStatusBadge :status="order.payment_status" kind="payment" />
        </div>
      </header>

      <div class="mt-5 space-y-3">
        <AlertMessage v-if="order.can_cancel" type="warning">
          <p class="font-medium">Waiting for payment</p>
          <p class="mt-0.5">
            Your items are reserved until {{ time(order.expires_at) }}. After that the order is cancelled automatically
            and the items go back on sale. Online payment is not available yet in this demo.
          </p>
        </AlertMessage>
        <AlertMessage v-else-if="order.status.value === 'CANCELLED'" type="error">
          This order was cancelled<template v-if="order.cancelled_at"> on {{ when(order.cancelled_at) }}</template
          >. Nothing was charged.
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

          <BaseButton v-if="order.can_cancel" block variant="secondary" @click="confirming = true"
            >Cancel order</BaseButton
          >
        </div>
      </div>

      <BaseModal :open="confirming" title="Cancel this order?" @close="confirming = false">
        <div class="space-y-3 p-5 text-sm">
          <AlertMessage v-if="cancelError">{{ cancelError }}</AlertMessage>
          <p>
            Order <span class="font-semibold">{{ order.order_number }}</span> will be cancelled and your items released.
            You can order again at any time.
          </p>
        </div>
        <template #footer>
          <div class="flex justify-end gap-3">
            <BaseButton variant="ghost" @click="confirming = false">Keep order</BaseButton>
            <BaseButton class="!bg-danger" :loading="cancelling" @click="cancel">Cancel order</BaseButton>
          </div>
        </template>
      </BaseModal>
    </article>
  </div>
</template>
