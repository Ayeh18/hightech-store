<script setup>
defineProps({
  type: { type: String, default: 'error', validator: (v) => ['error', 'warning', 'success'].includes(v) },
})
</script>

<template>
  <div
    role="alert"
    class="flex items-start gap-3 rounded-xl border px-4 py-3 text-sm"
    :class="{
      'border-danger/40 bg-danger/10 text-danger': type === 'error',
      'border-warning/40 bg-warning/10 text-warning': type === 'warning',
      'border-success/40 bg-success/10 text-success': type === 'success',
    }"
  >
    <span aria-hidden="true" class="font-bold">{{ type === 'success' ? '✓' : type === 'warning' ? '⚠' : '✗' }}</span>
    <div><slot /></div>
  </div>
</template>
