<script setup>
import { SORT_OPTIONS } from '@/lib/catalogQuery'

defineProps({ modelValue: { type: String, required: true } })
defineEmits(['update:modelValue'])
</script>

<template>
  <label class="flex items-center gap-2 text-sm text-muted">
    <span class="hidden sm:inline">Sort by</span>
    <select
      :value="modelValue"
      class="rounded-xl border border-line bg-surface px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none"
      @change="$emit('update:modelValue', $event.target.value)"
    >
      <option v-for="option in SORT_OPTIONS" :key="option.value" :value="option.value">{{ option.label }}</option>
    </select>
  </label>
</template>
