<script setup>
defineProps({
  inStock: { type: Boolean, required: true },
  lowStock: { type: Boolean, default: false },
})
</script>

<template>
  <span v-if="!inStock" class="inline-flex items-center gap-1.5 text-xs font-medium text-danger"
    ><span aria-hidden="true">✗</span> Out of stock</span
  >
  <span v-else-if="lowStock" class="inline-flex items-center gap-1.5 text-xs font-medium text-warning"
    ><span aria-hidden="true">⚠</span> Low stock</span
  >
  <span v-else class="inline-flex items-center gap-1.5 text-xs font-medium text-success"
    ><span aria-hidden="true">✓</span> In stock</span
  >
</template>
