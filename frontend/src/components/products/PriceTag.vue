<script setup>
import { computed } from 'vue'

// `price` and `effective` are Money objects from the API: { amount (sen), formatted: "RM 4,602.00" }.
const props = defineProps({
  price: { type: Object, required: true },
  effective: { type: Object, required: true },
  size: { type: String, default: 'md', validator: (v) => ['md', 'lg'].includes(v) },
})

const onSale = computed(() => props.effective.amount < props.price.amount)
// Integer maths on sen, so the percentage never comes from a float price.
const percentOff = computed(() =>
  Math.round(((props.price.amount - props.effective.amount) * 100) / props.price.amount),
)
</script>

<template>
  <div class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
    <span class="font-display font-bold tracking-tight" :class="size === 'lg' ? 'text-3xl' : 'text-lg'">{{
      effective.formatted
    }}</span>
    <template v-if="onSale">
      <s class="text-sm text-muted">{{ price.formatted }}</s>
      <span class="rounded-md bg-success/15 px-1.5 py-0.5 text-xs font-semibold text-success">-{{ percentOff }}%</span>
    </template>
  </div>
</template>
