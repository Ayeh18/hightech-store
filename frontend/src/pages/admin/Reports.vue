<script setup>
import { computed, onMounted, ref } from 'vue'
import AdminPage from '@/components/admin/AdminPage.vue'
import PeriodForm from '@/components/admin/PeriodForm.vue'
import StatCard from '@/components/admin/StatCard.vue'
import AlertMessage from '@/components/ui/AlertMessage.vue'
import { api } from '@/lib/api'
import { firstError } from '@/lib/errors'
import { number, periodProblem, shopDate, shortDay } from '@/lib/format'

/*
 * Sales for any period. "Net sales" is money received minus money refunded, counted on the day it happened, in Malaysian time.
 * The same numbers can be downloaded as a spreadsheet file (CSV).
 */
const from = ref(shopDate(29))
const to = ref(shopDate(0))
const report = ref(null)
const loading = ref(false)
const error = ref('')

const problem = computed(() => periodProblem(from.value, to.value))
const csvUrl = computed(
  () => `/api/admin/reports/sales/export?${new URLSearchParams({ from: from.value, to: to.value })}`,
)

async function run() {
  if (problem.value) {
    error.value = problem.value
    return
  }
  loading.value = true
  error.value = ''
  try {
    report.value = (await api.get('/api/admin/reports/sales', { params: { from: from.value, to: to.value } })).data.data
  } catch (e) {
    error.value = firstError(e)
  } finally {
    loading.value = false
  }
}

onMounted(run)
</script>

<template>
  <AdminPage title="Sales reports" subtitle="Pick a period. Up to a year at a time.">
    <PeriodForm v-model:from="from" v-model:to="to" :loading="loading" @submit="run" />

    <AlertMessage v-if="error" class="mb-4">{{ error }}</AlertMessage>

    <div v-if="report" class="space-y-6" :class="{ 'opacity-60 transition': loading }">
      <div class="grid grid-cols-2 gap-3 lg:grid-cols-5">
        <StatCard label="Net sales" :value="report.totals.net.formatted" />
        <StatCard label="Money in" :value="report.totals.gross.formatted" />
        <StatCard label="Refunded" :value="report.totals.refunded.formatted" />
        <StatCard
          label="Orders paid"
          :value="number(report.totals.orders_paid)"
          :hint="`${number(report.totals.orders_placed)} placed`"
        />
        <StatCard label="Average order" :value="report.totals.average_order.formatted" />
      </div>

      <div class="flex justify-end">
        <a
          :href="csvUrl"
          download
          class="inline-flex items-center rounded-xl border border-line bg-elevated px-4 py-2 text-sm font-semibold hover:border-accent/60"
          data-testid="csv-link"
          >Download as a spreadsheet (CSV)</a
        >
      </div>

      <section class="rounded-card border border-line bg-surface" aria-labelledby="days-heading">
        <h2 id="days-heading" class="px-5 pt-5 font-display text-lg font-semibold">Day by day</h2>
        <div class="mt-3 max-h-96 overflow-auto">
          <table class="admin-table" data-testid="days-table">
            <thead>
              <tr>
                <th>Day</th>
                <th class="text-right">Orders placed</th>
                <th class="text-right">Orders paid</th>
                <th class="text-right">Money in</th>
                <th class="text-right">Refunded</th>
                <th class="text-right">Net</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="day in report.days" :key="day.date">
                <td>{{ shortDay(day.date) }}</td>
                <td class="text-right tabular-nums">{{ day.orders_placed }}</td>
                <td class="text-right tabular-nums">{{ day.orders_paid }}</td>
                <td class="text-right tabular-nums">{{ day.gross.formatted }}</td>
                <td class="text-right tabular-nums text-muted">
                  {{ day.refunded.amount ? day.refunded.formatted : '—' }}
                </td>
                <td class="text-right font-medium tabular-nums">{{ day.net.formatted }}</td>
              </tr>
            </tbody>
            <tfoot>
              <tr class="font-semibold">
                <td class="px-4 py-3">Total</td>
                <td class="px-4 py-3 text-right tabular-nums">{{ report.totals.orders_placed }}</td>
                <td class="px-4 py-3 text-right tabular-nums">{{ report.totals.orders_paid }}</td>
                <td class="px-4 py-3 text-right tabular-nums">{{ report.totals.gross.formatted }}</td>
                <td class="px-4 py-3 text-right tabular-nums">{{ report.totals.refunded.formatted }}</td>
                <td class="px-4 py-3 text-right tabular-nums">{{ report.totals.net.formatted }}</td>
              </tr>
            </tfoot>
          </table>
        </div>
      </section>

      <div class="grid gap-4 xl:grid-cols-2">
        <section class="rounded-card border border-line bg-surface" aria-labelledby="products-heading">
          <h2 id="products-heading" class="px-5 pt-5 font-display text-lg font-semibold">What sold</h2>
          <div class="mt-3 max-h-96 overflow-auto">
            <table class="admin-table" data-testid="products-sold">
              <thead>
                <tr>
                  <th>Product</th>
                  <th class="text-right">Units</th>
                  <th class="text-right">Sales</th>
                </tr>
              </thead>
              <tbody>
                <tr v-if="!report.products.length">
                  <td colspan="3" class="text-muted">Nothing sold in this period.</td>
                </tr>
                <tr v-for="product in report.products" :key="product.id">
                  <td>
                    {{ product.name }}
                    <p class="text-xs text-muted">{{ product.sku }}</p>
                  </td>
                  <td class="text-right tabular-nums">{{ product.units }}</td>
                  <td class="text-right tabular-nums">{{ product.revenue.formatted }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>
        <section class="rounded-card border border-line bg-surface" aria-labelledby="categories-heading">
          <h2 id="categories-heading" class="px-5 pt-5 font-display text-lg font-semibold">By category</h2>
          <div class="mt-3 overflow-auto">
            <table class="admin-table" data-testid="categories-sold">
              <thead>
                <tr>
                  <th>Category</th>
                  <th class="text-right">Items</th>
                  <th class="text-right">Sales</th>
                </tr>
              </thead>
              <tbody>
                <tr v-if="!report.revenue_by_category.length">
                  <td colspan="3" class="text-muted">Nothing sold in this period.</td>
                </tr>
                <tr v-for="category in report.revenue_by_category" :key="category.id">
                  <td>{{ category.name }}</td>
                  <td class="text-right tabular-nums">{{ category.units }}</td>
                  <td class="text-right tabular-nums">{{ category.revenue.formatted }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>
      </div>
    </div>
  </AdminPage>
</template>
