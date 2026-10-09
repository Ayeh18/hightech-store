<script setup>
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import AdminPage from '@/components/admin/AdminPage.vue'
import FormCheckbox from '@/components/admin/FormCheckbox.vue'
import StatCard from '@/components/admin/StatCard.vue'
import OrderStatusBadge from '@/components/orders/OrderStatusBadge.vue'
import AlertMessage from '@/components/ui/AlertMessage.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { api } from '@/lib/api'
import { firstError } from '@/lib/errors'
import { number, shopDate, whenFull } from '@/lib/format'

/*
 * Do our books and the payment company's books agree? For every payment in the period the shop asks the payment company what it
 * holds (the amount, whether it was paid, what was refunded) and compares that with its own records. A difference is a
 * RECONCILIATION ERROR, explained in words. If the cause is a missed message, one button asks the company again and applies
 * its answer the safe way; everything else is left for a person.
 */
const from = ref(shopDate(6))
const to = ref(shopDate(0))
const report = ref(null)
const loading = ref(false)
const error = ref('')
const notice = ref('')
const onlyErrors = ref(false)
const syncing = ref('')

const problem = computed(() =>
  !from.value || !to.value
    ? 'Choose both dates.'
    : from.value > to.value
      ? 'The start date must not be after the end date.'
      : '',
)
const rows = computed(() => (report.value?.rows ?? []).filter((row) => !onlyErrors.value || row.result === 'ERROR'))

async function run({ keepMessages = false } = {}) {
  if (problem.value) {
    error.value = problem.value
    return
  }
  loading.value = true
  if (!keepMessages) {
    error.value = ''
    notice.value = ''
  }
  try {
    report.value = (
      await api.get('/api/admin/reconciliation', { params: { from: from.value, to: to.value } })
    ).data.data
  } catch (e) {
    error.value = firstError(e)
  } finally {
    loading.value = false
  }
}

async function sync(row) {
  syncing.value = row.payment_id
  error.value = ''
  notice.value = ''
  try {
    const fresh = (await api.post(`/api/admin/payments/${row.payment_id}/sync`)).data.data
    await run({ keepMessages: true }) // the summary and the order of the rows change too
    notice.value =
      fresh.result === 'MATCHED'
        ? `${fresh.order.order_number} now matches the payment company.`
        : `${fresh.order.order_number} was updated, but something still differs. See below.`
  } catch (e) {
    error.value = firstError(e)
  } finally {
    syncing.value = ''
  }
}

onMounted(() => run())
</script>

<template>
  <AdminPage title="Payment reconciliation" subtitle="Our records against the payment company's, payment by payment.">
    <form class="mb-4 flex flex-wrap items-end gap-3" @submit.prevent="run">
      <div>
        <label for="rec-from" class="mb-1.5 block text-sm font-medium">From</label>
        <input
          id="rec-from"
          v-model="from"
          type="date"
          class="rounded-xl border border-line bg-bg px-3 py-2.5 text-sm focus:border-accent focus:outline-none"
        />
      </div>
      <div>
        <label for="rec-to" class="mb-1.5 block text-sm font-medium">To</label>
        <input
          id="rec-to"
          v-model="to"
          type="date"
          class="rounded-xl border border-line bg-bg px-3 py-2.5 text-sm focus:border-accent focus:outline-none"
        />
      </div>
      <BaseButton type="submit" :loading="loading">Check payments</BaseButton>
      <p class="text-xs text-muted">
        Up to 31 days. Each payment is checked with the payment company, so it can take a moment.
      </p>
    </form>

    <AlertMessage v-if="error" class="mb-4">{{ error }}</AlertMessage>
    <AlertMessage v-if="notice" type="success" class="mb-4">{{ notice }}</AlertMessage>

    <div
      v-if="!report && loading"
      class="h-48 animate-pulse rounded-card bg-surface"
      aria-busy="true"
      aria-label="Checking payments"
    />

    <div v-else-if="report" class="space-y-4" :class="{ 'opacity-60 transition': loading }">
      <div class="grid grid-cols-2 gap-3 lg:grid-cols-5">
        <StatCard label="Checked" :value="number(report.summary.checked)" />
        <StatCard label="Matched" :value="number(report.summary.matched)" />
        <StatCard
          label="Errors"
          :value="number(report.summary.errors)"
          :tone="report.summary.errors ? 'danger' : 'normal'"
        />
        <StatCard label="Payment company received" :value="report.summary.gateway_received.formatted" />
        <StatCard
          label="We recorded"
          :value="report.summary.internal_recorded.formatted"
          :hint="
            report.summary.difference.amount
              ? `Difference ${report.summary.difference.formatted}`
              : 'Same as the payment company'
          "
          :tone="report.summary.difference.amount ? 'warning' : 'normal'"
        />
      </div>

      <FormCheckbox v-model="onlyErrors" label="Show only the errors" />

      <EmptyState
        v-if="!report.rows.length"
        title="No payments in this period"
        text="Nothing was sent to the payment company between these dates."
      />
      <EmptyState
        v-else-if="!rows.length"
        title="No errors"
        text="Every payment in this period matches the payment company's records."
      />

      <div v-else class="relative overflow-x-auto rounded-card border border-line bg-surface">
        <table class="admin-table" data-testid="reconciliation-table">
          <thead>
            <tr>
              <th>Order</th>
              <th class="text-right">Payment company</th>
              <th class="text-right">Our records</th>
              <th>Result</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in rows" :key="row.payment_id" class="align-top" :data-testid="`row-${row.result}`">
              <td>
                <RouterLink
                  :to="{ name: 'admin-payment', params: { id: row.payment_id } }"
                  class="whitespace-nowrap font-medium text-accent hover:underline"
                  >{{ row.order.order_number }}</RouterLink
                >
                <p class="text-xs text-muted">{{ row.order.customer?.name }}</p>
                <p class="text-xs text-muted">order total {{ row.order.total.formatted }}</p>
              </td>
              <td class="text-right">
                <template v-if="row.gateway">
                  <p class="font-semibold tabular-nums" data-testid="gateway-amount">
                    {{ row.gateway.amount.formatted }}
                  </p>
                  <p class="text-xs text-muted">
                    {{ row.gateway.label
                    }}<template v-if="row.gateway.refunded.amount">
                      · {{ row.gateway.refunded.formatted }} refunded</template
                    >
                  </p>
                </template>
                <span v-else class="text-muted">No record</span>
              </td>
              <td class="text-right">
                <p class="font-semibold tabular-nums" data-testid="internal-amount">
                  {{ row.internal.amount.formatted }}
                </p>
                <div class="mt-0.5 flex justify-end">
                  <OrderStatusBadge :status="row.internal.status" kind="payment" />
                </div>
                <p v-if="row.internal.refunded.amount" class="mt-0.5 text-xs text-muted">
                  {{ row.internal.refunded.formatted }} refunded
                </p>
              </td>
              <td class="min-w-[16rem]">
                <span
                  class="inline-flex whitespace-nowrap rounded-full px-2.5 py-0.5 text-xs font-bold tracking-wide"
                  :class="row.result === 'MATCHED' ? 'bg-success/15 text-success' : 'bg-danger/15 text-danger'"
                >
                  {{ row.result === 'MATCHED' ? 'MATCHED' : 'RECONCILIATION ERROR' }}
                </span>
                <ul v-if="row.issues.length" class="mt-2 list-disc space-y-1 pl-4 text-xs text-ink">
                  <li v-for="issue in row.issues" :key="issue.code">{{ issue.message }}</li>
                </ul>
                <div v-if="row.can_sync" class="mt-2">
                  <BaseButton
                    variant="secondary"
                    class="!px-3 !py-1.5 !text-xs"
                    :loading="syncing === row.payment_id"
                    @click="sync(row)"
                    >Ask the payment company again</BaseButton
                  >
                  <p class="mt-1 text-[11px] text-muted">
                    Applies its answer the same safe way a payment notice is handled.
                  </p>
                </div>
                <p v-else-if="row.result === 'ERROR'" class="mt-2 text-[11px] text-muted">
                  This needs a person: check it with the payment company before doing anything with the order.
                </p>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <p class="text-center text-xs text-muted">
        Checked {{ whenFull(new Date().toISOString()) }} · {{ report.period.from }} to {{ report.period.to }}
      </p>
    </div>
  </AdminPage>
</template>
