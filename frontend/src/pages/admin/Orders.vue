<script setup>
import { RouterLink } from 'vue-router'
import AdminPage from '@/components/admin/AdminPage.vue'
import FormSelect from '@/components/admin/FormSelect.vue'
import OrderStatusBadge from '@/components/orders/OrderStatusBadge.vue'
import AlertMessage from '@/components/ui/AlertMessage.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import { useAdminList } from '@/composables/useAdminList'
import { whenFull } from '@/lib/format'
import { ORDER_STATUSES, PAYMENT_STATUSES } from '@/lib/adminOptions'

const list = useAdminList('/api/admin/orders', { q: '', status: '', payment_status: '' })

function clear() {
  Object.assign(list.params, { q: '', status: '', payment_status: '' })
}
</script>

<template>
  <AdminPage title="Orders" subtitle="Every customer order. Open one to pack it, ship it or deal with a refund.">
    <form
      class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-[1fr_14rem_14rem]"
      role="search"
      @submit.prevent="list.apply"
    >
      <BaseInput v-model="list.params.q" label="Search" placeholder="Order number, customer name or email" />
      <FormSelect
        v-model="list.params.status"
        label="Order status"
        :options="ORDER_STATUSES"
        placeholder="Any status"
      />
      <FormSelect
        v-model="list.params.payment_status"
        label="Payment"
        :options="PAYMENT_STATUSES"
        placeholder="Any payment status"
      />
    </form>

    <AlertMessage v-if="list.error.value" class="mb-4"
      >{{ list.error.value }}
      <button type="button" class="underline" @click="list.apply">Try again</button></AlertMessage
    >

    <div
      v-if="!list.loaded.value && list.loading.value"
      class="h-64 animate-pulse rounded-card bg-surface"
      aria-busy="true"
      aria-label="Loading orders"
    />

    <EmptyState
      v-else-if="list.loaded.value && !list.rows.value.length"
      title="No orders match"
      text="Try a different search or filter."
    >
      <BaseButton variant="secondary" @click="clear">Clear filters</BaseButton>
    </EmptyState>

    <div
      v-else
      class="overflow-x-auto rounded-card border border-line bg-surface"
      :class="{ 'opacity-60 transition': list.loading.value }"
    >
      <table class="admin-table" data-testid="orders-table">
        <thead>
          <tr>
            <th>Order</th>
            <th>Customer</th>
            <th>Status</th>
            <th>Payment</th>
            <th class="text-right">Items</th>
            <th class="text-right">Total</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="order in list.rows.value" :key="order.id">
            <td>
              <RouterLink
                :to="{ name: 'admin-order', params: { id: order.id } }"
                class="whitespace-nowrap font-medium text-accent hover:underline"
                >{{ order.order_number }}</RouterLink
              >
              <p class="text-xs text-muted">{{ whenFull(order.placed_at) }}</p>
            </td>
            <td>
              <p>{{ order.customer.name }}</p>
              <p class="text-xs text-muted">{{ order.customer.email }}</p>
            </td>
            <td><OrderStatusBadge :status="order.status" /></td>
            <td><OrderStatusBadge :status="order.payment_status" kind="payment" /></td>
            <td class="text-right tabular-nums">{{ order.items_count }}</td>
            <td class="text-right tabular-nums">{{ order.total.formatted }}</td>
          </tr>
        </tbody>
      </table>
    </div>

    <p v-if="list.loaded.value" class="mt-3 text-center text-xs text-muted">
      {{ list.meta.value.total }} order{{ list.meta.value.total === 1 ? '' : 's' }}
    </p>
    <div class="mt-4">
      <PaginationBar :page="list.meta.value.current_page" :last-page="list.meta.value.last_page" @change="list.load" />
    </div>
  </AdminPage>
</template>
