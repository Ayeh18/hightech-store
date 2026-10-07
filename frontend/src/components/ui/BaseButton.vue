<script setup>
import { computed } from 'vue'

const props = defineProps({
  variant: { type: String, default: 'primary', validator: (v) => ['primary', 'secondary', 'ghost'].includes(v) },
  type: { type: String, default: 'button' },
  loading: { type: Boolean, default: false },
  block: { type: Boolean, default: false },
  disabled: { type: Boolean, default: false },
})

const classes = computed(() => [
  'inline-flex items-center justify-center gap-2 rounded-xl px-5 py-2.5 text-sm font-semibold transition',
  'disabled:cursor-not-allowed disabled:opacity-60',
  props.block ? 'w-full' : '',
  {
    primary: 'bg-accent text-white shadow-glow hover:bg-accent-hover',
    secondary: 'border border-line bg-elevated text-ink hover:border-accent/60',
    ghost: 'text-muted hover:bg-elevated hover:text-ink',
  }[props.variant],
])
</script>

<template>
  <button :type="type" :class="classes" :disabled="disabled || loading" :aria-busy="loading">
    <svg v-if="loading" class="size-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
      <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" class="opacity-25" />
      <path d="M22 12a10 10 0 0 0-10-10" stroke="currentColor" stroke-width="3" stroke-linecap="round" />
    </svg>
    <slot />
  </button>
</template>
