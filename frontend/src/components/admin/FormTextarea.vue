<script setup>
import { computed, useId } from 'vue'

defineProps({
  modelValue: { type: String, default: '' },
  label: { type: String, required: true },
  rows: { type: Number, default: 3 },
  error: { type: String, default: '' },
  maxlength: { type: Number, default: 500 },
  placeholder: { type: String, default: '' },
})
defineEmits(['update:modelValue'])

const id = useId()
const errorId = computed(() => `${id}-error`)
</script>

<template>
  <div>
    <label :for="id" class="mb-1.5 block text-sm font-medium text-ink">{{ label }}</label>
    <textarea
      :id="id"
      :value="modelValue"
      :rows="rows"
      :maxlength="maxlength"
      :placeholder="placeholder"
      :aria-invalid="error ? 'true' : undefined"
      :aria-describedby="error ? errorId : undefined"
      class="w-full rounded-xl border bg-bg px-4 py-2.5 text-sm text-ink placeholder:text-muted/70 transition focus:border-accent focus:outline-none"
      :class="error ? 'border-danger' : 'border-line'"
      @input="$emit('update:modelValue', $event.target.value)"
    />
    <p v-if="error" :id="errorId" class="mt-1.5 text-xs text-danger" role="alert">{{ error }}</p>
  </div>
</template>
