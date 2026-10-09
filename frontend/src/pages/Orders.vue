<script setup>
import { onMounted } from 'vue'
import { RouterLink } from 'vue-router'
import OrderStatusBadge from '@/components/orders/OrderStatusBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import { useOrderStore } from '@/stores/orders'

const store = useOrderStore()

onMounted(() => store.fetchOrders())

const when = (iso) =>
  new Intl.DateTimeFormat('en-MY', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(iso))
</script>

<template>
  <div class="mx-auto max-w-4xl px-4 py-8 sm:px-6">
    <header class="mb-6">
      <h1 class="font-display text-3xl font-semibold tracking-tight">My orders</h1>
      <p class="mt-1 text-sm text-muted">Track what you ordered and where each order is up to.</p>
    </header>

    <ul
      v-if="store.loading && !store.loaded"
      class="animate-pulse space-y-3"
      aria-busy="true"
      aria-label="Loading your orders"
    >
      <li v-for="n in 3" :key="n" class="h-28 rounded-card border border-line bg-surface" />
    </ul>

    <div v-else-if="store.error" role="alert">
      <EmptyState title="We couldn't load your orders" :text="store.error"
        ><BaseButton @click="store.fetchOrders()">Try again</BaseButton></EmptyState
      >
    </div>

    <EmptyState
      v-else-if="!store.orders.length"
      title="No orders yet"
      text="When you place an order it will show up here."
    >
      <RouterLink :to="{ name: 'products' }"><BaseButton>Start shopping</BaseButton></RouterLink>
    </EmptyState>

    <template v-else>
      <ul class="space-y-3">
        <li v-for="order in store.orders" :key="order.id">
          <RouterLink
            :to="{ name: 'order', params: { id: order.id } }"
            class="block rounded-card border border-line bg-surface p-5 transition hover:border-accent/50"
          >
            <div class="flex flex-wrap items-start justify-between gap-3">
              <div>
                <p class="font-display text-lg font-semibold">{{ order.order_number }}</p>
                <p class="text-xs text-muted">Placed {{ when(order.placed_at) }}</p>
              </div>
              <div class="flex flex-wrap items-center gap-2">
                <OrderStatusBadge :status="order.status" />
                <OrderStatusBadge :status="order.payment_status" kind="payment" />
              </div>
            </div>
            <p class="mt-3 truncate text-sm text-muted">
              {{ order.preview.join(' · ') }}<template v-if="order.more"> and {{ order.more }} more</template>
            </p>
            <p class="mt-2 font-display text-xl font-bold tabular-nums">{{ order.total.formatted }}</p>
          </RouterLink>
        </li>
      </ul>
      <div class="mt-6">
        <PaginationBar
          :page="store.meta.current_page"
          :last-page="store.meta.last_page"
          @change="store.fetchOrders($event)"
        />
      </div>
    </template>
  </div>
</template>
