<script setup>
import { computed, onMounted, ref } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import AdminPage from '@/components/admin/AdminPage.vue'
import AdminRefundRow from '@/components/admin/AdminRefundRow.vue'
import FormSelect from '@/components/admin/FormSelect.vue'
import FormTextarea from '@/components/admin/FormTextarea.vue'
import OrderTotals from '@/components/checkout/OrderTotals.vue'
import OrderLines from '@/components/orders/OrderLines.vue'
import OrderStatusBadge from '@/components/orders/OrderStatusBadge.vue'
import OrderTimeline from '@/components/orders/OrderTimeline.vue'
import AlertMessage from '@/components/ui/AlertMessage.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { api } from '@/lib/api'
import { CARRIERS } from '@/lib/adminOptions'
import { firstError } from '@/lib/errors'
import { whenFull } from '@/lib/format'
import { parseRinggit } from '@/lib/money'
import { useAuthStore } from '@/stores/auth'

const route = useRoute()
const auth = useAuthStore()

const order = ref(null)
const loading = ref(true)
const notFound = ref(false)
const error = ref('')
const busy = ref(false)
const actionError = ref('')
const notice = ref('')

async function load() {
  loading.value = true
  error.value = ''
  try {
    const { data } = await api.get(`/api/admin/orders/${route.params.id}`)
    order.value = data.data
    document.title = `${order.value.order_number} · Admin · HighTech Store`
  } catch (e) {
    order.value = null
    if (e.response?.status === 404) notFound.value = true
    else error.value = firstError(e)
  } finally {
    loading.value = false
  }
}
onMounted(load)

// ---------- fulfilment: the one next step (the server refuses anything out of order anyway)
const NEXT_STEP = {
  PAID: { label: 'Start preparing', path: 'status', body: { status: 'PROCESSING' } },
  PROCESSING: { label: 'Mark as packed', path: 'status', body: { status: 'PACKED' } },
  PACKED: { label: 'Ship…', dialog: 'ship' },
  SHIPPED: { label: 'Mark as delivered', path: 'shipment/delivered', body: {} },
}
const nextStep = computed(() => NEXT_STEP[order.value?.status.value] ?? null)
const cancellable = computed(() => ['PAID', 'PROCESSING', 'PACKED'].includes(order.value?.status.value))
const refundablePayment = computed(
  () => order.value?.payments.find((p) => p.status.value === 'PAID' && p.refundable.amount > 0) ?? null,
)
// While a customer's request waits for a decision, decide that first: a smaller refund in between would make it
// impossible to approve (the server refuses it too).
const requestWaiting = computed(() => order.value?.refunds.some((r) => r.status.value === 'REQUESTED') ?? false)

async function run(action) {
  busy.value = true
  actionError.value = ''
  notice.value = ''
  try {
    await action()
    return true
  } catch (e) {
    actionError.value = firstError(e)
    return false
  } finally {
    busy.value = false
  }
}

const advance = (step) =>
  run(async () => {
    const { data } = await api.post(`/api/admin/orders/${order.value.id}/${step.path}`, step.body)
    order.value = data.data
  })

// ---------- dialogs
const dialog = ref(null) // 'ship' | 'cancel' | 'refund'
const carrier = ref(CARRIERS[0])
const otherCarrier = ref('')
const tracking = ref('')
const note = ref('')
const amount = ref('')
const amountError = ref('')

function open(kind) {
  dialog.value = kind
  actionError.value = ''
  amountError.value = ''
  note.value = ''
  amount.value = ''
}

const ship = () =>
  run(async () => {
    const { data } = await api.post(`/api/admin/orders/${order.value.id}/shipment`, {
      carrier: carrier.value === 'Other' ? otherCarrier.value.trim() : carrier.value,
      tracking_number: tracking.value.trim(),
    })
    order.value = data.data
    dialog.value = null
    tracking.value = ''
  })

/** The server answers "refund asked of the payment company" with the refund; FAILED is a result to show, not hide. */
async function reportRefund(refund) {
  if (refund.status.value === 'FAILED')
    notice.value = `The payment company did not complete the refund: ${refund.failure_reason}. It is saved, and can be retried below.`
  await load()
}

const cancelAndRefund = () =>
  run(async () => {
    const { data } = await api.post(
      `/api/admin/orders/${order.value.id}/cancel-and-refund`,
      note.value.trim() ? { note: note.value.trim() } : {},
    )
    dialog.value = null
    await reportRefund(data.data)
  })

const refundAmount = () => {
  const sen = parseRinggit(amount.value)
  if (!sen || sen < 1) {
    amountError.value = 'Enter an amount in ringgit, for example 25.50'
    return
  }
  amountError.value = ''
  return run(async () => {
    const { data } = await api.post(`/api/admin/payments/${refundablePayment.value.id}/refunds`, {
      amount: sen,
      reason: 'goodwill',
      ...(note.value.trim() ? { note: note.value.trim() } : {}),
    })
    dialog.value = null
    await reportRefund(data.data)
  })
}

const refundLate = (payment) =>
  run(async () => {
    const { data } = await api.post(`/api/admin/payments/${payment.id}/refunds`, {
      amount: payment.refundable.amount,
      reason: 'late_payment',
    })
    await reportRefund(data.data)
  })

const address = computed(() => order.value?.shipping_address)
</script>

<template>
  <AdminPage :title="order?.order_number ?? 'Order'">
    <template #actions>
      <RouterLink :to="{ name: 'admin-orders' }" class="text-sm text-muted hover:text-ink">← All orders</RouterLink>
    </template>

    <div
      v-if="loading && !order"
      class="h-72 animate-pulse rounded-card bg-surface"
      aria-busy="true"
      aria-label="Loading the order"
    />
    <EmptyState v-else-if="notFound" title="Order not found" text="There is no order with that number."
      ><RouterLink :to="{ name: 'admin-orders' }"><BaseButton>All orders</BaseButton></RouterLink></EmptyState
    >
    <div v-else-if="error" role="alert">
      <AlertMessage>{{ error }}</AlertMessage
      ><BaseButton class="mt-3" variant="secondary" @click="load">Try again</BaseButton>
    </div>

    <div v-else-if="order" class="space-y-4">
      <div class="flex flex-wrap items-center gap-2">
        <OrderStatusBadge :status="order.status" />
        <OrderStatusBadge :status="order.payment_status" kind="payment" />
        <span class="text-sm text-muted"
          >Placed {{ whenFull(order.placed_at)
          }}<template v-if="order.paid_at"> · paid {{ whenFull(order.paid_at) }}</template></span
        >
      </div>

      <AlertMessage v-if="actionError">{{ actionError }}</AlertMessage>
      <AlertMessage v-if="notice" type="warning">{{ notice }}</AlertMessage>
      <AlertMessage v-if="order.refund_pending" type="warning">
        A payment arrived after this order was cancelled. It needs to be refunded (see Payments below).
      </AlertMessage>

      <div class="grid items-start gap-4 xl:grid-cols-[1fr_22rem]">
        <div class="space-y-4">
          <section class="rounded-card border border-line bg-surface p-5" aria-labelledby="items-heading">
            <h2 id="items-heading" class="font-display text-lg font-semibold">Items</h2>
            <OrderLines :lines="order.lines" class="mt-2" />
          </section>

          <section class="rounded-card border border-line bg-surface p-5" aria-labelledby="payments-heading">
            <h2 id="payments-heading" class="mb-3 font-display text-lg font-semibold">Payments</h2>
            <p v-if="!order.payments.length" class="text-sm text-muted">No payment has been started for this order.</p>
            <ul class="space-y-2">
              <li
                v-for="payment in order.payments"
                :key="payment.id"
                class="rounded-xl border border-line p-3 text-sm"
                data-testid="payment-row"
              >
                <div class="flex flex-wrap items-center justify-between gap-2">
                  <span class="font-semibold tabular-nums">{{ payment.amount.formatted }}</span>
                  <OrderStatusBadge :status="payment.status" kind="payment" />
                </div>
                <p class="mt-1 text-xs text-muted">
                  {{ payment.provider }}<template v-if="payment.method"> · {{ payment.method }}</template>
                  <template v-if="payment.paid_at"> · paid {{ whenFull(payment.paid_at) }}</template>
                  <template v-if="payment.refunded_amount.amount">
                    · {{ payment.refunded_amount.formatted }} refunded</template
                  >
                </p>
                <p v-if="payment.failure_reason" class="mt-1 text-xs text-danger">{{ payment.failure_reason }}</p>
                <div v-if="payment.requires_refund" class="mt-2 space-y-2">
                  <AlertMessage type="warning"
                    >This money came in but the order could not use it. Send it back.</AlertMessage
                  >
                  <BaseButton
                    v-if="auth.can('refunds.manage')"
                    variant="secondary"
                    class="!px-4 !py-2"
                    :loading="busy"
                    @click="refundLate(payment)"
                    >Refund {{ payment.refundable.formatted }} in full</BaseButton
                  >
                </div>
                <RouterLink
                  v-if="auth.can('payments.view')"
                  :to="{ name: 'admin-payment', params: { id: payment.id } }"
                  class="mt-2 inline-block text-xs text-accent hover:underline"
                  >Payment diary →</RouterLink
                >
              </li>
            </ul>
          </section>

          <section
            v-if="order.refunds.length"
            class="rounded-card border border-line bg-surface p-5"
            aria-labelledby="refunds-heading"
          >
            <h2 id="refunds-heading" class="mb-3 font-display text-lg font-semibold">Refunds</h2>
            <div class="space-y-3">
              <AdminRefundRow v-for="refund in order.refunds" :key="refund.id" :refund="refund" @changed="load" />
            </div>
          </section>

          <section class="rounded-card border border-line bg-surface p-5" aria-labelledby="progress-heading">
            <h2 id="progress-heading" class="mb-4 font-display text-lg font-semibold">Order progress</h2>
            <OrderTimeline :entries="order.timeline" />
          </section>
        </div>

        <div class="space-y-4">
          <section
            class="rounded-card border border-line bg-surface p-5"
            aria-labelledby="actions-heading"
            data-testid="actions"
          >
            <h2 id="actions-heading" class="mb-3 font-display text-lg font-semibold">Actions</h2>
            <div class="space-y-2">
              <BaseButton
                v-if="nextStep"
                block
                :loading="busy"
                @click="nextStep.dialog ? open(nextStep.dialog) : advance(nextStep)"
                >{{ nextStep.label }}</BaseButton
              >
              <p v-else class="text-sm text-muted">Nothing to do for an order that is “{{ order.status.label }}”.</p>
              <template v-if="auth.can('refunds.manage')">
                <BaseButton v-if="cancellable" block variant="secondary" @click="open('cancel')"
                  >Cancel and refund…</BaseButton
                >
                <BaseButton
                  v-if="refundablePayment && !requestWaiting"
                  block
                  variant="secondary"
                  @click="open('refund')"
                  >Refund an amount…</BaseButton
                >
              </template>
            </div>
          </section>

          <section class="rounded-card border border-line bg-surface p-5 text-sm" aria-labelledby="customer-heading">
            <h2 id="customer-heading" class="mb-2 font-display text-lg font-semibold">Customer</h2>
            <p class="font-medium">{{ order.customer.name }}</p>
            <p class="text-muted">{{ order.customer.email }}</p>
          </section>

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

          <section
            v-if="order.shipment"
            class="rounded-card border border-line bg-surface p-5 text-sm"
            aria-labelledby="shipment-heading"
            data-testid="shipment"
          >
            <h2 id="shipment-heading" class="mb-2 font-display text-lg font-semibold">Shipment</h2>
            <p class="text-muted">{{ order.shipment.carrier }}</p>
            <p class="font-medium tabular-nums">{{ order.shipment.tracking_number }}</p>
            <p class="mt-1 text-xs text-muted">
              Shipped {{ whenFull(order.shipment.shipped_at)
              }}<template v-if="order.shipment.delivered_at">
                · delivered {{ whenFull(order.shipment.delivered_at) }}</template
              >
            </p>
          </section>

          <section class="rounded-card border border-line bg-surface p-5 text-sm" aria-labelledby="address-heading">
            <h2 id="address-heading" class="mb-2 font-display text-lg font-semibold">Delivery address</h2>
            <p class="font-medium">{{ address.name }}</p>
            <p class="text-muted">{{ address.phone }}</p>
            <p class="mt-1 text-muted">
              {{ address.line1 }}<template v-if="address.line2">, {{ address.line2 }}</template
              ><br />{{ address.postcode }} {{ address.city }}, {{ address.state }}
            </p>
            <p v-if="order.notes" class="mt-3 border-t border-line pt-3 text-xs text-muted">
              Customer note: {{ order.notes }}
            </p>
          </section>
        </div>
      </div>

      <BaseModal :open="dialog === 'ship'" title="Ship this order" @close="dialog = null">
        <form id="ship-form" class="space-y-4 p-5 text-sm" @submit.prevent="ship">
          <AlertMessage v-if="actionError">{{ actionError }}</AlertMessage>
          <p>The customer is emailed the tracking number.</p>
          <FormSelect v-model="carrier" label="Carrier" :options="CARRIERS.map((c) => ({ value: c, label: c }))" />
          <BaseInput v-if="carrier === 'Other'" v-model="otherCarrier" label="Carrier name" required />
          <BaseInput v-model="tracking" label="Tracking number" required placeholder="For example JT123456789MY" />
        </form>
        <template #footer>
          <div class="flex justify-end gap-3">
            <BaseButton variant="ghost" @click="dialog = null">Not now</BaseButton>
            <BaseButton
              type="submit"
              form="ship-form"
              :loading="busy"
              :disabled="!tracking.trim() || (carrier === 'Other' && !otherCarrier.trim())"
              >Mark as shipped</BaseButton
            >
          </div>
        </template>
      </BaseModal>

      <BaseModal :open="dialog === 'cancel'" title="Cancel and refund this order" @close="dialog = null">
        <form id="cancel-form" class="space-y-4 p-5 text-sm" @submit.prevent="cancelAndRefund">
          <AlertMessage v-if="actionError">{{ actionError }}</AlertMessage>
          <p>
            The order is cancelled,
            <span class="font-semibold">{{ refundablePayment?.refundable.formatted ?? order.total.formatted }}</span> is
            refunded in full to the customer, and the goods go back on the shelf. This cannot be undone.
          </p>
          <FormTextarea v-model="note" label="Note for the record (optional)" :rows="2" />
        </form>
        <template #footer>
          <div class="flex justify-end gap-3">
            <BaseButton variant="ghost" @click="dialog = null">Keep the order</BaseButton>
            <BaseButton type="submit" form="cancel-form" class="!bg-danger" :loading="busy"
              >Cancel and refund</BaseButton
            >
          </div>
        </template>
      </BaseModal>

      <BaseModal :open="dialog === 'refund'" title="Refund an amount" @close="dialog = null">
        <form id="refund-form" class="space-y-4 p-5 text-sm" @submit.prevent="refundAmount">
          <AlertMessage v-if="actionError">{{ actionError }}</AlertMessage>
          <p>
            Up to <span class="font-semibold">{{ refundablePayment?.refundable.formatted }}</span> can still be refunded
            on this payment. The order itself is not changed.
          </p>
          <BaseInput v-model="amount" label="Amount (RM)" required placeholder="25.50" :error="amountError" />
          <FormTextarea v-model="note" label="Reason for the record (optional)" :rows="2" />
        </form>
        <template #footer>
          <div class="flex justify-end gap-3">
            <BaseButton variant="ghost" @click="dialog = null">Not now</BaseButton>
            <BaseButton type="submit" form="refund-form" :loading="busy" :disabled="!amount.trim()">Refund</BaseButton>
          </div>
        </template>
      </BaseModal>
    </div>
  </AdminPage>
</template>
