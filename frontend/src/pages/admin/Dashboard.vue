<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import AdminPage from '@/components/admin/AdminPage.vue'
import BarChart from '@/components/admin/BarChart.vue'
import RankedBars from '@/components/admin/RankedBars.vue'
import StatCard from '@/components/admin/StatCard.vue'
import OrderStatusBadge from '@/components/orders/OrderStatusBadge.vue'
import AlertMessage from '@/components/ui/AlertMessage.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import { api, parseApiError } from '@/lib/api'
import { number, shortDay, whenFull } from '@/lib/format'

const PERIODS = [7, 30, 90]
const days = ref(30)
const data = ref(null)
const loading = ref(true)
const error = ref('')
let latest = 0

async function load() {
  const mine = ++latest
  loading.value = true
  error.value = ''
  try {
    const response = await api.get('/api/admin/dashboard', { params: { days: days.value } })
    if (mine === latest) data.value = response.data.data
  } catch (e) {
    if (mine === latest) error.value = parseApiError(e).message
  } finally {
    if (mine === latest) loading.value = false
  }
}

onMounted(load)
watch(days, load)

const cards = computed(() => data.value?.cards)
const salesPoints = computed(() =>
  data.value.sales_by_day.map((d) => ({ label: shortDay(d.date), value: d.net.amount, text: d.net.formatted })),
)
const orderPoints = computed(() =>
  data.value.sales_by_day.map((d) => ({
    label: shortDay(d.date),
    value: d.orders_placed,
    text: `${d.orders_placed} orders`,
  })),
)
const salesSummary = computed(
  () => `Net sales per day over the last ${days.value} days. Total ${cards.value.total_sales.formatted}.`,
)
const ordersSummary = computed(
  () => `Orders placed per day over the last ${days.value} days. Total ${number(cards.value.orders)}.`,
)
const topProducts = computed(() =>
  data.value.top_products.map((p) => ({
    key: p.id,
    name: p.name,
    detail: `${p.sku} · ${number(p.units)} sold`,
    value: p.revenue.amount,
    text: p.revenue.formatted,
  })),
)
const categories = computed(() =>
  data.value.revenue_by_category.map((c) => ({
    key: c.id,
    name: c.name,
    detail: `${number(c.units)} items`,
    value: c.revenue.amount,
    text: c.revenue.formatted,
  })),
)
</script>

<template>
  <AdminPage title="Dashboard" subtitle="How the shop is doing.">
    <template #actions>
      <div class="inline-flex rounded-xl border border-line p-0.5" role="group" aria-label="Period">
        <button
          v-for="period in PERIODS"
          :key="period"
          type="button"
          class="rounded-lg px-3 py-1.5 text-sm transition"
          :class="days === period ? 'bg-accent-soft font-medium text-accent' : 'text-muted hover:text-ink'"
          :aria-pressed="days === period"
          @click="days = period"
        >
          {{ period }} days
        </button>
      </div>
    </template>

    <div v-if="error" class="space-y-3" role="alert">
      <AlertMessage>{{ error }}</AlertMessage>
      <BaseButton variant="secondary" @click="load">Try again</BaseButton>
    </div>

    <div v-else-if="!data" class="animate-pulse space-y-4" aria-busy="true" aria-label="Loading the dashboard">
      <div class="grid grid-cols-2 gap-3 lg:grid-cols-3 2xl:grid-cols-6">
        <div v-for="n in 6" :key="n" class="h-28 rounded-card bg-surface" />
      </div>
      <div class="h-56 rounded-card bg-surface" />
    </div>

    <div v-else class="space-y-6" :class="{ 'opacity-60 transition': loading }">
      <div class="grid grid-cols-2 gap-3 lg:grid-cols-3 2xl:grid-cols-6">
        <StatCard
          label="Total sales"
          :value="cards.total_sales.formatted"
          :hint="`${cards.gross_sales.formatted} in, ${cards.refunded.formatted} refunded`"
        />
        <StatCard
          label="Orders"
          :value="number(cards.orders)"
          :hint="`${number(cards.orders_paid)} paid · average ${cards.average_order.formatted}`"
          :to="{ name: 'admin-orders' }"
        />
        <StatCard
          label="Pending payments"
          :value="number(cards.pending_payments.count)"
          :hint="cards.pending_payments.amount.formatted"
          :tone="cards.pending_payments.count ? 'warning' : 'normal'"
          :to="{ name: 'admin-orders', query: { status: 'PENDING_PAYMENT' } }"
        />
        <StatCard
          label="Products"
          :value="number(cards.products.active)"
          :hint="`${number(cards.products.total)} in total`"
          :to="{ name: 'admin-products' }"
        />
        <StatCard
          label="Low stock"
          :value="number(cards.low_stock)"
          hint="at or below their alert level"
          :tone="cards.low_stock ? 'danger' : 'normal'"
        />
        <StatCard
          label="Customers"
          :value="number(cards.customers.total)"
          :hint="`${number(cards.customers.new)} new`"
        />
      </div>

      <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <section class="rounded-card border border-line bg-surface p-5" aria-labelledby="sales-heading">
          <h2 id="sales-heading" class="mb-4 font-display text-lg font-semibold">Sales over time</h2>
          <BarChart :points="salesPoints" :summary="salesSummary" />
        </section>
        <section class="rounded-card border border-line bg-surface p-5" aria-labelledby="orders-heading">
          <h2 id="orders-heading" class="mb-4 font-display text-lg font-semibold">Orders over time</h2>
          <BarChart :points="orderPoints" :summary="ordersSummary" />
        </section>
      </div>

      <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <section class="rounded-card border border-line bg-surface p-5" aria-labelledby="top-heading">
          <h2 id="top-heading" class="mb-4 font-display text-lg font-semibold">Top products</h2>
          <RankedBars :rows="topProducts" empty="No sales in this period yet." />
        </section>
        <section class="rounded-card border border-line bg-surface p-5" aria-labelledby="category-heading">
          <h2 id="category-heading" class="mb-4 font-display text-lg font-semibold">Revenue by category</h2>
          <RankedBars :rows="categories" empty="No sales in this period yet." />
        </section>
      </div>

      <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
        <section class="rounded-card border border-line bg-surface" aria-labelledby="recent-orders-heading">
          <div class="flex items-center justify-between px-5 pt-5">
            <h2 id="recent-orders-heading" class="font-display text-lg font-semibold">Recent orders</h2>
            <RouterLink :to="{ name: 'admin-orders' }" class="text-sm text-accent hover:underline"
              >All orders</RouterLink
            >
          </div>
          <div class="mt-3 overflow-x-auto">
            <table class="admin-table" data-testid="recent-orders">
              <thead>
                <tr>
                  <th>Order</th>
                  <th>Customer</th>
                  <th>Status</th>
                  <th class="text-right">Total</th>
                </tr>
              </thead>
              <tbody>
                <tr v-if="!data.recent_orders.length">
                  <td colspan="4" class="text-muted">No orders yet.</td>
                </tr>
                <tr v-for="order in data.recent_orders" :key="order.id">
                  <td>
                    <RouterLink
                      :to="{ name: 'admin-order', params: { id: order.id } }"
                      class="whitespace-nowrap font-medium text-accent hover:underline"
                      >{{ order.order_number }}</RouterLink
                    >
                    <p class="text-xs text-muted">{{ whenFull(order.placed_at) }}</p>
                  </td>
                  <td class="max-w-[10rem] truncate">{{ order.customer.name }}</td>
                  <td><OrderStatusBadge :status="order.status" /></td>
                  <td class="text-right tabular-nums">{{ order.total.formatted }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>

        <section class="rounded-card border border-line bg-surface" aria-labelledby="low-stock-heading">
          <div class="flex items-center justify-between px-5 pt-5">
            <h2 id="low-stock-heading" class="font-display text-lg font-semibold">Low-stock products</h2>
            <RouterLink :to="{ name: 'admin-products' }" class="text-sm text-accent hover:underline"
              >All products</RouterLink
            >
          </div>
          <div class="mt-3 overflow-x-auto">
            <table class="admin-table" data-testid="low-stock">
              <thead>
                <tr>
                  <th>Product</th>
                  <th class="text-right">Available</th>
                  <th class="text-right">Alert at</th>
                </tr>
              </thead>
              <tbody>
                <tr v-if="!data.low_stock_products.length">
                  <td colspan="3" class="text-muted">Everything is well stocked.</td>
                </tr>
                <tr v-for="product in data.low_stock_products" :key="product.id">
                  <td>
                    <RouterLink
                      :to="{ name: 'admin-product-edit', params: { id: product.id } }"
                      class="whitespace-nowrap font-medium text-accent hover:underline"
                      >{{ product.name }}</RouterLink
                    >
                    <p class="text-xs text-muted">{{ product.sku }}</p>
                  </td>
                  <td
                    class="text-right tabular-nums"
                    :class="product.available === 0 ? 'font-semibold text-danger' : 'text-warning'"
                  >
                    {{ product.available === 0 ? 'Sold out' : product.available }}
                  </td>
                  <td class="text-right tabular-nums text-muted">{{ product.low_stock_threshold }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>

        <!-- Only for people who may view payments; the API sends null to everyone else. -->
        <section
          v-if="data.recent_payments"
          class="rounded-card border border-line bg-surface"
          aria-labelledby="payments-heading"
        >
          <div class="flex items-center justify-between px-5 pt-5">
            <h2 id="payments-heading" class="font-display text-lg font-semibold">Recent payments</h2>
            <RouterLink :to="{ name: 'admin-payments' }" class="text-sm text-accent hover:underline"
              >All payments</RouterLink
            >
          </div>
          <div class="mt-3 overflow-x-auto">
            <table class="admin-table" data-testid="recent-payments">
              <thead>
                <tr>
                  <th>Order</th>
                  <th>Status</th>
                  <th class="text-right">Amount</th>
                </tr>
              </thead>
              <tbody>
                <tr v-if="!data.recent_payments.length">
                  <td colspan="3" class="text-muted">No payments yet.</td>
                </tr>
                <tr v-for="payment in data.recent_payments" :key="payment.id">
                  <td>
                    <RouterLink
                      :to="{ name: 'admin-payment', params: { id: payment.id } }"
                      class="whitespace-nowrap font-medium text-accent hover:underline"
                      >{{ payment.order.order_number }}</RouterLink
                    >
                    <p class="text-xs text-muted">
                      {{ payment.order.customer?.name }} · {{ whenFull(payment.created_at) }}
                    </p>
                  </td>
                  <td><OrderStatusBadge :status="payment.status" kind="payment" /></td>
                  <td class="text-right tabular-nums">{{ payment.amount.formatted }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>

        <section
          v-if="data.failed_payments"
          class="rounded-card border border-line bg-surface"
          aria-labelledby="failed-heading"
        >
          <div class="flex items-center justify-between px-5 pt-5">
            <h2 id="failed-heading" class="font-display text-lg font-semibold">Failed payments</h2>
            <RouterLink
              :to="{ name: 'admin-payments', query: { status: 'FAILED' } }"
              class="text-sm text-accent hover:underline"
              >See all</RouterLink
            >
          </div>
          <div class="mt-3 overflow-x-auto">
            <table class="admin-table" data-testid="failed-payments">
              <thead>
                <tr>
                  <th>Order</th>
                  <th>Why</th>
                  <th class="text-right">Amount</th>
                </tr>
              </thead>
              <tbody>
                <tr v-if="!data.failed_payments.length">
                  <td colspan="3" class="text-muted">No failed payments.</td>
                </tr>
                <tr v-for="payment in data.failed_payments" :key="payment.id">
                  <td>
                    <RouterLink
                      :to="{ name: 'admin-payment', params: { id: payment.id } }"
                      class="whitespace-nowrap font-medium text-accent hover:underline"
                      >{{ payment.order.order_number }}</RouterLink
                    >
                    <p class="text-xs text-muted">{{ whenFull(payment.created_at) }}</p>
                  </td>
                  <td class="text-danger">{{ payment.failure_reason ?? 'Unknown' }}</td>
                  <td class="text-right tabular-nums">{{ payment.amount.formatted }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>
      </div>
    </div>
  </AdminPage>
</template>
