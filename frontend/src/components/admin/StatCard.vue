<script setup>
import { RouterLink } from 'vue-router'

/** One number on the dashboard. With `to` the whole card is a link to where the detail lives. */
defineProps({
  label: { type: String, required: true },
  value: { type: String, required: true },
  hint: { type: String, default: '' },
  to: { type: [String, Object], default: null },
  tone: { type: String, default: 'normal', validator: (v) => ['normal', 'warning', 'danger'].includes(v) },
})
</script>

<template>
  <component
    :is="to ? RouterLink : 'div'"
    :to="to ?? undefined"
    class="block rounded-card border border-line bg-surface p-4"
    :class="to ? 'transition hover:border-accent/60' : ''"
    data-testid="stat-card"
  >
    <p class="text-xs font-medium uppercase tracking-wide text-muted">{{ label }}</p>
    <p
      class="mt-2 font-display text-2xl font-semibold tabular-nums"
      :class="{ 'text-warning': tone === 'warning', 'text-danger': tone === 'danger' }"
      data-testid="stat-value"
    >
      {{ value }}
    </p>
    <p v-if="hint" class="mt-1 text-xs text-muted">{{ hint }}</p>
  </component>
</template>
