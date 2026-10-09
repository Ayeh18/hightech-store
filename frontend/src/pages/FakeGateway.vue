<script setup>
import { onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { api, parseApiError } from '@/lib/api'
import { parseRinggit } from '@/lib/money'
import { redirectTo } from '@/lib/redirect'

/*
 * "FakePay": a pretend payment company, for local development only.
 *
 * A real gateway (Billplz, Stripe, ...) shows its own payment page on its own website. This page plays that part: it
 * shows what is being paid and lets you choose how the payment ends. Your choice goes to the "company" (the API), which
 * then tells the shop through a signed webhook a moment later, exactly like a real one would. No real money moves.
 */
const route = useRoute()

const payment = ref(null)
const loading = ref(true)
const notFound = ref(false)
const error = ref('')
const working = ref('') // the outcome being sent
const actionError = ref('')
const otherAmount = ref('')

async function load() {
  loading.value = true
  notFound.value = false
  error.value = ''
  try {
    const { data } = await api.get(`/api/fake-gateway/payments/${route.params.reference}`)
    payment.value = data.data
  } catch (e) {
    if (e.response?.status === 404) notFound.value = true
    else error.value = parseApiError(e).message
  } finally {
    loading.value = false
  }
}

onMounted(load)

// An optional amount, in sen: pay something other than what was asked (a test aid for the shop's reconciliation page).
async function choose(outcome, amount) {
  working.value = outcome
  actionError.value = ''
  try {
    const { data } = await api.post(
      `/api/fake-gateway/payments/${route.params.reference}/complete`,
      amount === undefined ? { outcome } : { outcome, amount },
    )
    redirectTo(data.data.return_url) // back to the shop; the page unloads, so the buttons stay busy
  } catch (e) {
    const parsed = parseApiError(e)
    actionError.value = Object.values(parsed.errors).flat()[0] ?? parsed.message
    working.value = ''
  }
}

function payOther() {
  const sen = parseRinggit(otherAmount.value)

  if (!sen || sen < 1) {
    actionError.value = 'Enter an amount in ringgit, for example 25.50'
    return
  }
  choose('succeeded', sen)
}

const button =
  'w-full rounded-lg px-4 py-3 text-sm font-semibold transition focus-visible:outline-2 focus-visible:outline-offset-2 disabled:cursor-not-allowed disabled:opacity-60'
</script>

<template>
  <div class="overflow-hidden rounded-2xl bg-white shadow-xl">
    <header class="flex items-center justify-between bg-slate-900 px-6 py-4 text-white">
      <span class="text-lg font-bold tracking-tight">Fake<span class="text-emerald-400">Pay</span></span>
      <span class="rounded-full bg-amber-400 px-2.5 py-0.5 text-xs font-bold uppercase tracking-wide text-slate-900"
        >Test mode</span
      >
    </header>

    <div class="p-6">
      <div v-if="loading" class="animate-pulse space-y-3" aria-busy="true" aria-label="Loading the payment">
        <div class="h-5 w-1/2 rounded bg-slate-200" />
        <div class="h-10 w-2/3 rounded bg-slate-200" />
      </div>

      <div v-else-if="notFound" role="alert">
        <h1 class="text-lg font-semibold">This payment page doesn't exist</h1>
        <p class="mt-1 text-sm text-slate-600">The link may be wrong, or the test payment gateway is switched off.</p>
      </div>

      <div v-else-if="error" role="alert">
        <p class="text-sm text-red-700">{{ error }}</p>
        <button type="button" :class="[button, 'mt-4 bg-slate-900 text-white']" @click="load">Try again</button>
      </div>

      <template v-else-if="payment">
        <p class="text-sm text-slate-500">Pay HighTech Store</p>
        <p class="mt-1 text-3xl font-bold tabular-nums" data-testid="amount">{{ payment.amount.formatted }}</p>
        <p class="mt-1 text-sm text-slate-600">{{ payment.description }}</p>

        <div
          v-if="payment.status !== 'pending'"
          class="mt-6 rounded-lg bg-slate-100 p-4 text-sm"
          role="status"
          data-testid="already-done"
        >
          <p class="font-medium">This payment has already been completed.</p>
          <a :href="payment.return_url" class="mt-2 inline-block font-medium text-emerald-700 underline"
            >Back to the shop</a
          >
        </div>

        <div v-else class="mt-6 space-y-3">
          <p v-if="actionError" role="alert" class="rounded-lg bg-red-50 p-3 text-sm text-red-700">
            {{ actionError }}
          </p>

          <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Choose what happens</p>
          <button
            type="button"
            :class="[button, 'bg-emerald-600 text-white hover:bg-emerald-700 focus-visible:outline-emerald-600']"
            :disabled="working !== ''"
            @click="choose('succeeded')"
          >
            {{ working === 'succeeded' ? 'Paying…' : 'Pay successfully' }}
          </button>
          <button
            type="button"
            :class="[button, 'bg-red-600 text-white hover:bg-red-700 focus-visible:outline-red-600']"
            :disabled="working !== ''"
            @click="choose('failed')"
          >
            {{ working === 'failed' ? 'Declining…' : 'Simulate a declined payment' }}
          </button>
          <button
            type="button"
            :class="[button, 'bg-slate-200 text-slate-800 hover:bg-slate-300 focus-visible:outline-slate-500']"
            :disabled="working !== ''"
            @click="choose('cancelled')"
          >
            {{ working === 'cancelled' ? 'Cancelling…' : 'Cancel and go back' }}
          </button>

          <details class="rounded-lg border border-slate-200 p-3 text-sm" data-testid="different-amount">
            <summary class="cursor-pointer font-medium text-slate-700">Test: pay a different amount</summary>
            <p class="mt-2 text-xs text-slate-500">
              Real customers sometimes pay less or more than asked. Try it to see how the shop's reconciliation page
              catches it. The shop will not treat the order as paid.
            </p>
            <label for="other-amount" class="mt-2 block text-xs font-medium text-slate-700">Amount (RM)</label>
            <input
              id="other-amount"
              v-model="otherAmount"
              type="text"
              inputmode="decimal"
              class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900"
              placeholder="25.50"
            />
            <button
              type="button"
              :class="[button, 'mt-2 bg-slate-700 text-white hover:bg-slate-800 focus-visible:outline-slate-500']"
              :disabled="working !== '' || !otherAmount.trim()"
              @click="payOther"
            >
              Pay this amount
            </button>
          </details>

          <p class="pt-2 text-xs text-slate-500">
            This is a simulator for development. No real money is charged and no card is needed.
          </p>
        </div>
      </template>
    </div>
  </div>
</template>
