<script setup>
import { useId } from 'vue'

defineProps({
  modelValue: { type: Boolean, default: false },
  label: { type: String, required: true },
  hint: { type: String, default: '' },
})
defineEmits(['update:modelValue'])

const id = useId()
</script>

<template>
  <div class="flex items-start gap-3">
    <input
      :id="id"
      type="checkbox"
      :checked="modelValue"
      class="mt-0.5 size-4 rounded border-line bg-bg accent-accent"
      @change="$emit('update:modelValue', $event.target.checked)"
    />
    <label :for="id" class="text-sm">
      <span class="font-medium text-ink">{{ label }}</span>
      <span v-if="hint" class="block text-xs text-muted">{{ hint }}</span>
    </label>
  </div>
</template>
