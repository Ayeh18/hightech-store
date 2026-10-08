<script setup>
const props = defineProps({
  modelValue: { type: Number, required: true },
  min: { type: Number, default: 1 },
  max: { type: Number, default: 99 },
  disabled: { type: Boolean, default: false },
  label: { type: String, default: 'Quantity' },
})
const emit = defineEmits(['update:modelValue'])

const step = (delta) => {
  const next = Math.min(props.max, Math.max(props.min, props.modelValue + delta))
  if (next !== props.modelValue) emit('update:modelValue', next)
}
</script>

<template>
  <div class="inline-flex items-center rounded-lg border border-line" role="group" :aria-label="label">
    <button
      type="button"
      class="px-3 py-1.5 text-muted transition enabled:hover:text-ink disabled:opacity-30"
      :disabled="disabled || modelValue <= min"
      :aria-label="`Decrease ${label.toLowerCase()}`"
      @click="step(-1)"
    >
      −
    </button>
    <span class="min-w-8 text-center text-sm tabular-nums" aria-live="polite">{{ modelValue }}</span>
    <button
      type="button"
      class="px-3 py-1.5 text-muted transition enabled:hover:text-ink disabled:opacity-30"
      :disabled="disabled || modelValue >= max"
      :aria-label="`Increase ${label.toLowerCase()}`"
      @click="step(1)"
    >
      +
    </button>
  </div>
</template>
