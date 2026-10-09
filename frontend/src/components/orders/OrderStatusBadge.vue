<script setup>
import { computed } from 'vue'

/** Shows the order status and the payment status side by side: they are separate things. `status`: { value, label }. */
const props = defineProps({
  status: { type: Object, required: true },
  kind: { type: String, default: 'order', validator: (v) => ['order', 'payment'].includes(v) },
})

const tone = computed(() => {
  const good = ['PAID', 'PROCESSING', 'PACKED', 'SHIPPED', 'DELIVERED']
  const bad = ['CANCELLED', 'FAILED', 'REFUNDED', 'REFUND_REQUESTED']
  if (good.includes(props.status.value)) return 'bg-success/15 text-success'
  if (bad.includes(props.status.value)) return 'bg-danger/15 text-danger'
  return 'bg-warning/15 text-warning'
})
</script>

<template>
  <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium" :class="tone">
    <span class="sr-only">{{ kind === 'payment' ? 'Payment: ' : 'Order: ' }}</span
    >{{ status.label }}
  </span>
</template>
