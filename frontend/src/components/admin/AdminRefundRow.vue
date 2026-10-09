<script setup>
import { RouterLink } from 'vue-router'
import RefundActions from '@/components/admin/RefundActions.vue'
import { useAuthStore } from '@/stores/auth'
import { whenFull } from '@/lib/format'

/** One refund as staff see it: the customer's words, ours, the technical failure if any, and the buttons for admins. */
defineProps({ refund: { type: Object, required: true }, showOrder: { type: Boolean, default: false } })
defineEmits(['changed'])

const auth = useAuthStore()
const tone = (value) =>
  ({ SUCCEEDED: 'text-success', REJECTED: 'text-muted', FAILED: 'text-danger' })[value] ?? 'text-warning'
</script>

<template>
  <article class="rounded-xl border border-line p-4 text-sm" data-testid="admin-refund">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <p class="font-semibold tabular-nums">{{ refund.amount.formatted }}</p>
      <p class="text-xs font-medium" :class="tone(refund.status.value)">{{ refund.status.label }}</p>
    </div>
    <p v-if="showOrder && refund.order" class="mt-1">
      <RouterLink
        :to="{ name: 'admin-order', params: { id: refund.order.id } }"
        class="whitespace-nowrap font-medium text-accent hover:underline"
        >{{ refund.order.order_number }}</RouterLink
      >
      <span class="text-muted"> · {{ refund.order.customer.name }} ({{ refund.order.customer.email }})</span>
    </p>
    <p class="mt-1 text-xs text-muted">
      {{ refund.reason.label }} · asked {{ whenFull(refund.requested_at)
      }}<template v-if="refund.refunded_at"> · refunded {{ whenFull(refund.refunded_at) }}</template>
      <template v-if="refund.restock"> · goods go back on the shelf</template>
    </p>
    <p v-if="refund.note" class="mt-2">Customer: “{{ refund.note }}”</p>
    <p v-if="refund.review_note" class="mt-1">Our note: “{{ refund.review_note }}”</p>
    <p v-if="refund.failure_reason" class="mt-2 text-danger" data-testid="refund-failure">
      Payment company: {{ refund.failure_reason }}
    </p>
    <div v-if="auth.can('refunds.manage')" class="mt-3">
      <RefundActions :refund="refund" @changed="$emit('changed', $event)" />
    </div>
  </article>
</template>
