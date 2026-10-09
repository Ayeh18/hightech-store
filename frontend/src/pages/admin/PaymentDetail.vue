<script setup>
import { onMounted, ref } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import AdminPage from '@/components/admin/AdminPage.vue'
import AdminRefundRow from '@/components/admin/AdminRefundRow.vue'
import OrderStatusBadge from '@/components/orders/OrderStatusBadge.vue'
import AlertMessage from '@/components/ui/AlertMessage.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { api } from '@/lib/api'
import { firstError } from '@/lib/errors'
import { whenFull } from '@/lib/format'
import { useAuthStore } from '@/stores/auth'

const route = useRoute()
const auth = useAuthStore()
const payment = ref(null)
const loading = ref(true)
const notFound = ref(false)
const error = ref('')
const busy = ref(false)
const actionError = ref('')

async function load() {
  loading.value = true
  error.value = ''
  try {
    payment.value = (await api.get(`/api/admin/payments/${route.params.id}`)).data.data
  } catch (e) {
    if (e.response?.status === 404) notFound.value = true
    else error.value = firstError(e)
  } finally {
    loading.value = false
  }
}
onMounted(load)

async function refundInFull() {
  busy.value = true
  actionError.value = ''
  try {
    await api.post(`/api/admin/payments/${payment.value.id}/refunds`, {
      amount: payment.value.refundable.amount,
      reason: 'late_payment',
    })
    await load()
  } catch (e) {
    actionError.value = firstError(e)
  } finally {
    busy.value = false
  }
}

// The diary's technical codes, said in words.
const STEP = {
  INITIATED: 'Payment page created',
  PROCESSING: 'Gateway is working on it',
  SUCCEEDED: 'Paid',
  FAILED: 'Failed',
  CANCELLED: 'Cancelled',
  AMOUNT_MISMATCH: 'Wrong amount reported (not accepted)',
  LATE_SUCCESS: 'Paid too late (needs a refund)',
  IGNORED: 'Repeat or out-of-order message (ignored)',
}
</script>

<template>
  <AdminPage :title="payment ? `Payment for ${payment.order.order_number}` : 'Payment'">
    <template #actions
      ><RouterLink :to="{ name: 'admin-payments' }" class="text-sm text-muted hover:text-ink"
        >← All payments</RouterLink
      ></template
    >

    <div
      v-if="loading && !payment"
      class="h-64 animate-pulse rounded-card bg-surface"
      aria-busy="true"
      aria-label="Loading the payment"
    />
    <EmptyState v-else-if="notFound" title="Payment not found" text="There is no payment with that id." />
    <div v-else-if="error" role="alert">
      <AlertMessage>{{ error }}</AlertMessage>
    </div>

    <div v-else-if="payment" class="space-y-4">
      <AlertMessage v-if="actionError">{{ actionError }}</AlertMessage>
      <AlertMessage v-if="payment.requires_refund" type="warning">
        <p class="font-medium">This money needs to go back to the customer</p>
        <p class="mt-0.5">It arrived after the order was cancelled, so there is nothing for it to pay for.</p>
        <BaseButton v-if="auth.can('refunds.manage')" class="mt-2 !px-4 !py-2" :loading="busy" @click="refundInFull"
          >Refund {{ payment.refundable.formatted }} in full</BaseButton
        >
      </AlertMessage>

      <section class="rounded-card border border-line bg-surface p-5" aria-labelledby="summary-heading">
        <h2 id="summary-heading" class="sr-only">Summary</h2>
        <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
          <div>
            <dt class="text-xs text-muted">Status</dt>
            <dd class="mt-1"><OrderStatusBadge :status="payment.status" kind="payment" /></dd>
          </div>
          <div>
            <dt class="text-xs text-muted">Amount</dt>
            <dd class="mt-1 font-semibold tabular-nums">{{ payment.amount.formatted }}</dd>
          </div>
          <div>
            <dt class="text-xs text-muted">Refunded so far</dt>
            <dd class="mt-1 tabular-nums">{{ payment.refunded_amount.formatted }}</dd>
          </div>
          <div>
            <dt class="text-xs text-muted">Still refundable</dt>
            <dd class="mt-1 tabular-nums">{{ payment.refundable.formatted }}</dd>
          </div>
          <div>
            <dt class="text-xs text-muted">Order</dt>
            <dd class="mt-1">
              <RouterLink
                :to="{ name: 'admin-order', params: { id: payment.order.id } }"
                class="text-accent hover:underline"
                >{{ payment.order.order_number }}</RouterLink
              >
            </dd>
          </div>
          <div>
            <dt class="text-xs text-muted">Customer</dt>
            <dd class="mt-1">
              {{ payment.order.customer?.name
              }}<span class="block text-xs text-muted">{{ payment.order.customer?.email }}</span>
            </dd>
          </div>
          <div>
            <dt class="text-xs text-muted">Gateway</dt>
            <dd class="mt-1">
              {{ payment.provider }}<span v-if="payment.method" class="text-muted"> · {{ payment.method }}</span>
            </dd>
          </div>
          <div>
            <dt class="text-xs text-muted">Gateway reference</dt>
            <dd class="mt-1 break-all font-mono text-xs">{{ payment.provider_reference ?? '—' }}</dd>
          </div>
        </dl>
      </section>

      <section class="rounded-card border border-line bg-surface" aria-labelledby="diary-heading">
        <h2 id="diary-heading" class="px-5 pt-5 font-display text-lg font-semibold">Diary</h2>
        <p class="px-5 pt-1 text-xs text-muted">
          Every step this payment went through. Entries are never edited or removed.
        </p>
        <div class="mt-3 overflow-x-auto">
          <table class="admin-table" data-testid="diary">
            <thead>
              <tr>
                <th>When</th>
                <th>What happened</th>
                <th>Status</th>
                <th class="text-right">Amount</th>
                <th>Note</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="entry in payment.transactions" :key="entry.id">
                <td class="whitespace-nowrap text-muted">{{ whenFull(entry.at) }}</td>
                <td>
                  {{ STEP[entry.type] ?? entry.type }}
                  <p v-if="entry.provider_event_id" class="font-mono text-[11px] text-muted">
                    {{ entry.provider_event_id }}
                  </p>
                </td>
                <td class="whitespace-nowrap text-muted">
                  {{ entry.status_before ?? '—' }} → {{ entry.status_after ?? '—' }}
                </td>
                <td class="text-right tabular-nums">{{ entry.amount?.formatted ?? '—' }}</td>
                <td class="text-muted">{{ entry.note ?? '' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section
        v-if="payment.refunds.length"
        class="rounded-card border border-line bg-surface p-5"
        aria-labelledby="refunds-heading"
      >
        <h2 id="refunds-heading" class="mb-3 font-display text-lg font-semibold">Refunds</h2>
        <div class="space-y-3">
          <AdminRefundRow v-for="refund in payment.refunds" :key="refund.id" :refund="refund" @changed="load" />
        </div>
      </section>
    </div>
  </AdminPage>
</template>
