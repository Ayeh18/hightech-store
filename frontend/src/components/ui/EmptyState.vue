<script setup>
defineProps({
  title: { type: String, required: true },
  text: { type: String, default: '' },
})
</script>

<template>
  <div class="rounded-card border border-dashed border-line bg-surface/50 px-6 py-14 text-center">
    <p class="font-display text-lg font-semibold">{{ title }}</p>
    <p v-if="text" class="mx-auto mt-2 max-w-md text-sm text-muted">{{ text }}</p>
    <div class="mt-5 flex justify-center"><slot /></div>
  </div>
</template>
