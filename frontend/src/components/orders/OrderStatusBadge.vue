<script setup>
import { computed } from 'vue'

/** Shows the order status and the payment status side by side: they are separate things. `status`: { value, label }. */
const props = defineProps({
  status: { type: Object, required: true },
  kind: { type: String, default: 'order', validator: (v) => ['order', 'payment'].includes(v) },
})

const tone = computed(() => {
  const value = props.status.value
  // "Processing" means progress for an order (being prepared) but "still in flight" for a payment.
  const good = ['PAID', 'PACKED', 'SHIPPED', 'DELIVERED', ...(props.kind === 'order' ? ['PROCESSING'] : [])]
  const bad = ['CANCELLED', 'FAILED']
  if (good.includes(value)) return 'bg-success/15 text-success'
  if (bad.includes(value)) return 'bg-danger/15 text-danger'
  if (value === 'REFUNDED') return 'bg-line text-muted' // finished, and not good or bad news
  return 'bg-warning/15 text-warning'
})
</script>

<template>
  <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium" :class="tone">
    <span class="sr-only">{{ kind === 'payment' ? 'Payment: ' : 'Order: ' }}</span
    >{{ status.label }}
  </span>
</template>
