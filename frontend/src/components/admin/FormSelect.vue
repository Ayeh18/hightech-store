<script setup>
import { computed, useId } from 'vue'

/** A labelled drop-down. `options` are [{ value, label }]. */
defineProps({
  modelValue: { type: [String, Number], default: '' },
  label: { type: String, required: true },
  options: { type: Array, required: true },
  error: { type: String, default: '' },
  placeholder: { type: String, default: '' }, // shows an empty first choice, e.g. "All statuses"
  disabled: { type: Boolean, default: false },
})
defineEmits(['update:modelValue'])

const id = useId()
const errorId = computed(() => `${id}-error`)
</script>

<template>
  <div>
    <label :for="id" class="mb-1.5 block text-sm font-medium text-ink">{{ label }}</label>
    <select
      :id="id"
      :value="modelValue"
      :disabled="disabled"
      :aria-invalid="error ? 'true' : undefined"
      :aria-describedby="error ? errorId : undefined"
      class="w-full rounded-xl border bg-bg px-3 py-2.5 text-sm text-ink transition focus:border-accent focus:outline-none disabled:cursor-not-allowed disabled:opacity-60"
      :class="error ? 'border-danger' : 'border-line'"
      @change="$emit('update:modelValue', $event.target.value)"
    >
      <option v-if="placeholder" value="">{{ placeholder }}</option>
      <option v-for="option in options" :key="option.value" :value="option.value">{{ option.label }}</option>
    </select>
    <p v-if="error" :id="errorId" class="mt-1.5 text-xs text-danger" role="alert">{{ error }}</p>
  </div>
</template>
