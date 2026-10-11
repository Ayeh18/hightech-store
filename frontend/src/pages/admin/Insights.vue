<script setup>
import { computed, onMounted, ref } from 'vue'
import AdminPage from '@/components/admin/AdminPage.vue'
import PeriodForm from '@/components/admin/PeriodForm.vue'
import RankedBars from '@/components/admin/RankedBars.vue'
import StatCard from '@/components/admin/StatCard.vue'
import AlertMessage from '@/components/ui/AlertMessage.vue'
import { api } from '@/lib/api'
import { firstError } from '@/lib/errors'
import { number, percent, periodProblem, shopDate } from '@/lib/format'

/*
 * The questions the sales report does not answer: who is buying, where orders are lost, how payments go, how much is sold
 * as whole PCs, and how "Suggest a build for me" is used.
 *
 * Every number is worked out by the API (see Insights.php, which also says exactly what each one means). This page only
 * lays them out. A percentage the API could not work out (nothing to take a percentage of) arrives as null and shows as "—".
 */
const from = ref(shopDate(29))
const to = ref(shopDate(0))
const data = ref(null)
const loading = ref(false)
const error = ref('')
let latest = 0

async function run() {
  const problem = periodProblem(from.value, to.value)
  if (problem) {
    error.value = problem
    return
  }
  const mine = ++latest
  loading.value = true
  error.value = ''
  try {
    const response = await api.get('/api/admin/reports/insights', { params: { from: from.value, to: to.value } })
    if (mine === latest) data.value = response.data.data
  } catch (e) {
    if (mine === latest) error.value = firstError(e)
  } finally {
    if (mine === latest) loading.value = false
  }
}

onMounted(run)

const times = (n, one, many) => `${number(n)} ${n === 1 ? one : many}`

const failureReasons = computed(() =>
  data.value.payments.failure_reasons.map((r) => ({
    key: r.reason,
    name: r.reason,
    value: r.attempts,
    text: times(r.attempts, 'attempt', 'attempts'),
  })),
)
const methods = computed(() =>
  data.value.payments.methods.map((m) => ({
    key: m.method,
    name: m.method,
    detail: times(m.payments, 'payment', 'payments'),
    value: m.amount.amount,
    text: m.amount.formatted,
  })),
)
const pairings = computed(() =>
  data.value.pcs.pairings.map((p) => ({
    key: `${p.cpu}|${p.gpu}`,
    name: p.cpu,
    detail: p.gpu ? `with ${p.gpu}` : "on the processor's built-in graphics",
    value: p.pcs,
    text: times(p.pcs, 'PC', 'PCs'),
  })),
)
// The parts come as one flat list in slot order: show the slot's name only on its first row.
const parts = computed(() =>
  data.value.pcs.popular_parts.map((part, index, all) => ({ ...part, first: all[index - 1]?.slot !== part.slot })),
)
const purposes = computed(() =>
  data.value.suggestions.by_purpose.map((p) => ({
    key: p.purpose,
    name: p.label,
    detail: `${number(p.accepted)} loaded into the builder`,
    value: p.asked,
    text: number(p.asked),
  })),
)
const budgets = computed(() =>
  data.value.suggestions.by_budget.map((b) => ({ key: b.label, name: b.label, value: b.asked, text: number(b.asked) })),
)
const aiReasons = computed(() =>
  data.value.suggestions.ai.reasons.map((r) => ({
    key: r.kind,
    name: r.label,
    value: r.times,
    text: times(r.times, 'time', 'times'),
  })),
)

const section = 'rounded-card border border-line bg-surface p-5'
const heading = 'font-display text-lg font-semibold'
const subheading = 'mb-3 text-sm font-medium text-muted'
</script>

<template>
  <AdminPage title="Insights" subtitle="Who is buying, where orders are lost, and what goes into the PCs you sell.">
    <PeriodForm v-model:from="from" v-model:to="to" :loading="loading" action="Show insights" @submit="run" />

    <AlertMessage v-if="error" class="mb-4">{{ error }}</AlertMessage>

    <div v-if="data" class="space-y-6" :class="{ 'opacity-60 transition': loading }">
      <section :class="section" aria-labelledby="customers-heading" data-testid="insight-customers">
        <h2 id="customers-heading" :class="heading">Customers</h2>
        <p class="mt-1 text-sm text-muted">
          The people behind the orders paid in this period. Orders cancelled or refunded afterwards are left out.
        </p>
        <div class="mt-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
          <StatCard label="Buyers" :value="number(data.customers.buyers)" />
          <StatCard label="New buyers" :value="number(data.customers.new)" hint="Their first paid order" />
          <StatCard
            label="Returning buyers"
            :value="number(data.customers.returning)"
            :hint="`${percent(data.customers.returning_rate)} of buyers had bought before`"
          />
          <StatCard
            label="Orders per buyer"
            :value="data.customers.orders_per_buyer === null ? '—' : number(data.customers.orders_per_buyer)"
            :hint="`${times(data.customers.orders, 'order', 'orders')} paid`"
          />
        </div>
      </section>

      <section :class="section" aria-labelledby="checkout-heading" data-testid="insight-checkout">
        <h2 id="checkout-heading" :class="heading">From order to payment</h2>
        <p class="mt-1 text-sm text-muted">What became of the orders placed in this period.</p>
        <div class="mt-4 grid grid-cols-2 gap-3 lg:grid-cols-5">
          <StatCard label="Orders placed" :value="number(data.checkout.placed)" />
          <StatCard
            label="Paid"
            :value="number(data.checkout.paid)"
            :hint="`${percent(data.checkout.paid_rate)} of orders placed`"
          />
          <StatCard label="Waiting for payment" :value="number(data.checkout.awaiting_payment)" />
          <StatCard
            label="Cancelled unpaid"
            :value="number(data.checkout.cancelled_unpaid)"
            hint="By the customer, by staff, or out of time"
            :tone="data.checkout.cancelled_unpaid > data.checkout.paid ? 'warning' : 'normal'"
          />
          <StatCard
            label="Paid with a coupon"
            :value="number(data.checkout.with_coupon)"
            :hint="`${data.checkout.discount_given.formatted} taken off`"
          />
        </div>
      </section>

      <section :class="section" aria-labelledby="payments-heading" data-testid="insight-payments">
        <h2 id="payments-heading" :class="heading">Payments</h2>
        <p class="mt-1 text-sm text-muted">
          Each try at paying counts once, so an order paid on the second try is two attempts.
        </p>
        <div class="mt-4 grid grid-cols-2 gap-3 lg:grid-cols-5">
          <StatCard
            label="Success rate"
            :value="percent(data.payments.success_rate)"
            hint="Of the attempts that have finished"
          />
          <StatCard label="Attempts" :value="number(data.payments.attempts)" />
          <StatCard label="Paid" :value="number(data.payments.paid)" />
          <StatCard
            label="Failed"
            :value="number(data.payments.failed)"
            :tone="data.payments.failed > data.payments.paid ? 'danger' : 'normal'"
          />
          <StatCard
            label="Cancelled"
            :value="number(data.payments.cancelled)"
            :hint="`${number(data.payments.open)} not finished yet`"
          />
        </div>
        <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
          <div data-testid="failure-reasons">
            <h3 :class="subheading">Why payments failed</h3>
            <RankedBars :rows="failureReasons" empty="No payment failed in this period." />
          </div>
          <div data-testid="payment-methods">
            <h3 :class="subheading">How people paid</h3>
            <RankedBars :rows="methods" empty="No payment arrived in this period." />
          </div>
        </div>
      </section>

      <section :class="section" aria-labelledby="pcs-heading" data-testid="insight-pcs">
        <h2 id="pcs-heading" :class="heading">PCs from the builder</h2>
        <p class="mt-1 text-sm text-muted">
          Whole PCs against items sold on their own. Amounts are the items' prices, before coupons and shipping.
        </p>
        <div class="mt-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
          <StatCard label="PCs sold" :value="number(data.pcs.sold)" />
          <StatCard
            label="PC sales"
            :value="data.pcs.revenue.formatted"
            :hint="`${percent(data.pcs.revenue_share)} of everything sold`"
          />
          <StatCard label="Average PC" :value="data.pcs.average_price.formatted" />
          <StatCard
            label="Single items"
            :value="data.pcs.single_items.revenue.formatted"
            :hint="`${times(data.pcs.single_items.units, 'item', 'items')} sold on their own`"
          />
        </div>
        <div class="mt-6 grid grid-cols-1 gap-6 xl:grid-cols-2">
          <div class="min-w-0">
            <h3 :class="subheading">Most chosen parts</h3>
            <p v-if="!parts.length" class="text-sm text-muted">No PC was sold in this period.</p>
            <div v-else class="overflow-auto rounded-xl border border-line">
              <table class="admin-table min-w-[30rem]" data-testid="popular-parts">
                <thead>
                  <tr>
                    <th>Part</th>
                    <th>Product</th>
                    <th class="text-right">In PCs</th>
                    <th class="text-right">Share</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="part in parts" :key="`${part.slot}-${part.id}`">
                    <td class="whitespace-nowrap font-medium">
                      <span :class="{ 'sr-only': !part.first }">{{ part.slot_label }}</span>
                    </td>
                    <td>{{ part.name }}</td>
                    <td class="text-right tabular-nums">{{ number(part.pcs) }}</td>
                    <td class="text-right tabular-nums text-muted">{{ percent(part.share) }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
          <div data-testid="pairings">
            <h3 :class="subheading">Processor and graphics card, together</h3>
            <RankedBars :rows="pairings" empty="No PC was sold in this period." />
          </div>
        </div>
      </section>

      <section :class="section" aria-labelledby="suggestions-heading" data-testid="insight-suggestions">
        <h2 id="suggestions-heading" :class="heading">Build suggestions</h2>
        <p class="mt-1 text-sm text-muted">How “Suggest a build for me” in the PC builder was used.</p>
        <div class="mt-4 grid grid-cols-2 gap-3 lg:grid-cols-3">
          <StatCard label="Builds suggested" :value="number(data.suggestions.asked)" />
          <StatCard
            label="Loaded into the builder"
            :value="number(data.suggestions.accepted)"
            :hint="`${percent(data.suggestions.acceptance_rate)} of suggestions`"
          />
          <StatCard
            label="Average budget"
            :value="data.suggestions.asked ? data.suggestions.average_budget.formatted : '—'"
          />
        </div>
        <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
          <div data-testid="suggestion-purposes">
            <h3 :class="subheading">What the PC is for</h3>
            <RankedBars :rows="purposes" />
          </div>
          <div data-testid="suggestion-budgets">
            <h3 :class="subheading">What customers want to spend</h3>
            <RankedBars :rows="budgets" />
          </div>
        </div>
        <div class="mt-6 border-t border-line pt-5" data-testid="suggestion-ai">
          <h3 :class="subheading">The AI assistant</h3>
          <p v-if="!data.suggestions.ai.asked" class="text-sm text-muted">
            No suggestion in this period involved the AI assistant: the store's own rules chose every build.
          </p>
          <template v-else>
            <p class="text-sm">
              Asked for {{ times(data.suggestions.ai.asked, 'suggestion', 'suggestions') }}: its build was used
              {{ times(data.suggestions.ai.used, 'time', 'times') }}, and the store's rules took over
              {{ times(data.suggestions.ai.fell_back, 'time', 'times') }}.
            </p>
            <div v-if="aiReasons.length" class="mt-4 max-w-2xl">
              <h4 class="mb-3 text-xs font-semibold uppercase tracking-wide text-muted">Why the rules took over</h4>
              <RankedBars :rows="aiReasons" />
            </div>
          </template>
        </div>
      </section>
    </div>
  </AdminPage>
</template>
