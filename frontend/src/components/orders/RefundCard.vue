<script setup>
/** One refund on an order: what was asked, what we said, and how much came back. `refund` comes from the server. */
defineProps({ refund: { type: Object, required: true } })

const when = (iso) => new Intl.DateTimeFormat('en-MY', { dateStyle: 'medium' }).format(new Date(iso))

// Customers are told "refunding" for anything still in progress; the technical reason a refund failed is staff's business.
const label = (refund) => (refund.status.value === 'FAILED' ? 'Refunding' : refund.status.label)
const tone = (value) => ({ SUCCEEDED: 'text-success', REJECTED: 'text-danger' })[value] ?? 'text-warning'
</script>

<template>
  <div class="rounded-xl border border-line p-3 text-sm" data-testid="refund-card">
    <div class="flex items-center justify-between gap-3">
      <span class="font-medium tabular-nums">{{ refund.amount.formatted }}</span>
      <span class="text-xs font-medium" :class="tone(refund.status.value)">{{ label(refund) }}</span>
    </div>
    <p class="mt-1 text-xs text-muted">
      {{ refund.reason.label }} · asked {{ when(refund.requested_at) }}
      <template v-if="refund.refunded_at"> · refunded {{ when(refund.refunded_at) }}</template>
    </p>
    <p v-if="refund.note" class="mt-2 text-xs text-muted">You said: “{{ refund.note }}”</p>
    <p v-if="refund.review_note" class="mt-1 text-xs">We said: “{{ refund.review_note }}”</p>
  </div>
</template>
