<script setup>
import { RouterLink } from 'vue-router'
import AdminPage from '@/components/admin/AdminPage.vue'
import FormSelect from '@/components/admin/FormSelect.vue'
import OrderStatusBadge from '@/components/orders/OrderStatusBadge.vue'
import AlertMessage from '@/components/ui/AlertMessage.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import { useAdminList } from '@/composables/useAdminList'
import { PAYMENT_STATUSES } from '@/lib/adminOptions'
import { whenFull } from '@/lib/format'

const list = useAdminList('/api/admin/payments', { q: '', status: '', requires_refund: '' })
const REFUND_FILTER = [{ value: '1', label: 'Only payments that need a refund' }]
</script>

<template>
  <AdminPage title="Payments" subtitle="Every attempt to pay, and what happened to it.">
    <form
      class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-[1fr_14rem_16rem]"
      role="search"
      @submit.prevent="list.apply"
    >
      <BaseInput v-model="list.params.q" label="Search" placeholder="Order number, customer name or email" />
      <FormSelect v-model="list.params.status" label="Status" :options="PAYMENT_STATUSES" placeholder="Any status" />
      <FormSelect
        v-model="list.params.requires_refund"
        label="Refunds"
        :options="REFUND_FILTER"
        placeholder="All payments"
      />
    </form>

    <AlertMessage v-if="list.error.value" class="mb-4">{{ list.error.value }}</AlertMessage>
    <div
      v-if="!list.loaded.value && list.loading.value"
      class="h-64 animate-pulse rounded-card bg-surface"
      aria-busy="true"
      aria-label="Loading payments"
    />
    <EmptyState
      v-else-if="list.loaded.value && !list.rows.value.length"
      title="No payments match"
      text="Try a different search or filter."
    />
    <div
      v-else
      class="overflow-x-auto rounded-card border border-line bg-surface"
      :class="{ 'opacity-60 transition': list.loading.value }"
    >
      <table class="admin-table" data-testid="payments-table">
        <thead>
          <tr>
            <th>Order</th>
            <th>Customer</th>
            <th>Status</th>
            <th>Method</th>
            <th class="text-right">Amount</th>
            <th class="text-right">Refunded</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="payment in list.rows.value" :key="payment.id">
            <td>
              <RouterLink
                :to="{ name: 'admin-payment', params: { id: payment.id } }"
                class="whitespace-nowrap font-medium text-accent hover:underline"
                >{{ payment.order.order_number }}</RouterLink
              >
              <p class="text-xs text-muted">{{ whenFull(payment.created_at) }}</p>
            </td>
            <td>
              <p>{{ payment.order.customer?.name }}</p>
              <p class="text-xs text-muted">{{ payment.order.customer?.email }}</p>
            </td>
            <td>
              <OrderStatusBadge :status="payment.status" kind="payment" />
              <p v-if="payment.requires_refund" class="mt-1 text-xs text-warning">needs a refund</p>
            </td>
            <td class="text-muted">{{ payment.method ?? '—' }}</td>
            <td class="text-right tabular-nums">{{ payment.amount.formatted }}</td>
            <td class="text-right tabular-nums text-muted">
              {{ payment.refunded_amount.amount ? payment.refunded_amount.formatted : '—' }}
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <p v-if="list.loaded.value" class="mt-3 text-center text-xs text-muted">
      {{ list.meta.value.total }} payment{{ list.meta.value.total === 1 ? '' : 's' }}
    </p>
    <div class="mt-4">
      <PaginationBar :page="list.meta.value.current_page" :last-page="list.meta.value.last_page" @change="list.load" />
    </div>
  </AdminPage>
</template>
