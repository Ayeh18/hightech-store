<script setup>
import { computed } from 'vue'
import { sortOptionsFor } from '@/lib/catalogQuery'

const props = defineProps({
  modelValue: { type: String, required: true },
  searching: { type: Boolean, default: false },
})
const emit = defineEmits(['update:modelValue'])

const options = computed(() => sortOptionsFor(props.searching))

// v-model on <select> (not a plain :value) re-selects the right option after the option list changes,
// which happens when "Most relevant" appears together with the search text.
const selected = computed({
  get: () => props.modelValue,
  set: (value) => emit('update:modelValue', value),
})
</script>

<template>
  <label class="flex items-center gap-2 text-sm text-muted">
    <span class="hidden sm:inline">Sort by</span>
    <select
      v-model="selected"
      class="rounded-xl border border-line bg-surface px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none"
    >
      <option v-for="option in options" :key="option.value" :value="option.value">{{ option.label }}</option>
    </select>
  </label>
</template>
