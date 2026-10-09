<script setup>
import { onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import AdminPage from '@/components/admin/AdminPage.vue'
import AdminRefundRow from '@/components/admin/AdminRefundRow.vue'
import FormSelect from '@/components/admin/FormSelect.vue'
import AlertMessage from '@/components/ui/AlertMessage.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import { useAdminList } from '@/composables/useAdminList'
import { api } from '@/lib/api'
import { firstError } from '@/lib/errors'
import { whenFull } from '@/lib/format'

const STATUSES = [
  { value: 'REQUESTED', label: 'Waiting for a decision' },
  { value: 'PROCESSING', label: 'Being sent' },
  { value: 'FAILED', label: 'Failed (can be retried)' },
  { value: 'SUCCEEDED', label: 'Refunded' },
  { value: 'REJECTED', label: 'Declined' },
]

const list = useAdminList('/api/admin/refunds', { status: 'REQUESTED' })

// Money that arrived for an order that was already cancelled.
const late = ref([])
const lateError = ref('')
const busy = ref('')
const notice = ref('')

async function loadLate() {
  try {
    late.value = (await api.get('/api/admin/payments/needing-refund')).data.data
  } catch (e) {
    lateError.value = firstError(e)
  }
}
onMounted(loadLate)

async function refundLate(payment) {
  busy.value = payment.id
  notice.value = ''
  lateError.value = ''
  try {
    const { data } = await api.post(`/api/admin/payments/${payment.id}/refunds`, {
      amount: payment.refundable.amount,
      reason: 'late_payment',
    })
    if (data.data.status.value === 'FAILED')
      notice.value = `The payment company did not complete it: ${data.data.failure_reason}. It is saved as a failed refund and can be retried from the list above.`
    await Promise.all([loadLate(), list.reload()])
  } catch (e) {
    lateError.value = firstError(e)
  } finally {
    busy.value = ''
  }
}
</script>

<template>
  <AdminPage
    title="Refunds"
    subtitle="Customer requests waiting for a decision, refunds that went wrong, and money that arrived too late."
  >
    <div class="mb-4 max-w-xs">
      <FormSelect v-model="list.params.status" label="Show" :options="STATUSES" placeholder="All refunds" />
    </div>

    <AlertMessage v-if="list.error.value" class="mb-4">{{ list.error.value }}</AlertMessage>
    <AlertMessage v-if="notice" type="warning" class="mb-4">{{ notice }}</AlertMessage>

    <div
      v-if="!list.loaded.value && list.loading.value"
      class="h-40 animate-pulse rounded-card bg-surface"
      aria-busy="true"
      aria-label="Loading refunds"
    />
    <EmptyState
      v-else-if="list.loaded.value && !list.rows.value.length"
      title="Nothing here"
      text="No refunds match this view."
    />
    <div v-else class="space-y-3" :class="{ 'opacity-60 transition': list.loading.value }" data-testid="refund-list">
      <AdminRefundRow
        v-for="refund in list.rows.value"
        :key="refund.id"
        :refund="refund"
        show-order
        @changed="list.reload"
      />
    </div>
    <div class="mt-4">
      <PaginationBar :page="list.meta.value.current_page" :last-page="list.meta.value.last_page" @change="list.load" />
    </div>

    <section class="mt-10" aria-labelledby="late-heading">
      <h2 id="late-heading" class="font-display text-xl font-semibold">Payments that need to be sent back</h2>
      <p class="mt-1 text-sm text-muted">
        The customer paid after the order was cancelled (for example when the payment window ran out), so the money has
        nothing to buy.
      </p>
      <AlertMessage v-if="lateError" class="mt-3">{{ lateError }}</AlertMessage>
      <p v-else-if="!late.length" class="mt-3 text-sm text-muted" data-testid="no-late">Nothing to send back.</p>
      <ul v-else class="mt-3 space-y-3" data-testid="late-list">
        <li
          v-for="payment in late"
          :key="payment.id"
          class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-line p-4 text-sm"
        >
          <div>
            <RouterLink
              :to="{ name: 'admin-order', params: { id: payment.order.id } }"
              class="font-medium text-accent hover:underline"
              >{{ payment.order.order_number }}</RouterLink
            >
            <p class="text-xs text-muted">{{ payment.order.customer?.name }} · paid {{ whenFull(payment.paid_at) }}</p>
          </div>
          <div class="flex items-center gap-3">
            <span class="font-semibold tabular-nums">{{ payment.refundable.formatted }}</span>
            <BaseButton class="!px-4 !py-2" :loading="busy === payment.id" @click="refundLate(payment)"
              >Refund in full</BaseButton
            >
          </div>
        </li>
      </ul>
    </section>
  </AdminPage>
</template>
